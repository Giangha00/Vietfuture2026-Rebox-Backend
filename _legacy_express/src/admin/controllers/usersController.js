const User = require("../../models/User");
const { renderAdmin } = require("../utils/render");
const {
  validatePhone,
  validatePassword,
  validateEmail,
} = require("../../utils/validators");

async function list(req, res) {
  const q = String(req.query.q || "").trim();
  const filter = q
    ? {
        $or: [
          { fullName: new RegExp(q, "i") },
          { email: new RegExp(q, "i") },
          { phone: new RegExp(q, "i") },
        ],
      }
    : {};

  const users = await User.find(filter).sort({ createdAt: -1 });
  return renderAdmin(res, "users/index", {
    title: "Users",
    users,
    q,
    success: req.query.success || null,
    error: req.query.error || null,
  });
}

async function showCreate(req, res) {
  return renderAdmin(res, "users/form", {
    title: "Create User",
    user: null,
    error: null,
  });
}

function validateUserPayload(body, { requirePassword }) {
  const fullName = String(body.fullName || "").trim();
  if (!fullName || fullName.length < 2) {
    return { ok: false, message: "Full name is required (at least 2 characters)." };
  }

  const emailCheck = validateEmail(body.email);
  if (!emailCheck.ok) return emailCheck;

  const phoneCheck = validatePhone(body.phone, { required: true });
  if (!phoneCheck.ok) return phoneCheck;

  if (requirePassword || body.password) {
    const passwordCheck = validatePassword(body.password);
    if (!passwordCheck.ok) return passwordCheck;
  }

  return {
    ok: true,
    fullName,
    email: emailCheck.email,
    phone: phoneCheck.phone,
  };
}

async function create(req, res) {
  try {
    const checked = validateUserPayload(req.body, { requirePassword: true });
    if (!checked.ok) {
      res.status(400);
      return renderAdmin(res, "users/form", {
        title: "Create User",
        user: req.body,
        error: checked.message,
      });
    }

    const { role, bio } = req.body;
    await User.create({
      fullName: checked.fullName,
      email: checked.email,
      phone: checked.phone,
      password: req.body.password,
      role: ["admin", "shipper", "user"].includes(role) ? role : "user",
      bio: bio || "",
      emailVerified: true,
      emailVerifiedAt: new Date(),
    });
    return res.redirect("/admin/users?success=User created");
  } catch (error) {
    res.status(400);
    return renderAdmin(res, "users/form", {
      title: "Create User",
      user: req.body,
      error: error.message,
    });
  }
}

async function showEdit(req, res) {
  const user = await User.findById(req.params.id);
  if (!user) return res.redirect("/admin/users?error=User not found");
  return renderAdmin(res, "users/form", {
    title: "Edit User",
    user,
    error: null,
  });
}

async function update(req, res) {
  try {
    const user = await User.findById(req.params.id).select("+password");
    if (!user) return res.redirect("/admin/users?error=User not found");

    const checked = validateUserPayload(req.body, {
      requirePassword: Boolean(req.body.password),
    });
    if (!checked.ok) {
      res.status(400);
      return renderAdmin(res, "users/form", {
        title: "Edit User",
        user: { ...req.body, _id: req.params.id },
        error: checked.message,
      });
    }

    user.fullName = checked.fullName;
    user.email = checked.email;
    user.phone = checked.phone;
    user.role = ["admin", "shipper", "user"].includes(req.body.role)
      ? req.body.role
      : "user";
    user.bio = req.body.bio || "";
    if (req.body.password) {
      user.password = req.body.password;
    }
    await user.save();
    return res.redirect("/admin/users?success=User updated");
  } catch (error) {
    res.status(400);
    return renderAdmin(res, "users/form", {
      title: "Edit User",
      user: { ...req.body, _id: req.params.id },
      error: error.message,
    });
  }
}

async function destroy(req, res) {
  try {
    if (String(req.admin._id) === String(req.params.id)) {
      return res.redirect("/admin/users?error=Cannot delete your own admin account");
    }
    await User.findByIdAndDelete(req.params.id);
    return res.redirect("/admin/users?success=User deleted");
  } catch (error) {
    return res.redirect(`/admin/users?error=${encodeURIComponent(error.message)}`);
  }
}

module.exports = { list, showCreate, create, showEdit, update, destroy };
