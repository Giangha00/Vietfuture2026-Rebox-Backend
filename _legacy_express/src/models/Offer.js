const mongoose = require("mongoose");

const OFFER_PERCENTS = [5, 10, 15];

const offerSchema = new mongoose.Schema(
  {
    product: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "Product",
      required: true,
      index: true,
    },
    buyer: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "User",
      required: true,
      index: true,
    },
    seller: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "User",
      required: true,
      index: true,
    },
    listPrice: {
      type: Number,
      required: true,
      min: 0,
    },
    discountPercent: {
      type: Number,
      required: true,
      enum: OFFER_PERCENTS,
    },
    offerPrice: {
      type: Number,
      required: true,
      min: 0,
    },
    message: {
      type: String,
      default: "",
      trim: true,
      maxlength: 300,
    },
    status: {
      type: String,
      enum: ["pending", "accepted", "rejected", "cancelled", "expired"],
      default: "pending",
      index: true,
    },
    expiresAt: {
      type: Date,
      required: true,
      index: true,
    },
    acceptedAt: { type: Date, default: null },
    rejectedAt: { type: Date, default: null },
    cancelledAt: { type: Date, default: null },
    order: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "Order",
      default: null,
    },
  },
  { timestamps: true },
);

offerSchema.index({ product: 1, buyer: 1, status: 1 });

function calcOfferPrice(listPrice, discountPercent) {
  const list = Number(listPrice) || 0;
  const pct = Number(discountPercent) || 0;
  const price = Math.round(list * (1 - pct / 100) * 100) / 100;
  return Math.max(0.01, price);
}

module.exports = mongoose.model("Offer", offerSchema);
module.exports.OFFER_PERCENTS = OFFER_PERCENTS;
module.exports.calcOfferPrice = calcOfferPrice;
