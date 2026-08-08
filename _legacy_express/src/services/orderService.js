const Product = require("../models/Product");
const { calcFees } = require("./paypalService");
const { publishOrderUpdate } = require("./orderEvents");

function pushTimeline(order, status, note = "", by = null) {
  if (!Array.isArray(order.timeline)) order.timeline = [];
  order.timeline.push({
    status,
    note,
    at: new Date(),
    by: by || null,
  });
}

function normalizeStatus(status) {
  if (status === "pending") return "pending_payment";
  if (status === "confirmed") return "paid";
  return status;
}

function isBuyer(order, userId) {
  return String(order.buyer?._id || order.buyer) === String(userId);
}

function isSellerOnOrder(order, userId) {
  return (order.items || []).some(
    (item) => String(item.seller?._id || item.seller) === String(userId),
  );
}

function isShipperOnOrder(order, userId) {
  return order.shipper && String(order.shipper?._id || order.shipper) === String(userId);
}

function canViewOrder(order, user) {
  if (!user) return false;
  if (user.role === "admin") return true;
  if (user.role === "shipper" && isShipperOnOrder(order, user._id)) return true;
  if (isBuyer(order, user._id)) return true;
  if (isSellerOnOrder(order, user._id)) return true;
  return false;
}

async function releaseProducts(order, nextStatus = "active") {
  const ids = (order.items || []).map((item) => item.product).filter(Boolean);
  if (ids.length === 0) return;
  await Product.updateMany(
    { _id: { $in: ids }, status: { $in: ["reserved", "sold"] } },
    { $set: { status: nextStatus } },
  );
}

async function markProductsSold(order) {
  const ids = (order.items || []).map((item) => item.product).filter(Boolean);
  if (ids.length === 0) return;
  await Product.updateMany(
    { _id: { $in: ids } },
    { $set: { status: "sold" } },
  );
}

function applyFeeFields(order) {
  const { platformFee, sellerPayout } = calcFees(order.totalAmount);
  order.platformFee = platformFee;
  order.sellerPayout = sellerPayout;
  return { platformFee, sellerPayout };
}

function sanitizeAddress(input = {}) {
  const { normalizePhone } = require("../utils/validators");
  return {
    fullName: String(input.fullName || "").trim(),
    phone: normalizePhone(input.phone),
    line1: String(input.line1 || "").trim(),
    line2: String(input.line2 || "").trim(),
    city: String(input.city || "").trim(),
    district: String(input.district || "").trim(),
    note: String(input.note || "").trim().slice(0, 300),
  };
}

function addressLabel(address) {
  if (!address) return "";
  return [address.line1, address.district, address.city]
    .filter(Boolean)
    .join(", ");
}

function hoursFrom(base, hours) {
  const start = base ? new Date(base) : new Date();
  if (Number.isNaN(start.getTime())) return new Date(Date.now() + hours * 60 * 60 * 1000);
  return new Date(start.getTime() + hours * 60 * 60 * 1000);
}

/**
 * Always expose usable ETAs:
 * - Prefer stored values
 * - Else derive from assignedAt / paidAt / now
 * - For finished steps, fall back to actual timestamps
 */
function resolveOrderEtas(order = {}) {
  const assignedAt = order.assignedAt || order.paidAt || order.createdAt || null;
  let estimatedPickupAt = order.estimatedPickupAt
    ? new Date(order.estimatedPickupAt)
    : null;
  let estimatedDeliveryAt = order.estimatedDeliveryAt
    ? new Date(order.estimatedDeliveryAt)
    : null;

  if (estimatedPickupAt && Number.isNaN(estimatedPickupAt.getTime())) {
    estimatedPickupAt = null;
  }
  if (estimatedDeliveryAt && Number.isNaN(estimatedDeliveryAt.getTime())) {
    estimatedDeliveryAt = null;
  }

  if (!estimatedPickupAt) {
    if (order.pickedUpAt) estimatedPickupAt = new Date(order.pickedUpAt);
    else if (order.shipper || assignedAt) estimatedPickupAt = hoursFrom(assignedAt, 2);
  }

  if (!estimatedDeliveryAt) {
    if (order.deliveredAt) estimatedDeliveryAt = new Date(order.deliveredAt);
    else if (order.pickedUpAt) estimatedDeliveryAt = hoursFrom(order.pickedUpAt, 2);
    else if (order.shipper || assignedAt) estimatedDeliveryAt = hoursFrom(assignedAt, 4);
  }

  return {
    estimatedPickupAt: estimatedPickupAt || null,
    estimatedDeliveryAt: estimatedDeliveryAt || null,
  };
}

function ensureOrderEtas(order) {
  const resolved = resolveOrderEtas(order);
  if (!order.estimatedPickupAt && resolved.estimatedPickupAt) {
    order.estimatedPickupAt = resolved.estimatedPickupAt;
  }
  if (!order.estimatedDeliveryAt && resolved.estimatedDeliveryAt) {
    order.estimatedDeliveryAt = resolved.estimatedDeliveryAt;
  }
  return resolved;
}

function sanitizeOrder(order) {
  const status = normalizeStatus(order.status);
  const etas = resolveOrderEtas(order);
  return {
    id: order._id,
    buyer: order.buyer,
    items: order.items,
    totalAmount: order.totalAmount,
    platformFee: order.platformFee || 0,
    sellerPayout: order.sellerPayout || 0,
    currency: order.currency || "USD",
    note: order.note || "",
    offer: order.offer || null,
    pickupStation: order.pickupStation,
    deliveryAddress: order.deliveryAddress || {},
    pickupAddress: order.pickupAddress || {},
    status,
    paymentStatus: order.paymentStatus || "unpaid",
    escrowStatus: order.escrowStatus || "none",
    paypalOrderId: order.paypalOrderId || "",
    shipper: order.shipper || null,
    assignedAt: order.assignedAt,
    estimatedDeliveryAt: etas.estimatedDeliveryAt,
    estimatedPickupAt: etas.estimatedPickupAt,
    pickedUpAt: order.pickedUpAt,
    deliveredAt: order.deliveredAt,
    buyerConfirmedAt: order.buyerConfirmedAt,
    autoCompleteAt: order.autoCompleteAt,
    completedAt: order.completedAt,
    cancelledAt: order.cancelledAt,
    cancelReason: order.cancelReason || "",
    timeline: order.timeline || [],
    dispute: order.dispute || null,
    paidAt: order.paidAt,
    createdAt: order.createdAt,
    updatedAt: order.updatedAt,
  };
}

const POPULATE = [
  { path: "buyer", select: "fullName email phone deliveryAddress pickupAddress" },
  { path: "pickupStation", select: "city address partnerName lockerCode" },
  { path: "items.product", select: "title status images moderationStatus" },
  { path: "items.seller", select: "fullName email phone pickupAddress" },
  { path: "shipper", select: "fullName email phone" },
  { path: "timeline.by", select: "fullName role" },
];

async function populateOrder(query) {
  return query
    .populate(POPULATE[0])
    .populate(POPULATE[1])
    .populate(POPULATE[2])
    .populate(POPULATE[3])
    .populate(POPULATE[4])
    .populate(POPULATE[5]);
}

async function saveOrder(order, meta = {}) {
  await order.save();
  publishOrderUpdate(order, meta);
  return order;
}

module.exports = {
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
  addressLabel,
  sanitizeOrder,
  populateOrder,
  saveOrder,
  publishOrderUpdate,
  resolveOrderEtas,
  ensureOrderEtas,
};
