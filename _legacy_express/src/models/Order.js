const mongoose = require("mongoose");

const addressSchema = new mongoose.Schema(
  {
    fullName: { type: String, default: "", trim: true },
    phone: { type: String, default: "", trim: true },
    line1: { type: String, default: "", trim: true },
    line2: { type: String, default: "", trim: true },
    city: { type: String, default: "", trim: true },
    district: { type: String, default: "", trim: true },
    note: { type: String, default: "", trim: true, maxlength: 300 },
  },
  { _id: false },
);

const orderItemSchema = new mongoose.Schema(
  {
    product: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "Product",
      required: true,
    },
    title: {
      type: String,
      required: true,
      trim: true,
    },
    price: {
      type: Number,
      required: true,
      min: 0,
    },
    image: {
      type: String,
      default: "",
    },
    seller: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "User",
      required: true,
    },
  },
  { _id: false },
);

const timelineSchema = new mongoose.Schema(
  {
    status: { type: String, required: true },
    note: { type: String, default: "" },
    at: { type: Date, default: Date.now },
    by: { type: mongoose.Schema.Types.ObjectId, ref: "User", default: null },
  },
  { _id: false },
);

const ORDER_STATUSES = [
  "pending_payment",
  "paid",
  "seller_confirmed",
  "pickup_assigned",
  "picked_up",
  "out_for_delivery",
  "delivered",
  "completed",
  "cancelled",
  "disputed",
  // legacy
  "pending",
  "confirmed",
];

const orderSchema = new mongoose.Schema(
  {
    buyer: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "User",
      required: true,
      index: true,
    },
    items: {
      type: [orderItemSchema],
      validate: [(v) => Array.isArray(v) && v.length > 0, "Order needs at least one item."],
    },
    totalAmount: {
      type: Number,
      required: true,
      min: 0,
    },
    platformFee: {
      type: Number,
      default: 0,
      min: 0,
    },
    sellerPayout: {
      type: Number,
      default: 0,
      min: 0,
    },
    currency: {
      type: String,
      default: "USD",
      uppercase: true,
    },
    note: {
      type: String,
      default: "",
      trim: true,
      maxlength: 500,
    },
    offer: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "Offer",
      default: null,
      index: true,
    },
    pickupStation: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "Station",
      default: null,
    },
    deliveryAddress: {
      type: addressSchema,
      default: () => ({}),
    },
    pickupAddress: {
      type: addressSchema,
      default: () => ({}),
    },
    status: {
      type: String,
      enum: ORDER_STATUSES,
      default: "pending_payment",
      index: true,
    },
    paymentStatus: {
      type: String,
      enum: ["unpaid", "pending", "paid", "refunded", "released"],
      default: "unpaid",
      index: true,
    },
    escrowStatus: {
      type: String,
      enum: ["none", "held", "released", "refunded"],
      default: "none",
      index: true,
    },
    paypalOrderId: {
      type: String,
      default: "",
      index: true,
    },
    paypalCaptureId: {
      type: String,
      default: "",
    },
    paidAt: {
      type: Date,
      default: null,
    },
    shipper: {
      type: mongoose.Schema.Types.ObjectId,
      ref: "User",
      default: null,
      index: true,
    },
    assignedAt: {
      type: Date,
      default: null,
    },
    estimatedDeliveryAt: {
      type: Date,
      default: null,
    },
    estimatedPickupAt: {
      type: Date,
      default: null,
    },
    pickedUpAt: {
      type: Date,
      default: null,
    },
    deliveredAt: {
      type: Date,
      default: null,
    },
    buyerConfirmedAt: {
      type: Date,
      default: null,
    },
    autoCompleteAt: {
      type: Date,
      default: null,
    },
    completedAt: {
      type: Date,
      default: null,
    },
    cancelledAt: {
      type: Date,
      default: null,
    },
    cancelReason: {
      type: String,
      default: "",
      trim: true,
    },
    timeline: {
      type: [timelineSchema],
      default: [],
    },
    dispute: {
      reason: { type: String, default: "" },
      evidence: { type: [String], default: [] },
      createdAt: { type: Date, default: null },
      createdBy: { type: mongoose.Schema.Types.ObjectId, ref: "User", default: null },
      resolvedAt: { type: Date, default: null },
      resolution: { type: String, default: "" },
      resolutionStatus: {
        type: String,
        enum: ["", "completed", "cancelled", "refunded"],
        default: "",
      },
    },
  },
  { timestamps: true },
);

orderSchema.index({ "items.seller": 1, createdAt: -1 });

module.exports = mongoose.model("Order", orderSchema);
module.exports.ORDER_STATUSES = ORDER_STATUSES;
module.exports.addressSchema = addressSchema;
