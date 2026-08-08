const nodemailer = require("nodemailer");

let transporter = null;

function isEmailConfigured() {
  return Boolean(
    process.env.GOOGLE_EMAIL &&
      process.env.GOOGLE_APP_PASSWORD &&
      String(process.env.GOOGLE_APP_PASSWORD).trim(),
  );
}

function getTransporter() {
  if (!isEmailConfigured()) return null;
  if (transporter) return transporter;

  transporter = nodemailer.createTransport({
    service: "gmail",
    auth: {
      user: process.env.GOOGLE_EMAIL,
      pass: String(process.env.GOOGLE_APP_PASSWORD).replace(/\s+/g, ""),
    },
  });

  return transporter;
}

function wrapEmailHtml({ title, intro, code, outro }) {
  return `
  <div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#1f1a17;background:#fff8f5;border:1px solid #f0ddd7;border-radius:16px;">
    <p style="margin:0 0 8px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#9b4a3c;font-weight:700;">ReBox</p>
    <h1 style="margin:0 0 12px;font-size:22px;">${title}</h1>
    <p style="margin:0 0 20px;line-height:1.5;color:#6b5e58;">${intro}</p>
    <div style="display:inline-block;padding:14px 22px;border-radius:12px;background:#9b4a3c;color:#ffffff;font-size:28px;font-weight:700;letter-spacing:0.35em;">
      ${code}
    </div>
    <p style="margin:20px 0 0;line-height:1.5;color:#6b5e58;font-size:14px;">${outro}</p>
    <p style="margin:24px 0 0;font-size:12px;color:#9a8c85;">If you did not request this, you can ignore this email.</p>
  </div>`;
}

async function sendMail({ to, subject, html, text }) {
  const mailer = getTransporter();
  const from = process.env.GOOGLE_EMAIL || "noreply@rebox.local";

  if (!mailer) {
    // eslint-disable-next-line no-console
    console.warn(
      `[email:dev] SMTP not configured. Would send to ${to}: ${subject}\n${text || ""}`,
    );
    return { queued: false, preview: true };
  }

  await mailer.sendMail({
    from: `"ReBox" <${from}>`,
    to,
    subject,
    html,
    text,
  });

  return { queued: true, preview: false };
}

async function sendVerificationOtpEmail({ to, code, fullName = "" }) {
  const subject = "Verify your ReBox account";
  const intro = fullName
    ? `Hi ${fullName}, use this one-time code to verify your email.`
    : "Use this one-time code to verify your email.";
  const text = `${subject}\n\nYour verification code is: ${code}\nIt expires in 10 minutes.`;
  const html = wrapEmailHtml({
    title: "Verify your email",
    intro,
    code,
    outro: "This code expires in 10 minutes.",
  });
  return sendMail({ to, subject, html, text });
}

async function sendPasswordResetOtpEmail({ to, code, fullName = "" }) {
  const subject = "Reset your ReBox password";
  const intro = fullName
    ? `Hi ${fullName}, use this one-time code to reset your password.`
    : "Use this one-time code to reset your password.";
  const text = `${subject}\n\nYour password reset code is: ${code}\nIt expires in 10 minutes.`;
  const html = wrapEmailHtml({
    title: "Password reset code",
    intro,
    code,
    outro: "This code expires in 10 minutes. Enter it on the forgot-password page to continue.",
  });
  return sendMail({ to, subject, html, text });
}

module.exports = {
  isEmailConfigured,
  sendMail,
  sendVerificationOtpEmail,
  sendPasswordResetOtpEmail,
};
