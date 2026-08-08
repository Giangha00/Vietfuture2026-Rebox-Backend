const crypto = require("crypto");
const bcrypt = require("bcryptjs");
const OtpCode = require("../models/OtpCode");
const {
  sendVerificationOtpEmail,
  sendPasswordResetOtpEmail,
  isEmailConfigured,
} = require("./emailService");

const OTP_TTL_MS = 10 * 60 * 1000;
const MAX_ATTEMPTS = 5;

function normalizeEmail(email) {
  return String(email || "").trim().toLowerCase();
}

function generateOtpCode() {
  return String(crypto.randomInt(100000, 999999));
}

async function issueOtp({ email, purpose, fullName = "" }) {
  const normalizedEmail = normalizeEmail(email);
  if (!normalizedEmail) {
    throw new Error("Email is required.");
  }

  const code = generateOtpCode();
  const codeHash = await bcrypt.hash(code, 10);
  const expiresAt = new Date(Date.now() + OTP_TTL_MS);

  await OtpCode.deleteMany({
    email: normalizedEmail,
    purpose,
    consumedAt: null,
  });

  await OtpCode.create({
    email: normalizedEmail,
    codeHash,
    purpose,
    expiresAt,
  });

  if (purpose === "verify_email") {
    await sendVerificationOtpEmail({
      to: normalizedEmail,
      code,
      fullName,
    });
  } else if (purpose === "reset_password") {
    await sendPasswordResetOtpEmail({
      to: normalizedEmail,
      code,
      fullName,
    });
  }

  return {
    email: normalizedEmail,
    expiresInSeconds: Math.floor(OTP_TTL_MS / 1000),
    emailConfigured: isEmailConfigured(),
    // Only expose in non-production when SMTP is missing (local/dev).
    debugCode:
      !isEmailConfigured() && process.env.NODE_ENV !== "production"
        ? code
        : undefined,
  };
}

async function verifyOtp({ email, purpose, code, consume = true }) {
  const normalizedEmail = normalizeEmail(email);
  const otp = String(code || "").trim();

  if (!normalizedEmail || !otp) {
    return { ok: false, message: "Email and OTP are required." };
  }

  const record = await OtpCode.findOne({
    email: normalizedEmail,
    purpose,
    consumedAt: null,
  }).sort({ createdAt: -1 });

  if (!record) {
    return { ok: false, message: "OTP not found or already used. Request a new code." };
  }

  if (record.expiresAt.getTime() < Date.now()) {
    return { ok: false, message: "OTP has expired. Request a new code." };
  }

  if (record.attempts >= MAX_ATTEMPTS) {
    return { ok: false, message: "Too many incorrect attempts. Request a new code." };
  }

  const matched = await bcrypt.compare(otp, record.codeHash);
  if (!matched) {
    record.attempts += 1;
    await record.save();
    return { ok: false, message: "Invalid OTP code." };
  }

  if (consume) {
    record.consumedAt = new Date();
    await record.save();
  }

  return { ok: true, email: normalizedEmail, record };
}

module.exports = {
  issueOtp,
  verifyOtp,
  normalizeEmail,
  OTP_TTL_MS,
};
