const PHONE_DIGITS_RE = /^\d{10}$/;

const PASSWORD_HINT =
  "Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.";

const PHONE_HINT = "Phone number must be exactly 10 digits.";

function digitsOnly(value) {
  return String(value || "").replace(/\D/g, "");
}

function normalizePhone(value) {
  return digitsOnly(value);
}

function isValidPhone(value, { required = true } = {}) {
  const phone = normalizePhone(value);
  if (!phone) return !required;
  return PHONE_DIGITS_RE.test(phone);
}

function validatePhone(value, { required = true, field = "Phone number" } = {}) {
  const phone = normalizePhone(value);
  if (!phone) {
    if (required) {
      return { ok: false, message: `${field} is required (10 digits).`, phone: "" };
    }
    return { ok: true, message: "", phone: "" };
  }
  if (!PHONE_DIGITS_RE.test(phone)) {
    return { ok: false, message: PHONE_HINT, phone };
  }
  return { ok: true, message: "", phone };
}

function isValidPassword(value) {
  const password = String(value || "");
  if (password.length < 8) return false;
  if (!/[A-Z]/.test(password)) return false;
  if (!/[a-z]/.test(password)) return false;
  if (!/[0-9]/.test(password)) return false;
  if (!/[^A-Za-z0-9]/.test(password)) return false;
  return true;
}

function validatePassword(value, { field = "Password" } = {}) {
  const password = String(value || "");
  if (!password) {
    return { ok: false, message: `${field} is required.` };
  }
  if (!isValidPassword(password)) {
    return { ok: false, message: PASSWORD_HINT };
  }
  return { ok: true, message: "" };
}

function isValidEmail(value) {
  const email = String(value || "").trim();
  if (!email) return false;
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function validateEmail(value, { required = true, field = "Email" } = {}) {
  const email = String(value || "").trim();
  if (!email) {
    if (required) return { ok: false, message: `${field} is required.`, email: "" };
    return { ok: true, message: "", email: "" };
  }
  if (!isValidEmail(email)) {
    return { ok: false, message: "Please enter a valid email address.", email };
  }
  return { ok: true, message: "", email };
}

module.exports = {
  PHONE_HINT,
  PASSWORD_HINT,
  normalizePhone,
  isValidPhone,
  validatePhone,
  isValidPassword,
  validatePassword,
  isValidEmail,
  validateEmail,
};
