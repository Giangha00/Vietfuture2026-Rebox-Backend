const Offer = require("../models/Offer");

async function expireIfNeeded(offer) {
  if (!offer) return offer;
  if (offer.status === "pending" && offer.expiresAt && offer.expiresAt < new Date()) {
    offer.status = "expired";
    await offer.save();
  }
  return offer;
}

module.exports = { expireIfNeeded };
