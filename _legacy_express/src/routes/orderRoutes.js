const express = require("express");
const {
  protect,
  requireVerified,
  authorize,
} = require("../middleware/authMiddleware");
const {
  createOrder,
  getMyOrders,
  getSellingOrders,
  getOrderById,
  startPaypalPayment,
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
} = require("../controllers/orderController");

const router = express.Router();

router.use(protect, requireVerified);

router.get("/events/stream", streamOrderEvents);
router.get("/mine", getMyOrders);
router.get("/selling", getSellingOrders);
router.get("/shipper/jobs", authorize("shipper", "admin"), listShipperJobs);
router.post("/auto-complete", authorize("admin"), autoCompleteDelivered);

router.get("/:id", getOrderById);
router.post("/", createOrder);

router.post("/:id/pay", startPaypalPayment);
router.post("/:id/capture-payment", capturePaypalPayment);
router.post("/:id/seller-confirm", sellerConfirm);
router.post("/:id/seller-reject", sellerReject);
router.post("/:id/cancel", cancelOrder);
router.post("/:id/assign-shipper", assignShipper);
router.post("/:id/shipper-status", shipperUpdateStatus);
router.post("/:id/confirm-delivery", buyerConfirm);
router.post("/:id/dispute", openDispute);
router.post("/:id/resolve-dispute", authorize("admin"), resolveDispute);

module.exports = router;
