const mongoose = require("mongoose");

const productSchema = new mongoose.Schema(
  {
    price: {
      type: Number,
      required: true,
      min: [0.01, "Price must be greater than 0."],
      max: [99999999999, "Price can have at most 11 digits."],
    },
    title: {
      type: String,
      required: true,
      trim: true,
      minlength: [3, "Title must be at least 3 characters."],
    },
    description: {
      type: String,
      required: true,
      trim: true,
      minlength: [10, "Description must be at least 10 characters."],
    },
    condition: {
      type: String,
      enum: ["Like New", "Good", "Fair"],
      default: "Good",
    },
    images: {
      type: [String],
      default: [],
    },
    isVerified: {
      type: Boolean,
      default: false,
    },
    moderationStatus: {
      type: String,
      enum: ["pending", "approved", "rejected"],
      default: "pending",
      index: true,
    },
    rejectionReason: {
      type: String,
      default: "",
      trim: true,
    },
    moderatedAt: {
      type: Date,
      default: null,
    },
    moderatedBy: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "User",
      default: null,
    },
    seller: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "User",
      required: true,
    },
    category: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "Category",
      required: true,
    },
    station: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "Station",
      required: true,
    },
    status: {
      type: String,
      enum: ["active", "reserved", "sold", "archived"],
      default: "active",
    },
    /** Seller opt-in: buyers can send fixed −5/−10/−15% offers */
    acceptsOffers: {
      type: Boolean,
      default: true,
      index: true,
    },
  },
  { timestamps: true }
);

const Product = mongoose.model("Product", productSchema);

module.exports = Product;
