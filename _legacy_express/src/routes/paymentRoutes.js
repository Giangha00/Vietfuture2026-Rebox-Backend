const express = require("express");
const {
  paypalReturn,
  paypalCancel,
} = require("../controllers/orderController");
const { isPaypalConfigured, PLATFORM_FEE_PERCENT } = require("../services/paypalService");

const router = express.Router();

router.get("/paypal/return", paypalReturn);
router.get("/paypal/cancel", paypalCancel);

router.get("/paypal/status", (_req, res) => {
  return res.status(200).json({
    configured: isPaypalConfigured(),
    mode: process.env.PAYPAL_MODE || "sandbox",
    platformFeePercent: PLATFORM_FEE_PERCENT,
    clientId: process.env.PAYPAL_CLIENT_ID
      ? `${String(process.env.PAYPAL_CLIENT_ID).slice(0, 8)}…`
      : null,
  });
});

module.exports = router;
