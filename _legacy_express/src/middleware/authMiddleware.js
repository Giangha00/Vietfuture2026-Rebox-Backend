const jwt = require("jsonwebtoken");
const User = require("../models/User");

function extractBearerToken(req) {
  const authHeader = req.headers.authorization || "";
  if (authHeader.startsWith("Bearer ")) {
    return authHeader.slice(7).trim();
  }
  // EventSource cannot set Authorization headers — allow query token for SSE.
  const queryToken = req.query?.access_token || req.query?.token;
  if (typeof queryToken === "string" && queryToken.trim()) {
    return queryToken.trim();
  }
  return null;
}

async function loadUserFromToken(token) {
  if (!process.env.JWT_SECRET) {
    const error = new Error("JWT_SECRET is not configured.");
    error.code = "JWT_SECRET_MISSING";
    throw error;
  }

  const decoded = jwt.verify(token, process.env.JWT_SECRET);
  if (!decoded?.sub) {
    return null;
  }

  return User.findById(decoded.sub);
}

/** Require a valid Bearer JWT. Attaches `req.user`. */
async function protect(req, res, next) {
  try {
    const token = extractBearerToken(req);
    if (!token) {
      return res.status(401).json({ message: "Unauthorized: missing token." });
    }

    const user = await loadUserFromToken(token);
    if (!user) {
      return res.status(401).json({ message: "Unauthorized: user not found." });
    }

    req.user = user;
    return next();
  } catch (error) {
    if (error.code === "JWT_SECRET_MISSING") {
      return res.status(500).json({ message: "Server auth is misconfigured." });
    }
    return res.status(401).json({ message: "Unauthorized: invalid token." });
  }
}

/** Optional Bearer JWT — sets `req.user` or null. */
async function optionalProtect(req, res, next) {
  try {
    const token = extractBearerToken(req);
    if (!token) {
      req.user = null;
      return next();
    }

    req.user = (await loadUserFromToken(token)) || null;
    return next();
  } catch {
    req.user = null;
    return next();
  }
}

/** Require `req.user.emailVerified` (use after `protect`). */
function requireVerified(req, res, next) {
  if (!req.user) {
    return res.status(401).json({ message: "Unauthorized: missing token." });
  }

  if (!req.user.emailVerified) {
    return res.status(403).json({
      message: "Please verify your email to continue.",
      needsVerification: true,
      email: req.user.email,
    });
  }

  return next();
}

/**
 * Role-based authorization (use after `protect`).
 * Example: `authorize("admin")` or `authorize("user", "admin")`
 */
function authorize(...roles) {
  return (req, res, next) => {
    if (!req.user) {
      return res.status(401).json({ message: "Unauthorized: missing token." });
    }

    if (roles.length > 0 && !roles.includes(req.user.role)) {
      return res.status(403).json({
        message: "Forbidden: insufficient permissions.",
        requiredRoles: roles,
      });
    }

    return next();
  };
}

module.exports = {
  protect,
  optionalProtect,
  requireVerified,
  authorize,
};
