const Notification = require("../models/Notification");
const User = require("../models/User");
const { sendFcmToTokens } = require("./fcmService");

async function createAndPushNotification({
  userId,
  title,
  body,
  type = "system",
  link = "",
  data = {},
}) {
  const notification = await Notification.create({
    user: userId,
    title,
    body,
    type,
    link,
    data,
  });

  const user = await User.findById(userId).select("fcmTokens");
  const tokens = Array.isArray(user?.fcmTokens) ? user.fcmTokens : [];

  if (tokens.length > 0) {
    const result = await sendFcmToTokens(tokens, { title, body, data, link });
    if (result.invalidTokens?.length) {
      await User.updateOne(
        { _id: userId },
        { $pull: { fcmTokens: { $in: result.invalidTokens } } },
      );
    }
  }

  return notification;
}

module.exports = { createAndPushNotification };
