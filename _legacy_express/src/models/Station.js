const mongoose = require("mongoose");

const stationSchema = new mongoose.Schema(
  {
    city: {
      type: String,
      required: true,
      trim: true,
      minlength: [2, "City must be at least 2 characters."],
    },
    address: {
      type: String,
      required: true,
      trim: true,
      minlength: [5, "Address must be at least 5 characters."],
    },
    lockerCode: {
      type: String,
      required: true,
      trim: true,
      minlength: [1, "Locker code is required."],
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
