const jwt = require("jsonwebtoken");
const User = require("../../models/User");

async function requireAdmin(req, res, next) {
  try {
    const token = req.cookies?.admin_token;
    if (!token) {
      return res.redirect("/admin/login");
    }

    const decoded = jwt.verify(token, process.env.JWT_SECRET);
    const user = await User.findById(decoded.sub);

    if (!user || user.role !== "admin") {
      res.clearCookie("admin_token");
      return res.redirect("/admin/login");
    }

    req.admin = user;
    res.locals.admin = {
      id: user._id,
      fullName: user.fullName,
      email: user.email,
    };
    res.locals.currentPath = req.path;
    return next();
  } catch {
    res.clearCookie("admin_token");
    return res.redirect("/admin/login");
  }
}

async function redirectIfAuthed(req, res, next) {
  const token = req.cookies?.admin_token;
  if (!token) return next();
  try {
    const decoded = jwt.verify(token, process.env.JWT_SECRET);
    const user = await User.findById(decoded.sub);
    if (user?.role === "admin") {
      return res.redirect("/admin");
    }
    res.clearCookie("admin_token");
    return next();
  } catch {
    res.clearCookie("admin_token");
    return next();
  }
}

module.exports = { requireAdmin, redirectIfAuthed };
