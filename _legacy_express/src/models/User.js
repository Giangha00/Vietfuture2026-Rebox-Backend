const mongoose = require("mongoose");
const bcrypt = require("bcryptjs");
const { isValidPhone, isValidPassword } = require("../utils/validators");

const addressSchema = new mongoose.Schema(
  {
    fullName: { type: String, default: "", trim: true },
    phone: {
      type: String,
      default: "",
      trim: true,
      validate: {
        validator(value) {
          if (!value) return true;
          return isValidPhone(value, { required: false });
        },
        message: "Phone number must be exactly 10 digits.",
      },
    },
    line1: { type: String, default: "", trim: true },
    line2: { type: String, default: "", trim: true },
    city: { type: String, default: "", trim: true },
    district: { type: String, default: "", trim: true },
    note: { type: String, default: "", trim: true, maxlength: 300 },
  },
  { _id: false },
);

const userSchema = new mongoose.Schema(
  {
    fullName: {
      type: String,
      required: true,
      trim: true,
      minlength: 2,
    },
    email: {
      type: String,
      required: true,
      unique: true,
      lowercase: true,
      trim: true,
    },
    phone: {
      type: String,
      required: [true, "Phone number is required."],
      trim: true,
      validate: {
        validator(value) {
          return isValidPhone(value, { required: true });
        },
        message: "Phone number must be exactly 10 digits.",
      },
    },
    password: {
      type: String,
      required: true,
      minlength: 8,
      select: false,
      validate: {
        validator(value) {
          // Already-hashed values skip strength check (bcrypt hashes start with $2)
          if (typeof value === "string" && value.startsWith("$2")) return true;
          return isValidPassword(value);
        },
        message:
          "Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.",
      },
    },
    role: {
      type: String,
      enum: ["user", "admin", "shipper"],
      default: "user",
    },
    avatarUrl: {
      type: String,
      default: "/default-avatar.svg",
    },
    bio: {
      type: String,
      default: "",
      trim: true,
      maxlength: 280,
    },
    emailVerified: {
      type: Boolean,
      default: false,
      index: true,
    },
    emailVerifiedAt: {
      type: Date,
      default: null,
    },
    fcmTokens: {
      type: [String],
      default: [],
    },
    deliveryAddress: {
      type: addressSchema,
      default: () => ({}),
    },
    pickupAddress: {
      type: addressSchema,
      default: () => ({}),
    },
  },
  { timestamps: true },
);

userSchema.pre("validate", function normalizePhoneField() {
  if (this.phone != null) {
    this.phone = String(this.phone).replace(/\D/g, "");
  }
});

userSchema.pre("save", async function hashPassword() {
  if (!this.isModified("password")) return;
  this.password = await bcrypt.hash(this.password, 12);
});

userSchema.methods.comparePassword = async function comparePassword(
  candidatePassword,
) {
  return bcrypt.compare(candidatePassword, this.password);
};

const User = mongoose.model("User", userSchema);

module.exports = User;
