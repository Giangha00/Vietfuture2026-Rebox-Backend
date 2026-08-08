const User = require("../../models/User");
const { signToken } = require("../../utils/jwt");
const { validateEmail } = require("../../utils/validators");

function showLogin(req, res) {
  res.render("admin/login", {
    title: "Admin Login",
    error: null,
    layout: false,
  });
}

async function login(req, res) {
  try {
    const emailCheck = validateEmail(req.body.email);
    const password = String(req.body.password || "");

    if (!emailCheck.ok) {
      return res.status(400).render("admin/login", {
        title: "Admin Login",
        error: emailCheck.message,
        layout: false,
      });
    }
    if (!password) {
      return res.status(400).render("admin/login", {
        title: "Admin Login",
        error: "Email and password are required.",
        layout: false,
      });
    }

    const email = emailCheck.email.toLowerCase();
    const user = await User.findOne({ email }).select("+password");
    if (!user || user.role !== "admin") {
      return res.status(401).render("admin/login", {
        title: "Admin Login",
        error: "Invalid admin credentials.",
        layout: false,
      });
    }

    const matched = await user.comparePassword(password);
    if (!matched) {
      return res.status(401).render("admin/login", {
        title: "Admin Login",
        error: "Invalid admin credentials.",
        layout: false,
      });
    }

    const token = signToken(user._id.toString());
    res.cookie("admin_token", token, {
      httpOnly: true,
      sameSite: "lax",
      maxAge: 7 * 24 * 60 * 60 * 1000,
    });
    return res.redirect("/admin");
  } catch (error) {
    return res.status(500).render("admin/login", {
      title: "Admin Login",
      error: error.message,
      layout: false,
    });
  }
}

function logout(req, res) {
  res.clearCookie("admin_token");
  return res.redirect("/admin/login");
}

module.exports = { showLogin, login, logout };
