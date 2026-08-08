const Order = require("../../models/Order");
const User = require("../../models/User");
const { renderAdmin } = require("../utils/render");
const {
  pushTimeline,
  normalizeStatus,
  releaseProducts,
  markProductsSold,
  sanitizeOrder,
  saveOrder,
} = require("../../services/orderService");
const { createAndPushNotification } = require("../../services/notificationService");

async function list(req, res) {
  const status = String(req.query.status || "").trim();
  const filter = status ? { status } : {};
  const orders = await Order.find(filter)
    .populate("buyer", "fullName email")
    .populate("shipper", "fullName email")
    .populate("items.seller", "fullName email")
    .sort({ createdAt: -1 })
    .limit(200);

  return renderAdmin(res, "orders/index", {
    title: "Orders",
    orders,
    status,
    success: req.query.success || null,
    error: req.query.error || null,
  });
}

async function show(req, res) {
  const order = await Order.findById(req.params.id)
    .populate("buyer", "fullName email phone")
    .populate("shipper", "fullName email phone")
    .populate("items.seller", "fullName email phone")
    .populate("pickupStation", "city address partnerName lockerCode")
    .populate("timeline.by", "fullName role");

  if (!order) {
    return res.redirect("/admin/orders?error=Order not found");
  }

  const shippers = await User.find({ role: "shipper" })
    .select("fullName email phone")
    .sort({ fullName: 1 });

  return renderAdmin(res, "orders/show", {
    title: `Order ${order._id}`,
    order,
    shippers,
    success: req.query.success || null,
    error: req.query.error || null,
  });
}

async function assignShipper(req, res) {
  try {
    const order = await Order.findById(req.params.id);
    if (!order) return res.redirect("/admin/orders?error=Order not found");

    const status = normalizeStatus(order.status);
    if (!["seller_confirmed", "pickup_assigned"].includes(status)) {
      return res.redirect(
        `/admin/orders/${order._id}?error=Order must be seller_confirmed`,
      );
    }

    const shipper = await User.findById(req.body.shipperId);
    if (!shipper || shipper.role !== "shipper") {
      return res.redirect(`/admin/orders/${order._id}?error=Invalid shipper`);
    }

    order.shipper = shipper._id;
    order.status = "pickup_assigned";
    order.assignedAt = new Date();
    pushTimeline(
      order,
      "pickup_assigned",
      `Admin assigned shipper ${shipper.fullName}`,
      req.admin?._id || null,
    );
    await saveOrder(order);

    createAndPushNotification({
      userId: shipper._id,
      title: "New delivery job",
      body: `Assigned to order ${order._id}`,
      type: "system",
      link: "/shipper",
      data: { orderId: String(order._id) },
    }).catch(() => {});

    return res.redirect(`/admin/orders/${order._id}?success=Shipper assigned`);
  } catch (error) {
    return res.redirect(`/admin/orders/${req.params.id}?error=${encodeURIComponent(error.message)}`);
  }
}

async function resolveDispute(req, res) {
  try {
    const order = await Order.findById(req.params.id);
    if (!order) return res.redirect("/admin/orders?error=Order not found");
    if (normalizeStatus(order.status) !== "disputed") {
      return res.redirect(`/admin/orders/${order._id}?error=Not disputed`);
    }

    const resolutionStatus = String(req.body.resolutionStatus || "").trim();
    const resolution = String(req.body.resolution || "").trim();

    if (!["completed", "cancelled", "refunded"].includes(resolutionStatus)) {
      return res.redirect(`/admin/orders/${order._id}?error=Invalid resolution status`);
    }
    if (resolution.length < 5) {
      return res.redirect(`/admin/orders/${order._id}?error=Resolution note must be at least 5 characters`);
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
      order.cancelReason = resolution || "Dispute refunded";
      order.escrowStatus = "refunded";
      order.paymentStatus = "refunded";
      await releaseProducts(order, "active");
    }

    pushTimeline(
      order,
      order.status,
      `Admin resolved dispute: ${resolutionStatus}. ${resolution}`,
      req.admin?._id || null,
    );
    await saveOrder(order);

    return res.redirect(`/admin/orders/${order._id}?success=Dispute resolved`);
  } catch (error) {
    return res.redirect(`/admin/orders/${req.params.id}?error=${encodeURIComponent(error.message)}`);
  }
}

module.exports = {
  list,
  show,
  assignShipper,
  resolveDispute,
  sanitizeOrder,
};
