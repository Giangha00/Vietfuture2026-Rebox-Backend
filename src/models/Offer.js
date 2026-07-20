const mongoose = require("mongoose");

const offerSchema = new mongoose.Schema(
  {
    product: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "Product",
      required: true,
    },
    buyer: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "User",
      required: true,
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
    },
    status: {
      type: String,
      enum: ["pending", "accepted", "rejected", "cancelled"],
      default: "pending",
    },
  },
  { timestamps: true }
);

const Offer = mongoose.model("Offer", offerSchema);

module.exports = Offer;
