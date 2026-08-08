const express = require("express");
const { protect, requireVerified } = require("../middleware/authMiddleware");
const {
  registerFcmToken,
  removeFcmToken,
  listNotifications,
  markNotificationRead,
  markAllNotificationsRead,
  sendTestNotification,
  sendCartNotification,
} = require("../controllers/notificationController");

const router = express.Router();

router.use(protect, requireVerified);

router.get("/", listNotifications);
router.post("/fcm-token", registerFcmToken);
router.delete("/fcm-token", removeFcmToken);
router.patch("/read-all", markAllNotificationsRead);
router.patch("/:id/read", markNotificationRead);
router.post("/test", sendTestNotification);
router.post("/cart", sendCartNotification);

module.exports = router;
