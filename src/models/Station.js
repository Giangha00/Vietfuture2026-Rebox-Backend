const mongoose = require("mongoose");

const stationSchema = new mongoose.Schema(
  {
    city: {
      type: String,
      required: true,
      trim: true,
    },
    address: {
      type: String,
      required: true,
      trim: true,
    },
    lockerCode: {
      type: String,
      required: true,
      trim: true,
    },
    partnerName: {
      type: String,
      enum: ["Circle K", "GS25", "Other"],
      default: "Other",
    },
    isActive: {
      type: Boolean,
      default: true,
    },
  },
  { timestamps: true }
);

const Station = mongoose.model("Station", stationSchema);

module.exports = Station;
