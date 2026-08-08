const User = require("../models/User");
const { signToken } = require("../utils/jwt");
const {
  issueOtp,
  verifyOtp,
  normalizeEmail,
} = require("../services/otpService");
const { isEmailConfigured } = require("../services/emailService");
const {
  validatePhone,
  validatePassword,
  validateEmail,
  normalizePhone,
} = require("../utils/validators");

function sanitizeAddress(address = {}) {
  const phoneResult = validatePhone(address.phone, { required: false });
  return {
    fullName: String(address.fullName || "").trim(),
    phone: phoneResult.ok ? phoneResult.phone : normalizePhone(address.phone),
    line1: String(address.line1 || "").trim(),
    line2: String(address.line2 || "").trim(),
    city: String(address.city || "").trim(),
    district: String(address.district || "").trim(),
    note: String(address.note || "").trim().slice(0, 300),
  };
}

function sanitizeUser(userDoc) {
  return {
    id: userDoc._id,
    fullName: userDoc.fullName,
    email: userDoc.email,
    phone: userDoc.phone,
    role: userDoc.role,
    avatarUrl: userDoc.avatarUrl,
    bio: userDoc.bio || "",
    emailVerified: Boolean(userDoc.emailVerified),
    emailVerifiedAt: userDoc.emailVerifiedAt || null,
    deliveryAddress: sanitizeAddress(userDoc.deliveryAddress),
    pickupAddress: sanitizeAddress(userDoc.pickupAddress),
    createdAt: userDoc.createdAt,
  };
}

async function register(req, res) {
  try {
    const { fullName, email, phone, password } = req.body;

    const name = String(fullName || "").trim();
    if (!name || name.length < 2) {
      return res.status(400).json({
        message: "fullName is required (at least 2 characters).",
      });
    }

    const emailCheck = validateEmail(email);
    if (!emailCheck.ok) {
      return res.status(400).json({ message: emailCheck.message });
    }

    const phoneCheck = validatePhone(phone, { required: true });
    if (!phoneCheck.ok) {
      return res.status(400).json({ message: phoneCheck.message });
    }

    const passwordCheck = validatePassword(password);
    if (!passwordCheck.ok) {
      return res.status(400).json({ message: passwordCheck.message });
    }

    const normalizedEmail = normalizeEmail(emailCheck.email);
    const existingUser = await User.findOne({ email: normalizedEmail });
    if (existingUser) {
      return res.status(409).json({ message: "Email already exists." });
    }

    const user = await User.create({
      fullName: name,
      email: normalizedEmail,
      phone: phoneCheck.phone,
      password,
      emailVerified: false,
    });

    const otpResult = await issueOtp({
      email: user.email,
      purpose: "verify_email",
      fullName: user.fullName,
    });

    return res.status(201).json({
      message:
        "Register successful. Please verify your email with the OTP we sent.",
      needsVerification: true,
      email: user.email,
      expiresInSeconds: otpResult.expiresInSeconds,
      emailConfigured: otpResult.emailConfigured,
      debugCode: otpResult.debugCode,
      user: sanitizeUser(user),
    });
  } catch (error) {
    if (error?.name === "ValidationError") {
      const first = Object.values(error.errors || {})[0];
      return res.status(400).json({
        message: first?.message || "Validation failed.",
      });
    }
    return res.status(500).json({
      message: "Failed to register user.",
      error: error.message,
    });
  }
}

async function login(req, res) {
  try {
    const { email, password } = req.body;

    if (!email || !password) {
      return res
        .status(400)
        .json({ message: "email and password are required." });
    }

    const user = await User.findOne({ email: normalizeEmail(email) }).select(
      "+password",
    );
    if (!user) {
      return res.status(401).json({ message: "Invalid email or password." });
    }

    const isMatched = await user.comparePassword(password);
    if (!isMatched) {
      return res.status(401).json({ message: "Invalid email or password." });
    }

    if (!user.emailVerified) {
      const otpResult = await issueOtp({
        email: user.email,
        purpose: "verify_email",
        fullName: user.fullName,
      });

      return res.status(403).json({
        message: "Please verify your email before signing in.",
        needsVerification: true,
        email: user.email,
        expiresInSeconds: otpResult.expiresInSeconds,
        emailConfigured: otpResult.emailConfigured,
        debugCode: otpResult.debugCode,
      });
    }

    const token = signToken(user._id.toString());
    return res.status(200).json({
      message: "Login successful.",
      token,
      user: sanitizeUser(user),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to login.",
      error: error.message,
    });
  }
}

async function verifyEmail(req, res) {
  try {
    const email = normalizeEmail(req.body?.email);
    const code = req.body?.otp || req.body?.code;

    const result = await verifyOtp({
      email,
      purpose: "verify_email",
      code,
      consume: true,
    });

    if (!result.ok) {
      return res.status(400).json({ message: result.message });
    }

    const user = await User.findOne({ email });
    if (!user) {
      return res.status(404).json({ message: "User not found." });
    }

    user.emailVerified = true;
    user.emailVerifiedAt = new Date();
    await user.save();

    const token = signToken(user._id.toString());
    return res.status(200).json({
      message: "Email verified successfully.",
      token,
      user: sanitizeUser(user),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to verify email.",
      error: error.message,
    });
  }
}

async function resendVerification(req, res) {
  try {
    const email = normalizeEmail(req.body?.email);
    if (!email) {
      return res.status(400).json({ message: "email is required." });
    }

    const user = await User.findOne({ email });
    if (!user) {
      return res.status(404).json({ message: "User not found." });
    }

    if (user.emailVerified) {
      return res.status(400).json({ message: "Email is already verified." });
    }

    const otpResult = await issueOtp({
      email: user.email,
      purpose: "verify_email",
      fullName: user.fullName,
    });

    return res.status(200).json({
      message: "Verification code sent.",
      email: user.email,
      expiresInSeconds: otpResult.expiresInSeconds,
      emailConfigured: otpResult.emailConfigured,
      debugCode: otpResult.debugCode,
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to resend verification code.",
      error: error.message,
    });
  }
}

async function forgotPassword(req, res) {
  try {
    const email = normalizeEmail(req.body?.email);
    if (!email) {
      return res.status(400).json({ message: "email is required." });
    }

    const user = await User.findOne({ email });

    // Always return success to avoid email enumeration.
    if (!user) {
      return res.status(200).json({
        message: "If that email exists, an OTP has been sent.",
        email,
        expiresInSeconds: 600,
        emailConfigured: isEmailConfigured(),
      });
    }

    const otpResult = await issueOtp({
      email: user.email,
      purpose: "reset_password",
      fullName: user.fullName,
    });

    return res.status(200).json({
      message: "If that email exists, an OTP has been sent.",
      email: user.email,
      expiresInSeconds: otpResult.expiresInSeconds,
      emailConfigured: otpResult.emailConfigured,
      debugCode: otpResult.debugCode,
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to start password reset.",
      error: error.message,
    });
  }
}

async function verifyResetOtp(req, res) {
  try {
    const email = normalizeEmail(req.body?.email);
    const code = req.body?.otp || req.body?.code;

    const result = await verifyOtp({
      email,
      purpose: "reset_password",
      code,
      consume: false,
    });

    if (!result.ok) {
      return res.status(400).json({ message: result.message });
    }

    return res.status(200).json({
      message: "OTP verified. You can set a new password.",
      email,
      verified: true,
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to verify OTP.",
      error: error.message,
    });
  }
}

async function resetPassword(req, res) {
  try {
    const email = normalizeEmail(req.body?.email);
    const code = req.body?.otp || req.body?.code;
    const password = String(req.body?.password || req.body?.newPassword || "");

    const passwordCheck = validatePassword(password);
    if (!passwordCheck.ok) {
      return res.status(400).json({
        message: passwordCheck.message,
      });
    }

    const result = await verifyOtp({
      email,
      purpose: "reset_password",
      code,
      consume: true,
    });

    if (!result.ok) {
      return res.status(400).json({ message: result.message });
    }

    const user = await User.findOne({ email }).select("+password");
    if (!user) {
      return res.status(404).json({ message: "User not found." });
    }

    user.password = password;
    if (!user.emailVerified) {
      user.emailVerified = true;
      user.emailVerifiedAt = new Date();
    }
    await user.save();

    return res.status(200).json({
      message: "Password updated successfully. You can sign in now.",
      email: user.email,
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to reset password.",
      error: error.message,
    });
  }
}

async function me(req, res) {
  return res.status(200).json({
    user: sanitizeUser(req.user),
  });
}

async function updateMe(req, res) {
  try {
    const { fullName, phone, bio, avatarUrl, email, deliveryAddress, pickupAddress } =
      req.body;
    const user = await User.findById(req.user._id);

    if (!user) {
      return res.status(404).json({ message: "User not found." });
    }

    if (fullName !== undefined) {
      const nextName = String(fullName || "").trim();
      if (!nextName) {
        return res.status(400).json({ message: "fullName cannot be empty." });
      }
      user.fullName = nextName;
    }

    if (phone !== undefined) {
      const phoneCheck = validatePhone(phone, { required: true });
      if (!phoneCheck.ok) {
        return res.status(400).json({ message: phoneCheck.message });
      }
      user.phone = phoneCheck.phone;
    }

    if (bio !== undefined) {
      user.bio = String(bio || "")
        .trim()
        .slice(0, 280);
    }

    if (avatarUrl !== undefined) {
      user.avatarUrl = String(avatarUrl || "").trim();
    }

    if (deliveryAddress !== undefined && deliveryAddress !== null) {
      const phoneCheck = validatePhone(deliveryAddress.phone, {
        required: Boolean(deliveryAddress.line1 || deliveryAddress.city),
        field: "Delivery phone",
      });
      if (!phoneCheck.ok) {
        return res.status(400).json({ message: phoneCheck.message });
      }
      user.deliveryAddress = {
        ...sanitizeAddress(deliveryAddress),
        phone: phoneCheck.phone,
      };
    }

    if (pickupAddress !== undefined && pickupAddress !== null) {
      const phoneCheck = validatePhone(pickupAddress.phone, {
        required: Boolean(pickupAddress.line1 || pickupAddress.city),
        field: "Pickup phone",
      });
      if (!phoneCheck.ok) {
        return res.status(400).json({ message: phoneCheck.message });
      }
      user.pickupAddress = {
        ...sanitizeAddress(pickupAddress),
        phone: phoneCheck.phone,
      };
    }

    if (email !== undefined) {
      const nextEmail = normalizeEmail(email);
      if (!nextEmail) {
        return res.status(400).json({ message: "email cannot be empty." });
      }
      if (nextEmail !== user.email) {
        const taken = await User.findOne({ email: nextEmail });
        if (taken) {
          return res.status(409).json({ message: "Email already exists." });
        }
        user.email = nextEmail;
        user.emailVerified = false;
        user.emailVerifiedAt = null;
        await issueOtp({
          email: nextEmail,
          purpose: "verify_email",
          fullName: user.fullName,
        });
      }
    }

    await user.save();

    return res.status(200).json({
      message: "Profile updated.",
      user: sanitizeUser(user),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to update profile.",
      error: error.message,
    });
  }
}

module.exports = {
  register,
  login,
  me,
  updateMe,
  verifyEmail,
  resendVerification,
  forgotPassword,
  verifyResetOtp,
  resetPassword,
};
