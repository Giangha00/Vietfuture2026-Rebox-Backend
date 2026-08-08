const { EventEmitter } = require("events");

const orderEvents = new EventEmitter();
// Many browser tabs may subscribe during local demo testing.
orderEvents.setMaxListeners(100);

function participantIds(order) {
  const ids = new Set();
  if (order?.buyer) ids.add(String(order.buyer._id || order.buyer));
  if (order?.shipper) ids.add(String(order.shipper._id || order.shipper));
  for (const item of order?.items || []) {
    if (item?.seller) ids.add(String(item.seller._id || item.seller));
  }
  return [...ids];
}

function publishOrderUpdate(order, meta = {}) {
  if (!order?._id) return;
  const payload = {
    type: "order.updated",
    orderId: String(order._id),
    status: order.status,
    paymentStatus: order.paymentStatus || "",
    escrowStatus: order.escrowStatus || "",
    participants: participantIds(order),
    at: new Date().toISOString(),
    ...meta,
  };
  orderEvents.emit("order.updated", payload);
}

function subscribeOrderUpdates(listener) {
  orderEvents.on("order.updated", listener);
  return () => orderEvents.off("order.updated", listener);
}

function writeSse(res, event, data) {
  res.write(`event: ${event}\n`);
  res.write(`data: ${JSON.stringify(data)}\n\n`);
}

/**
 * SSE stream of order status changes for the authenticated user.
 * Shippers/admins receive all updates (needed for available jobs list).
 */
function streamOrderEvents(req, res) {
  res.setHeader("Content-Type", "text/event-stream");
  res.setHeader("Cache-Control", "no-cache, no-transform");
  res.setHeader("Connection", "keep-alive");
  res.setHeader("X-Accel-Buffering", "no");
  if (typeof res.flushHeaders === "function") res.flushHeaders();

  const userId = String(req.user._id);
  const receiveAll = req.user.role === "admin" || req.user.role === "shipper";

  writeSse(res, "connected", {
    ok: true,
    userId,
    receiveAll,
    at: new Date().toISOString(),
  });

  const onUpdate = (payload) => {
    if (
      !receiveAll &&
      !payload.participants.includes(userId)
    ) {
      return;
    }
    writeSse(res, "order.updated", payload);
  };

  const unsubscribe = subscribeOrderUpdates(onUpdate);
  const heartbeat = setInterval(() => {
    res.write(`: ping ${Date.now()}\n\n`);
  }, 15000);

  req.on("close", () => {
    clearInterval(heartbeat);
    unsubscribe();
  });
}

module.exports = {
  publishOrderUpdate,
  subscribeOrderUpdates,
  streamOrderEvents,
};
