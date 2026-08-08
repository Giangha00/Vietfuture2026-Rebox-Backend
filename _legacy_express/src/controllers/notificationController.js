const Notification = require("../models/Notification");
const User = require("../models/User");
const { createAndPushNotification } = require("../services/notificationService");
const { isFirebaseReady } = require("../services/fcmService");

function sanitizeNotification(doc) {
  return {
    id: doc._id,
    title: doc.title,
    body: doc.body,
    type: doc.type,
    link: doc.link || "",
    data: doc.data || {},
    readAt: doc.readAt,
    createdAt: doc.createdAt,
  };
}

async function registerFcmToken(req, res) {
  try {
    const token = String(req.body?.token || "").trim();
    if (!token) {
      return res.status(400).json({ message: "token is required." });
    }

    await User.updateOne(
      { _id: req.user._id },
      { $addToSet: { fcmTokens: token } },
    );

    return res.status(200).json({
      message: "FCM token registered.",
      firebaseReady: isFirebaseReady(),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to register FCM token.",
      error: error.message,
    });
  }
}

async function removeFcmToken(req, res) {
  try {
    const token = String(req.body?.token || "").trim();
    if (!token) {
      return res.status(400).json({ message: "token is required." });
    }

    await User.updateOne(
      { _id: req.user._id },
      { $pull: { fcmTokens: token } },
    );

    return res.status(200).json({ message: "FCM token removed." });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to remove FCM token.",
      error: error.message,
    });
  }
}

async function listNotifications(req, res) {
  try {
    const notifications = await Notification.find({ user: req.user._id })
      .sort({ createdAt: -1 })
      .limit(50);

    const unreadCount = await Notification.countDocuments({
      user: req.user._id,
      readAt: null,
    });

    return res.status(200).json({
      notifications: notifications.map(sanitizeNotification),
      unreadCount,
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to fetch notifications.",
      error: error.message,
    });
  }
}

async function markNotificationRead(req, res) {
  try {
    const notification = await Notification.findOneAndUpdate(
      { _id: req.params.id, user: req.user._id, readAt: null },
      { $set: { readAt: new Date() } },
      { new: true },
    );

    if (!notification) {
      const existing = await Notification.findOne({
        _id: req.params.id,
        user: req.user._id,
      });
      if (!existing) {
        return res.status(404).json({ message: "Notification not found." });
      }
      return res.status(200).json({ notification: sanitizeNotification(existing) });
    }

    return res.status(200).json({
      notification: sanitizeNotification(notification),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to mark notification as read.",
      error: error.message,
    });
  }
}

async function markAllNotificationsRead(req, res) {
  try {
    await Notification.updateMany(
      { user: req.user._id, readAt: null },
      { $set: { readAt: new Date() } },
    );

    return res.status(200).json({ message: "All notifications marked as read." });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to mark notifications as read.",
      error: error.message,
    });
  }
}

async function sendCartNotification(req, res) {
  try {
    const productTitle = String(req.body?.productTitle || "").trim();
    const productId = String(req.body?.productId || "").trim();

    if (!productTitle) {
      return res.status(400).json({ message: "productTitle is required." });
    }

    const notification = await createAndPushNotification({
      userId: req.user._id,
      title: "Added to cart",
      body: `"${productTitle}" was added to your cart.`,
      type: "system",
      link: "/order",
      data: {
        type: "cart",
        productId,
      },
    });

    return res.status(201).json({
      message: "Cart notification sent.",
      firebaseReady: isFirebaseReady(),
      notification: sanitizeNotification(notification),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to send cart notification.",
      error: error.message,
    });
  }
}

async function sendTestNotification(req, res) {
  try {
    const title = String(req.body?.title || "ReBox test notification").trim();
    const body = String(
      req.body?.body || "Push notifications are working on your account.",
    ).trim();

    const notification = await createAndPushNotification({
      userId: req.user._id,
      title,
      body,
      type: "test",
      link: "/profile",
      data: { source: "test" },
    });

    return res.status(201).json({
      message: "Test notification sent.",
      firebaseReady: isFirebaseReady(),
      notification: sanitizeNotification(notification),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to send test notification.",
      error: error.message,
    });
  }
}

module.exports = {
  registerFcmToken,
  removeFcmToken,
  listNotifications,
  markNotificationRead,
  markAllNotificationsRead,
  sendTestNotification,
  sendCartNotification,
};
