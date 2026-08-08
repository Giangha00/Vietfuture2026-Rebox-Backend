/**
 * One-off fixer for local demo DB:
 * - Normalize every user phone to exactly 10 digits
 * - Backfill delivery/pickup addresses for users missing them
 * - Backfill empty order delivery/pickup addresses
 * - Recompute platformFee/sellerPayout when missing
 * - Reset sold/reserved products not locked by an open order back to active
 *
 * Usage: node src/scripts/fix-demo-data.js
 */
require("dotenv").config();
const mongoose = require("mongoose");
const User = require("../models/User");
const Order = require("../models/Order");
const Product = require("../models/Product");
const { calcFees } = require("../services/paypalService");
const { normalizePhone } = require("../utils/validators");

function padPhone(index) {
  return `09${String(10000000 + (index % 90000000)).slice(-8)}`;
}

function sampleDelivery(user, index) {
  return {
    fullName: user.fullName || "ReBox User",
    phone: normalizePhone(user.phone) || padPhone(index),
    line1: `${10 + (index % 80)} Nguyen Hue`,
    line2: `Apt ${index + 1}`,
    city: index % 2 === 0 ? "Ho Chi Minh" : "Ha Noi",
    district: index % 2 === 0 ? "District 1" : "Cau Giay",
    note: "Call on arrival",
  };
}

function samplePickup(user, index) {
  return {
    fullName: user.fullName || "ReBox Seller",
    phone: normalizePhone(user.phone) || padPhone(index + 50),
    line1: `${20 + (index % 70)} Le Loi`,
    line2: "",
    city: index % 2 === 0 ? "Ho Chi Minh" : "Ha Noi",
    district: index % 2 === 0 ? "District 3" : "Dong Da",
    note: "Pickup at lobby",
  };
}

function isBlankAddress(address) {
  if (!address || typeof address !== "object") return true;
  return !String(address.line1 || "").trim() && !String(address.city || "").trim();
}

async function main() {
  const uri = process.env.MONGODB_URI || "mongodb://127.0.0.1:27017/rebox_db";
  await mongoose.connect(uri);
  console.log("Connected:", uri);

  const users = await User.find().sort({ createdAt: 1 });
  let phoneFixed = 0;
  let addressFixed = 0;

  for (let i = 0; i < users.length; i += 1) {
    const user = users[i];
    let dirty = false;

    let phone = normalizePhone(user.phone);
    if (!/^\d{10}$/.test(phone)) {
      phone = user.role === "admin" ? "0900000000" : padPhone(i + 1);
      user.phone = phone;
      phoneFixed += 1;
      dirty = true;
    } else if (user.phone !== phone) {
      user.phone = phone;
      dirty = true;
      phoneFixed += 1;
    }

    if (isBlankAddress(user.deliveryAddress)) {
      user.deliveryAddress = sampleDelivery(user, i);
      addressFixed += 1;
      dirty = true;
    } else {
      const dPhone = normalizePhone(user.deliveryAddress.phone);
      if (!/^\d{10}$/.test(dPhone)) {
        user.deliveryAddress.phone = phone;
        dirty = true;
      }
    }

    if (isBlankAddress(user.pickupAddress)) {
      user.pickupAddress = samplePickup(user, i);
      addressFixed += 1;
      dirty = true;
    } else {
      const pPhone = normalizePhone(user.pickupAddress.phone);
      if (!/^\d{10}$/.test(pPhone)) {
        user.pickupAddress.phone = phone;
        dirty = true;
      }
    }

    if (dirty) {
      // Avoid password re-hash / strength validation on legacy docs
      await User.collection.updateOne(
        { _id: user._id },
        {
          $set: {
            phone: user.phone,
            deliveryAddress: user.deliveryAddress,
            pickupAddress: user.pickupAddress,
          },
        },
      );
    }
  }

  const orders = await Order.find();
  let orderFixed = 0;
  for (let i = 0; i < orders.length; i += 1) {
    const order = orders[i];
    const buyer = await User.findById(order.buyer);
    const sellerId = order.items?.[0]?.seller;
    const seller = sellerId ? await User.findById(sellerId) : null;
    const patch = {};

    if (isBlankAddress(order.deliveryAddress)) {
      patch.deliveryAddress = buyer
        ? sampleDelivery(buyer, i)
        : sampleDelivery({ fullName: "Buyer", phone: "0901234567" }, i);
    } else if (!/^\d{10}$/.test(normalizePhone(order.deliveryAddress.phone))) {
      patch.deliveryAddress = {
        ...order.deliveryAddress.toObject?.() || order.deliveryAddress,
        phone: normalizePhone(buyer?.phone) || "0901234567",
      };
    }

    if (isBlankAddress(order.pickupAddress)) {
      patch.pickupAddress = seller
        ? samplePickup(seller, i + 3)
        : samplePickup({ fullName: "Seller", phone: "0907654321" }, i + 3);
    } else if (!/^\d{10}$/.test(normalizePhone(order.pickupAddress.phone))) {
      patch.pickupAddress = {
        ...order.pickupAddress.toObject?.() || order.pickupAddress,
        phone: normalizePhone(seller?.phone) || "0907654321",
      };
    }

    if (!order.platformFee && order.totalAmount) {
      const fees = calcFees(order.totalAmount);
      patch.platformFee = fees.platformFee;
      patch.sellerPayout = fees.sellerPayout;
    }

    if (!Array.isArray(order.timeline) || order.timeline.length === 0) {
      patch.timeline = [
        {
          status: order.status || "pending_payment",
          note: "Backfilled timeline for demo order.",
          at: order.createdAt || new Date(),
          by: order.buyer || null,
        },
      ];
    }

    if (Object.keys(patch).length > 0) {
      await Order.collection.updateOne({ _id: order._id }, { $set: patch });
      orderFixed += 1;
    }
  }

  // Seed often marks products sold/reserved without a real order — unlock for checkout tests.
  const lockedProductIds = new Set();
  const openOrders = await Order.find({
    status: { $nin: ["cancelled"] },
  }).select("items.product");
  for (const order of openOrders) {
    for (const item of order.items || []) {
      if (item.product) lockedProductIds.add(String(item.product));
    }
  }

  const orphaned = await Product.find({
    status: { $in: ["sold", "reserved"] },
  }).select("_id status");
  let productsUnlocked = 0;
  for (const product of orphaned) {
    if (lockedProductIds.has(String(product._id))) continue;
    await Product.collection.updateOne(
      { _id: product._id },
      { $set: { status: "active" } },
    );
    productsUnlocked += 1;
  }

  const buyable = await Product.countDocuments({
    status: "active",
    moderationStatus: "approved",
  });

  console.log(
    JSON.stringify(
      {
        users: users.length,
        phoneFixed,
        userAddressesFixed: addressFixed,
        orders: orders.length,
        ordersFixed: orderFixed,
        productsUnlocked,
        buyableActiveApproved: buyable,
      },
      null,
      2,
    ),
  );

  await mongoose.disconnect();
}

main().catch(async (error) => {
  console.error(error);
  try {
    await mongoose.disconnect();
  } catch {
    // ignore
  }
  process.exit(1);
});
