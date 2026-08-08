const Order = require("../models/Order");
const Product = require("../models/Product");
const User = require("../models/User");
const Offer = require("../models/Offer");
const { createAndPushNotification } = require("../services/notificationService");
const { formatMoney } = require("../utils/money");
const {
  createPaypalOrder,
  capturePaypalOrder,
  extractApproveUrl,
  extractCaptureId,
  isPaypalConfigured,
  calcFees,
} = require("../services/paypalService");
const {
  pushTimeline,
  normalizeStatus,
  isBuyer,
  isSellerOnOrder,
  isShipperOnOrder,
  canViewOrder,
  releaseProducts,
  markProductsSold,
  applyFeeFields,
  sanitizeAddress,
  sanitizeOrder,
  populateOrder,
  saveOrder,
  publishOrderUpdate,
  ensureOrderEtas,
} = require("../services/orderService");
const { streamOrderEvents } = require("../services/orderEvents");
const { validatePhone } = require("../utils/validators");
const { expireIfNeeded } = require("../services/offerService");

const FRONTEND_URL = process.env.FRONTEND_URL || "http://localhost:3000";
const PUBLIC_BASE_URL = process.env.PUBLIC_BASE_URL || "http://localhost:5001";
const BUYER_CONFIRM_HOURS = Number(process.env.BUYER_CONFIRM_HOURS || 48);

async function loadOrderForUser(id, user) {
  const order = await populateOrder(Order.findById(id));
  if (!order) return { error: { status: 404, message: "Order not found." } };
  if (!canViewOrder(order, user)) {
    return { error: { status: 403, message: "Forbidden." } };
  }
  return { order };
}

async function createOrder(req, res) {
  try {
    const offerId = req.body?.offerId || null;
    const note = String(req.body?.note || "").trim();
    const pickupStation = req.body?.pickupStation || null;
    const deliveryPhoneCheck = validatePhone(req.body?.deliveryAddress?.phone, {
      required: true,
      field: "Delivery phone",
    });
    if (!deliveryPhoneCheck.ok) {
      return res.status(400).json({ message: deliveryPhoneCheck.message });
    }
    const deliveryAddress = {
      ...sanitizeAddress(req.body?.deliveryAddress || {}),
      phone: deliveryPhoneCheck.phone,
    };
    const pickupAddress = sanitizeAddress(req.body?.pickupAddress || {});

    if (!deliveryAddress.line1 || !deliveryAddress.city) {
      return res.status(400).json({
        message: "Delivery address requires phone, line1, and city.",
      });
    }

    if (!deliveryAddress.fullName) {
      deliveryAddress.fullName = req.user.fullName || "";
    }

    let products = [];
    let items = [];
    let linkedOffer = null;
    let productIds = [];

    if (offerId) {
      linkedOffer = await Offer.findById(offerId);
      if (!linkedOffer) {
        return res.status(404).json({ message: "Offer not found." });
      }
      await expireIfNeeded(linkedOffer);

      if (String(linkedOffer.buyer) !== String(req.user._id)) {
        return res.status(403).json({ message: "This offer does not belong to you." });
      }
      if (linkedOffer.status !== "accepted") {
        return res.status(400).json({
          message: "Only an accepted offer can be checked out.",
        });
      }
      if (linkedOffer.order) {
        return res.status(400).json({
          message: "This offer already has an order.",
        });
      }

      const product = await Product.findById(linkedOffer.product).populate(
        "seller",
        "fullName email phone pickupAddress",
      );
      if (
        !product ||
        product.moderationStatus !== "approved" ||
        product.status !== "active"
      ) {
        return res.status(400).json({
          message:
            "Product is unavailable for this offer (sold, reserved by another buyer, or inactive).",
        });
      }

      products = [product];
      productIds = [String(product._id)];
      items = [
        {
          product: product._id,
          title: product.title,
          price: linkedOffer.offerPrice,
          image: Array.isArray(product.images) ? product.images[0] || "" : "",
          seller: product.seller._id || product.seller,
        },
      ];
    } else {
      productIds = Array.isArray(req.body?.productIds)
        ? [...new Set(req.body.productIds.map(String))]
        : [];

      if (productIds.length === 0) {
        return res.status(400).json({ message: "Select at least one product." });
      }

      products = await Product.find({
        _id: { $in: productIds },
        moderationStatus: "approved",
        status: "active",
      }).populate("seller", "fullName email phone pickupAddress");

      if (products.length !== productIds.length) {
        return res.status(400).json({
          message:
            "One or more products are unavailable (not approved, sold, or missing).",
        });
      }

      for (const product of products) {
        if (String(product.seller._id || product.seller) === String(req.user._id)) {
          return res.status(400).json({
            message: "You cannot buy your own listing.",
          });
        }
      }

      items = products.map((product) => ({
        product: product._id,
        title: product.title,
        price: product.price,
        image: Array.isArray(product.images) ? product.images[0] || "" : "",
        seller: product.seller._id || product.seller,
      }));
    }

    const totalAmount = items.reduce((sum, item) => sum + Number(item.price || 0), 0);
    const fees = calcFees(totalAmount);

    let resolvedPickup = pickupAddress;
    if (!resolvedPickup.line1) {
      const seller = products[0]?.seller;
      const fromSeller = seller?.pickupAddress || {};
      resolvedPickup = sanitizeAddress({
        fullName: fromSeller.fullName || seller?.fullName || "",
        phone: fromSeller.phone || seller?.phone || "",
        line1: fromSeller.line1 || "",
        line2: fromSeller.line2 || "",
        city: fromSeller.city || "",
        district: fromSeller.district || "",
        note: fromSeller.note || "",
      });
    }

    const timelineNote = linkedOffer
      ? `Order from accepted −${linkedOffer.discountPercent}% offer (${formatMoney(linkedOffer.offerPrice)}). Awaiting PayPal payment.`
      : "Order created. Awaiting PayPal payment.";

    const order = await Order.create({
      buyer: req.user._id,
      items,
      totalAmount,
      platformFee: fees.platformFee,
      sellerPayout: fees.sellerPayout,
      currency: "USD",
      note,
      offer: linkedOffer?._id || null,
      pickupStation: pickupStation || null,
      deliveryAddress,
      pickupAddress: resolvedPickup,
      status: "pending_payment",
      paymentStatus: "unpaid",
      escrowStatus: "none",
      timeline: [
        {
          status: "pending_payment",
          note: timelineNote,
          at: new Date(),
          by: req.user._id,
        },
      ],
    });

    publishOrderUpdate(order);

    await Product.updateMany(
      { _id: { $in: productIds } },
      { $set: { status: "reserved" } },
    );

    // First checkout wins: close competing offers on these products.
    await Offer.updateMany(
      {
        product: { $in: productIds },
        ...(linkedOffer ? { _id: { $ne: linkedOffer._id } } : {}),
        status: { $in: ["pending", "accepted"] },
        order: null,
      },
      {
        $set: {
          status: "cancelled",
          cancelledAt: new Date(),
          message: "Cancelled because the item was reserved by another checkout.",
        },
      },
    );

    if (linkedOffer) {
      linkedOffer.order = order._id;
      await linkedOffer.save();
    }

    const sellerIds = [...new Set(items.map((item) => String(item.seller)))];

    await Promise.all(
      sellerIds.map((sellerId) =>
        createAndPushNotification({
          userId: sellerId,
          title: "New order received",
          body: `${req.user.fullName || "A buyer"} ordered your item(s). Total: ${formatMoney(totalAmount)}. Waiting for payment.`,
          type: "offer",
          link: `/orders/${order._id}`,
          data: { orderId: String(order._id), type: "order" },
        }).catch(() => {}),
      ),
    );

    createAndPushNotification({
      userId: req.user._id,
      title: "Order placed",
      body: `Pay ${formatMoney(totalAmount)} via PayPal to confirm your order.`,
      type: "system",
      link: `/orders/${order._id}`,
      data: { orderId: String(order._id) },
    }).catch(() => {});

    const populated = await populateOrder(Order.findById(order._id));

    return res.status(201).json({
      message: "Order created successfully. Complete PayPal payment to continue.",
      order: sanitizeOrder(populated),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to create order.",
      error: error.message,
    });
  }
}

async function getMyOrders(req, res) {
  try {
    const orders = await populateOrder(
      Order.find({ buyer: req.user._id }).sort({ createdAt: -1 }),
    );
    return res.status(200).json({ orders: orders.map(sanitizeOrder) });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to fetch orders.",
      error: error.message,
    });
  }
}

async function getSellingOrders(req, res) {
  try {
    const orders = await populateOrder(
      Order.find({ "items.seller": req.user._id }).sort({ createdAt: -1 }),
    );
    return res.status(200).json({ orders: orders.map(sanitizeOrder) });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to fetch selling orders.",
      error: error.message,
    });
  }
}

async function getOrderById(req, res) {
  try {
    const { order, error } = await loadOrderForUser(req.params.id, req.user);
    if (error) return res.status(error.status).json({ message: error.message });
    return res.status(200).json({ order: sanitizeOrder(order) });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to fetch order.",
      error: error.message,
    });
  }
}

async function startPaypalPayment(req, res) {
  try {
    const order = await Order.findById(req.params.id);
    if (!order) return res.status(404).json({ message: "Order not found." });
    if (!isBuyer(order, req.user._id)) {
      return res.status(403).json({ message: "Only the buyer can pay." });
    }

    const status = normalizeStatus(order.status);
    if (!["pending_payment", "pending"].includes(status)) {
      return res.status(400).json({
        message: `Order cannot be paid in status "${status}".`,
      });
    }

    if (!isPaypalConfigured()) {
      return res.status(503).json({
        message:
          "PayPal sandbox is not configured. Add PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET to .env.",
      });
    }

    applyFeeFields(order);

    const paypalOrder = await createPaypalOrder({
      orderId: order._id,
      totalAmount: order.totalAmount,
      currency: order.currency || "USD",
      description: `ReBox order ${order._id}`,
      returnUrl: `${PUBLIC_BASE_URL}/api/payments/paypal/return?orderId=${order._id}`,
      cancelUrl: `${PUBLIC_BASE_URL}/api/payments/paypal/cancel?orderId=${order._id}`,
    });

    const approveUrl = extractApproveUrl(paypalOrder);
    if (!approveUrl) {
      return res.status(502).json({ message: "PayPal did not return an approve URL." });
    }

    order.paypalOrderId = paypalOrder.id;
    order.paymentStatus = "pending";
    pushTimeline(order, "pending_payment", "PayPal checkout started.", req.user._id);
    await saveOrder(order);

    return res.status(200).json({
      message: "PayPal order created.",
      approveUrl,
      paypalOrderId: paypalOrder.id,
      order: sanitizeOrder(order),
    });
  } catch (error) {
    return res.status(500).json({
      message: error.message || "Failed to start PayPal payment.",
      error: error.message,
      details: error.details || undefined,
    });
  }
}

async function markOrderPaidFromCapture(order, captureResult, actorId = null) {
  const captureId = extractCaptureId(captureResult);
  applyFeeFields(order);
  order.status = "paid";
  order.paymentStatus = "paid";
  order.escrowStatus = "held";
  order.paypalCaptureId = captureId || order.paypalCaptureId;
  order.paidAt = new Date();
  pushTimeline(
    order,
    "paid",
    `Payment captured via PayPal. Escrow held. Fee ${formatMoney(order.platformFee)}.`,
    actorId,
  );
  await saveOrder(order);

  const sellerIds = [...new Set(order.items.map((item) => String(item.seller)))];
  await Promise.all(
    sellerIds.map((sellerId) =>
      createAndPushNotification({
        userId: sellerId,
        title: "Order paid — confirm stock",
        body: `Buyer paid ${formatMoney(order.totalAmount)}. Please confirm you still have the item(s).`,
        type: "offer",
        link: `/orders/${order._id}`,
        data: { orderId: String(order._id), type: "order" },
      }).catch(() => {}),
    ),
  );

  createAndPushNotification({
    userId: order.buyer,
    title: "Payment successful",
    body: "Funds are held in escrow until delivery is confirmed.",
    type: "system",
    link: `/orders/${order._id}`,
    data: { orderId: String(order._id) },
  }).catch(() => {});
}

async function paypalReturn(req, res) {
  try {
    const orderId = req.query.orderId;
    const paypalToken = req.query.token; // PayPal order id
    if (!orderId) {
      return res.redirect(`${FRONTEND_URL}/orders?payment=missing`);
    }

    const order = await Order.findById(orderId);
    if (!order) {
      return res.redirect(`${FRONTEND_URL}/orders?payment=not_found`);
    }

    const status = normalizeStatus(order.status);
    if (status === "paid" || order.paymentStatus === "paid") {
      return res.redirect(`${FRONTEND_URL}/orders/${order._id}?payment=success`);
    }

    const paypalOrderId = paypalToken || order.paypalOrderId;
    if (!paypalOrderId) {
      return res.redirect(`${FRONTEND_URL}/orders/${order._id}?payment=failed`);
    }

    const captureResult = await capturePaypalOrder(paypalOrderId);
    order.paypalOrderId = paypalOrderId;
    await markOrderPaidFromCapture(order, captureResult, order.buyer);

    return res.redirect(`${FRONTEND_URL}/orders/${order._id}?payment=success`);
  } catch (error) {
    const orderId = req.query.orderId;
    console.error("PayPal return capture failed:", error.message);
    return res.redirect(
      `${FRONTEND_URL}/orders/${orderId || ""}?payment=failed&reason=${encodeURIComponent(error.message || "capture_failed")}`,
    );
  }
}

async function paypalCancel(req, res) {
  const orderId = req.query.orderId;
  return res.redirect(
    `${FRONTEND_URL}/orders/${orderId || ""}?payment=cancelled`,
  );
}

async function capturePaypalPayment(req, res) {
  try {
    const order = await Order.findById(req.params.id);
    if (!order) return res.status(404).json({ message: "Order not found." });
    if (!isBuyer(order, req.user._id) && req.user.role !== "admin") {
      return res.status(403).json({ message: "Forbidden." });
    }

    if (normalizeStatus(order.status) === "paid" || order.paymentStatus === "paid") {
      const populated = await populateOrder(Order.findById(order._id));
      return res.status(200).json({
        message: "Already paid.",
        order: sanitizeOrder(populated),
      });
    }

    const paypalOrderId = req.body?.paypalOrderId || order.paypalOrderId;
    if (!paypalOrderId) {
      return res.status(400).json({ message: "Missing PayPal order id." });
    }

    const captureResult = await capturePaypalOrder(paypalOrderId);
    order.paypalOrderId = paypalOrderId;
    await markOrderPaidFromCapture(order, captureResult, req.user._id);
    const populated = await populateOrder(Order.findById(order._id));

    return res.status(200).json({
      message: "Payment captured. Escrow held.",
      order: sanitizeOrder(populated),
    });
  } catch (error) {
    return res.status(500).json({
      message: error.message || "Failed to capture payment.",
      error: error.message,
    });
  }
}

async function sellerConfirm(req, res) {
  try {
    const order = await Order.findById(req.params.id);
    if (!order) return res.status(404).json({ message: "Order not found." });
    if (!isSellerOnOrder(order, req.user._id) && req.user.role !== "admin") {
      return res.status(403).json({ message: "Only the seller can confirm." });
    }

    if (normalizeStatus(order.status) !== "paid") {
      return res.status(400).json({
        message: "Order must be paid before seller confirmation.",
      });
    }

    // Optional pickup address update from seller
    if (req.body?.pickupAddress) {
      order.pickupAddress = sanitizeAddress(req.body.pickupAddress);
    } else if (!order.pickupAddress?.line1) {
      const seller = await User.findById(req.user._id);
      if (seller?.pickupAddress?.line1) {
        order.pickupAddress = sanitizeAddress({
          ...seller.pickupAddress,
          fullName: seller.pickupAddress.fullName || seller.fullName,
          phone: seller.pickupAddress.phone || seller.phone,
        });
      }
    }

    order.status = "seller_confirmed";
    pushTimeline(order, "seller_confirmed", "Seller confirmed stock.", req.user._id);
    await saveOrder(order);

    createAndPushNotification({
      userId: order.buyer,
      title: "Seller confirmed",
      body: "Your order was confirmed. A shipper will be assigned next.",
      type: "system",
      link: `/orders/${order._id}`,
      data: { orderId: String(order._id) },
    }).catch(() => {});

    const populated = await populateOrder(Order.findById(order._id));
    return res.status(200).json({
      message: "Order confirmed by seller.",
      order: sanitizeOrder(populated),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to confirm order.",
      error: error.message,
    });
  }
}

async function sellerReject(req, res) {
  try {
    const order = await Order.findById(req.params.id);
    if (!order) return res.status(404).json({ message: "Order not found." });
    if (!isSellerOnOrder(order, req.user._id) && req.user.role !== "admin") {
      return res.status(403).json({ message: "Only the seller can reject." });
    }

    const status = normalizeStatus(order.status);
    if (!["paid", "pending_payment", "pending"].includes(status)) {
      return res.status(400).json({
        message: `Cannot reject order in status "${status}".`,
      });
    }

    const reason = String(req.body?.reason || "Seller rejected the order.").trim();
    order.status = "cancelled";
    order.cancelledAt = new Date();
    order.cancelReason = reason;

    if (order.escrowStatus === "held" || order.paymentStatus === "paid") {
      order.escrowStatus = "refunded";
      order.paymentStatus = "refunded";
      pushTimeline(
        order,
        "cancelled",
        `${reason} Escrow marked refunded (demo — release manually in PayPal if needed).`,
        req.user._id,
      );
    } else {
      pushTimeline(order, "cancelled", reason, req.user._id);
    }

    await saveOrder(order);
    await releaseProducts(order, "active");

    createAndPushNotification({
      userId: order.buyer,
      title: "Order cancelled by seller",
      body: reason,
      type: "system",
      link: `/orders/${order._id}`,
      data: { orderId: String(order._id) },
    }).catch(() => {});

    const populated = await populateOrder(Order.findById(order._id));
    return res.status(200).json({
      message: "Order rejected and cancelled.",
      order: sanitizeOrder(populated),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to reject order.",
      error: error.message,
    });
  }
}

async function cancelOrder(req, res) {
  try {
    const order = await Order.findById(req.params.id);
    if (!order) return res.status(404).json({ message: "Order not found." });

    const buyer = isBuyer(order, req.user._id);
    const admin = req.user.role === "admin";
    if (!buyer && !admin) {
      return res.status(403).json({ message: "Forbidden." });
    }

    const status = normalizeStatus(order.status);
    const cancellable = ["pending_payment", "pending", "paid"].includes(status);
    if (!cancellable) {
      return res.status(400).json({
        message: `Cannot cancel after logistics started (status: ${status}).`,
      });
    }

    const reason = String(req.body?.reason || "Cancelled by buyer.").trim();
    order.status = "cancelled";
    order.cancelledAt = new Date();
    order.cancelReason = reason;

    if (order.escrowStatus === "held" || order.paymentStatus === "paid") {
      order.escrowStatus = "refunded";
      order.paymentStatus = "refunded";
    }

    pushTimeline(order, "cancelled", reason, req.user._id);
    await saveOrder(order);
    await releaseProducts(order, "active");

    const sellerIds = [...new Set(order.items.map((item) => String(item.seller)))];
    await Promise.all(
      sellerIds.map((sellerId) =>
        createAndPushNotification({
          userId: sellerId,
          title: "Order cancelled",
          body: reason,
          type: "offer",
          link: `/orders/${order._id}`,
          data: { orderId: String(order._id) },
        }).catch(() => {}),
      ),
    );

    const populated = await populateOrder(Order.findById(order._id));
    return res.status(200).json({
      message: "Order cancelled.",
      order: sanitizeOrder(populated),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to cancel order.",
      error: error.message,
    });
  }
}

async function assignShipper(req, res) {
  try {
    if (req.user.role !== "admin" && req.user.role !== "shipper") {
      // Allow shipper self-claim OR admin assign
    }

    const order = await Order.findById(req.params.id);
    if (!order) return res.status(404).json({ message: "Order not found." });

    const status = normalizeStatus(order.status);
    if (status !== "seller_confirmed" && status !== "pickup_assigned") {
      return res.status(400).json({
        message: "Order must be seller_confirmed before assigning a shipper.",
      });
    }

    let shipperId = req.body?.shipperId;
    if (!shipperId && req.user.role === "shipper") {
      shipperId = req.user._id;
    }

    if (!shipperId) {
      return res.status(400).json({ message: "shipperId is required." });
    }

    if (req.user.role !== "admin" && String(shipperId) !== String(req.user._id)) {
      return res.status(403).json({ message: "Forbidden." });
    }

    const shipper = await User.findById(shipperId);
    if (!shipper || shipper.role !== "shipper") {
      return res.status(400).json({ message: "Invalid shipper user." });
    }

    order.shipper = shipper._id;
    order.status = "pickup_assigned";
    order.assignedAt = new Date();

    const etaRaw = req.body?.estimatedDeliveryAt;
    const pickupEtaRaw = req.body?.estimatedPickupAt;
    let estimatedDeliveryAt = etaRaw ? new Date(etaRaw) : null;
    let estimatedPickupAt = pickupEtaRaw ? new Date(pickupEtaRaw) : null;
    if (!estimatedDeliveryAt || Number.isNaN(estimatedDeliveryAt.getTime())) {
      estimatedDeliveryAt = new Date(Date.now() + 4 * 60 * 60 * 1000);
    }
    if (!estimatedPickupAt || Number.isNaN(estimatedPickupAt.getTime())) {
      estimatedPickupAt = new Date(Date.now() + 2 * 60 * 60 * 1000);
    }
    order.estimatedDeliveryAt = estimatedDeliveryAt;
    order.estimatedPickupAt = estimatedPickupAt;
    ensureOrderEtas(order);

    pushTimeline(
      order,
      "pickup_assigned",
      `Shipper assigned: ${shipper.fullName}. ETA delivery ${estimatedDeliveryAt.toLocaleString()}.`,
      req.user._id,
    );
    await saveOrder(order);

    createAndPushNotification({
      userId: shipper._id,
      title: "New delivery job",
      body: `Pickup & deliver order ${order._id}`,
      type: "system",
      link: `/shipper`,
      data: { orderId: String(order._id) },
    }).catch(() => {});

    createAndPushNotification({
      userId: order.buyer,
      title: "Shipper assigned",
      body: `${shipper.fullName} will deliver by ${estimatedDeliveryAt.toLocaleString()}.`,
      type: "system",
      link: `/orders/${order._id}`,
      data: { orderId: String(order._id) },
    }).catch(() => {});

    const populated = await populateOrder(Order.findById(order._id));
    return res.status(200).json({
      message: "Shipper assigned.",
      order: sanitizeOrder(populated),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to assign shipper.",
      error: error.message,
    });
  }
}

async function shipperUpdateStatus(req, res) {
  try {
    const order = await Order.findById(req.params.id);
    if (!order) return res.status(404).json({ message: "Order not found." });

    const isAssignedShipper = isShipperOnOrder(order, req.user._id);
    if (!isAssignedShipper && req.user.role !== "admin") {
      return res.status(403).json({ message: "Forbidden." });
    }

    const next = String(req.body?.status || "").trim();
    const note = String(req.body?.note || "").trim();
    const current = normalizeStatus(order.status);

    const transitions = {
      pickup_assigned: ["picked_up"],
      picked_up: ["out_for_delivery"],
      out_for_delivery: ["delivered"],
    };

    if (!transitions[current]?.includes(next)) {
      return res.status(400).json({
        message: `Cannot move from "${current}" to "${next}".`,
      });
    }

    if (req.body?.estimatedDeliveryAt) {
      const eta = new Date(req.body.estimatedDeliveryAt);
      if (!Number.isNaN(eta.getTime())) {
        order.estimatedDeliveryAt = eta;
      }
    }
    if (req.body?.estimatedPickupAt) {
      const eta = new Date(req.body.estimatedPickupAt);
      if (!Number.isNaN(eta.getTime())) {
        order.estimatedPickupAt = eta;
      }
    }

    order.status = next;
    if (next === "picked_up") order.pickedUpAt = new Date();
    if (next === "delivered") {
      order.deliveredAt = new Date();
      order.autoCompleteAt = new Date(
        Date.now() + BUYER_CONFIRM_HOURS * 60 * 60 * 1000,
      );
    }
    ensureOrderEtas(order);

    const etaNote = order.estimatedDeliveryAt
      ? ` ETA ${new Date(order.estimatedDeliveryAt).toLocaleString()}.`
      : "";
    pushTimeline(
      order,
      next,
      note || `Status updated to ${next}.${etaNote}`,
      req.user._id,
    );
    await saveOrder(order);

    createAndPushNotification({
      userId: order.buyer,
      title: `Order ${next.replace(/_/g, " ")}`,
      body:
        note ||
        `Your order is now ${next.replace(/_/g, " ")}.${
          order.estimatedDeliveryAt && next !== "delivered"
            ? ` Expected by ${new Date(order.estimatedDeliveryAt).toLocaleString()}.`
            : ""
        }`,
      type: "system",
      link: `/orders/${order._id}`,
      data: { orderId: String(order._id) },
    }).catch(() => {});

    const populated = await populateOrder(Order.findById(order._id));
    return res.status(200).json({
      message: "Shipment status updated.",
      order: sanitizeOrder(populated),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to update shipment status.",
      error: error.message,
    });
  }
}

async function listShipperJobs(req, res) {
  try {
    if (req.user.role !== "shipper" && req.user.role !== "admin") {
      return res.status(403).json({ message: "Shipper role required." });
    }

    const available = await populateOrder(
      Order.find({
        status: "seller_confirmed",
        $or: [{ shipper: null }, { shipper: { $exists: false } }],
      }).sort({ updatedAt: -1 }),
    );

    const mine = await populateOrder(
      Order.find({
        shipper: req.user._id,
        status: {
          $in: [
            "pickup_assigned",
            "picked_up",
            "out_for_delivery",
            "delivered",
          ],
        },
      }).sort({ updatedAt: -1 }),
    );

    return res.status(200).json({
      available: available.map(sanitizeOrder),
      mine: mine.map(sanitizeOrder),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to list shipper jobs.",
      error: error.message,
    });
  }
}

async function buyerConfirm(req, res) {
  try {
    const order = await Order.findById(req.params.id);
    if (!order) return res.status(404).json({ message: "Order not found." });
    if (!isBuyer(order, req.user._id) && req.user.role !== "admin") {
      return res.status(403).json({ message: "Only the buyer can confirm." });
    }

    if (normalizeStatus(order.status) !== "delivered") {
      return res.status(400).json({
        message: "Order must be delivered before confirmation.",
      });
    }

    order.status = "completed";
    order.buyerConfirmedAt = new Date();
    order.completedAt = new Date();
    order.escrowStatus = "released";
    order.paymentStatus = "released";
    pushTimeline(
      order,
      "completed",
      `Buyer confirmed receipt. Escrow released to seller (${formatMoney(order.sellerPayout)}).`,
      req.user._id,
    );
    await saveOrder(order);
    await markProductsSold(order);

    const sellerIds = [...new Set(order.items.map((item) => String(item.seller)))];
    await Promise.all(
      sellerIds.map((sellerId) =>
        createAndPushNotification({
          userId: sellerId,
          title: "Escrow released",
          body: `Buyer confirmed. Payout ${formatMoney(order.sellerPayout)} (demo ledger).`,
          type: "offer",
          link: `/orders/${order._id}`,
          data: { orderId: String(order._id) },
        }).catch(() => {}),
      ),
    );

    const populated = await populateOrder(Order.findById(order._id));
    return res.status(200).json({
      message: "Order completed. Escrow released.",
      order: sanitizeOrder(populated),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to confirm order.",
      error: error.message,
    });
  }
}

async function openDispute(req, res) {
  try {
    const order = await Order.findById(req.params.id);
    if (!order) return res.status(404).json({ message: "Order not found." });
    if (!isBuyer(order, req.user._id) && req.user.role !== "admin") {
      return res.status(403).json({ message: "Only the buyer can dispute." });
    }

    const status = normalizeStatus(order.status);
    if (!["delivered", "out_for_delivery"].includes(status)) {
      return res.status(400).json({
        message: "Disputes can be opened after delivery starts.",
      });
    }

    const reason = String(req.body?.reason || "").trim();
    if (reason.length < 10) {
      return res.status(400).json({
        message: "Dispute reason must be at least 10 characters.",
      });
    }

    const evidence = Array.isArray(req.body?.evidence)
      ? req.body.evidence.map(String).slice(0, 8)
      : [];

    order.status = "disputed";
    order.dispute = {
      reason,
      evidence,
      createdAt: new Date(),
      createdBy: req.user._id,
      resolvedAt: null,
      resolution: "",
      resolutionStatus: "",
    };
    pushTimeline(order, "disputed", reason, req.user._id);
    await saveOrder(order);

    createAndPushNotification({
      userId: order.items[0]?.seller,
      title: "Order disputed",
      body: reason,
      type: "offer",
      link: `/orders/${order._id}`,
      data: { orderId: String(order._id) },
    }).catch(() => {});

    const populated = await populateOrder(Order.findById(order._id));
    return res.status(200).json({
      message: "Dispute opened. Admin will review.",
      order: sanitizeOrder(populated),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to open dispute.",
      error: error.message,
    });
  }
}

async function resolveDispute(req, res) {
  try {
    if (req.user.role !== "admin") {
      return res.status(403).json({ message: "Admin only." });
    }

    const order = await Order.findById(req.params.id);
    if (!order) return res.status(404).json({ message: "Order not found." });
    if (normalizeStatus(order.status) !== "disputed") {
      return res.status(400).json({ message: "Order is not disputed." });
    }

    const resolutionStatus = String(req.body?.resolutionStatus || "").trim();
    const resolution = String(req.body?.resolution || "").trim();

    if (!["completed", "cancelled", "refunded"].includes(resolutionStatus)) {
      return res.status(400).json({
        message: "resolutionStatus must be completed, cancelled, or refunded.",
      });
    }

    order.dispute = order.dispute || {};
    order.dispute.resolvedAt = new Date();
    order.dispute.resolution = resolution;
    order.dispute.resolutionStatus = resolutionStatus;

    if (resolutionStatus === "completed") {
      order.status = "completed";
      order.completedAt = new Date();
      order.escrowStatus = "released";
      order.paymentStatus = "released";
      await markProductsSold(order);
    } else {
      order.status = "cancelled";
      order.cancelledAt = new Date();
      order.cancelReason = resolution || "Dispute resolved with refund.";
      order.escrowStatus = "refunded";
      order.paymentStatus = "refunded";
      await releaseProducts(order, "active");
    }

    pushTimeline(
      order,
      order.status,
      `Dispute resolved: ${resolutionStatus}. ${resolution}`,
      req.user._id,
    );
    await saveOrder(order);

    const populated = await populateOrder(Order.findById(order._id));
    return res.status(200).json({
      message: "Dispute resolved.",
      order: sanitizeOrder(populated),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to resolve dispute.",
      error: error.message,
    });
  }
}

/** Auto-complete delivered orders past hold window (callable manually / cron). */
async function autoCompleteDelivered(_req, res) {
  try {
    const due = await Order.find({
      status: "delivered",
      autoCompleteAt: { $lte: new Date() },
    });

    let completed = 0;
    for (const order of due) {
      order.status = "completed";
      order.completedAt = new Date();
      order.buyerConfirmedAt = order.buyerConfirmedAt || new Date();
      order.escrowStatus = "released";
      order.paymentStatus = "released";
      pushTimeline(
        order,
        "completed",
        "Auto-completed after buyer confirm window.",
        null,
      );
      await saveOrder(order);
      await markProductsSold(order);
      completed += 1;
    }

    return res.status(200).json({ message: "Auto-complete finished.", completed });
  } catch (error) {
    return res.status(500).json({
      message: "Auto-complete failed.",
      error: error.message,
    });
  }
}

module.exports = {
  createOrder,
  getMyOrders,
  getSellingOrders,
  getOrderById,
  startPaypalPayment,
  paypalReturn,
  paypalCancel,
  capturePaypalPayment,
  sellerConfirm,
  sellerReject,
  cancelOrder,
  assignShipper,
  shipperUpdateStatus,
  listShipperJobs,
  buyerConfirm,
  openDispute,
  resolveDispute,
  autoCompleteDelivered,
  streamOrderEvents,
};
