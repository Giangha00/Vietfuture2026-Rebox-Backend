const User = require("../models/User");
const { signToken } = require("../utils/jwt");

function sanitizeUser(userDoc) {
  return {
    id: userDoc._id,
    fullName: userDoc.fullName,
    email: userDoc.email,
    phone: userDoc.phone,
    role: userDoc.role,
    avatarUrl: userDoc.avatarUrl,
    bio: userDoc.bio || "",
    createdAt: userDoc.createdAt,
  };
}

async function register(req, res) {
  try {
    const { fullName, email, phone, password } = req.body;

    if (!fullName || !email || !password) {
      return res.status(400).json({
        message: "fullName, email, password are required.",
      });
    }

    const existingUser = await User.findOne({ email: email.toLowerCase() });
    if (existingUser) {
      return res.status(409).json({ message: "Email already exists." });
    }

    const user = await User.create({ fullName, email, phone, password });
    const token = signToken(user._id.toString());

    return res.status(201).json({
      message: "Register successful.",
      token,
      user: sanitizeUser(user),
    });
  } catch (error) {
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
      return res.status(400).json({ message: "email and password are required." });
    }

    const user = await User.findOne({ email: email.toLowerCase() }).select("+password");
    if (!user) {
      return res.status(401).json({ message: "Invalid email or password." });
    }

    const isMatched = await user.comparePassword(password);
    if (!isMatched) {
      return res.status(401).json({ message: "Invalid email or password." });
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

async function me(req, res) {
  return res.status(200).json({
    user: sanitizeUser(req.user),
  });
}

async function updateMe(req, res) {
  try {
    const { fullName, phone, bio, avatarUrl, email } = req.body;
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
      user.phone = String(phone || "").trim();
    }

    if (bio !== undefined) {
      user.bio = String(bio || "").trim().slice(0, 280);
    }

    if (avatarUrl !== undefined) {
      user.avatarUrl = String(avatarUrl || "").trim();
    }

    if (email !== undefined) {
      const nextEmail = String(email || "").trim().toLowerCase();
      if (!nextEmail) {
        return res.status(400).json({ message: "email cannot be empty." });
      }
      if (nextEmail !== user.email) {
        const taken = await User.findOne({ email: nextEmail });
        if (taken) {
          return res.status(409).json({ message: "Email already exists." });
        }
        user.email = nextEmail;
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

module.exports = { register, login, me, updateMe };
