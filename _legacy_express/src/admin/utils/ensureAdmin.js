const User = require("../../models/User");
const Product = require("../../models/Product");

async function ensureAdminUser() {
  const email = (process.env.ADMIN_EMAIL || "admin@rebox.com").toLowerCase();
  const password = process.env.ADMIN_PASSWORD || "Admin@123";
  const fullName = process.env.ADMIN_NAME || "ReBox Admin";

  // Backfill older products so existing listings stay visible.
  await Product.updateMany(
    { moderationStatus: { $exists: false } },
    { $set: { moderationStatus: "approved", rejectionReason: "" } },
  );

  // Backfill older accounts created before email verification existed.
  await User.updateMany(
    { emailVerified: { $exists: false } },
    { $set: { emailVerified: true, emailVerifiedAt: new Date() } },
  );

  const existing = await User.findOne({ email });
  if (existing) {
    let dirty = false;
    if (existing.role !== "admin") {
      existing.role = "admin";
      dirty = true;
      console.log(`Promoted ${email} to admin.`);
    }
    if (!existing.emailVerified) {
      existing.emailVerified = true;
      existing.emailVerifiedAt = existing.emailVerifiedAt || new Date();
      dirty = true;
    }
    const phoneDigits = String(existing.phone || "").replace(/\D/g, "");
    if (!/^\d{10}$/.test(phoneDigits)) {
      existing.phone = "0900000000";
      dirty = true;
    }
    if (dirty) await existing.save();
    return existing;
  }

  const admin = await User.create({
    fullName,
    email,
    phone: "0900000000",
    password,
    role: "admin",
    bio: "System administrator",
    emailVerified: true,
    emailVerifiedAt: new Date(),
  });
  console.log(`Created admin account: ${email}`);
  return admin;
}

module.exports = { ensureAdminUser };
