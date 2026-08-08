const PLATFORM_FEE_PERCENT = Number(process.env.PLATFORM_FEE_PERCENT || 10);

function getPaypalBaseUrl() {
  const mode = String(process.env.PAYPAL_MODE || "sandbox").toLowerCase();
  return mode === "live"
    ? "https://api-m.paypal.com"
    : "https://api-m.sandbox.paypal.com";
}

function isPaypalConfigured() {
  return Boolean(
    process.env.PAYPAL_CLIENT_ID && process.env.PAYPAL_CLIENT_SECRET,
  );
}

function calcFees(totalAmount) {
  const total = Number(totalAmount) || 0;
  const platformFee = Math.round(total * (PLATFORM_FEE_PERCENT / 100) * 100) / 100;
  const sellerPayout = Math.round((total - platformFee) * 100) / 100;
  return { platformFee, sellerPayout, feePercent: PLATFORM_FEE_PERCENT };
}

let cachedToken = null;
let cachedTokenExpiresAt = 0;

async function getAccessToken() {
  if (!isPaypalConfigured()) {
    const error = new Error(
      "PayPal is not configured. Set PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET.",
    );
    error.code = "PAYPAL_NOT_CONFIGURED";
    throw error;
  }

  if (cachedToken && Date.now() < cachedTokenExpiresAt - 30_000) {
    return cachedToken;
  }

  const auth = Buffer.from(
    `${process.env.PAYPAL_CLIENT_ID}:${process.env.PAYPAL_CLIENT_SECRET}`,
  ).toString("base64");

  const res = await fetch(`${getPaypalBaseUrl()}/v1/oauth2/token`, {
    method: "POST",
    headers: {
      Authorization: `Basic ${auth}`,
      "Content-Type": "application/x-www-form-urlencoded",
    },
    body: "grant_type=client_credentials",
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const error = new Error(data?.error_description || "PayPal auth failed.");
    error.code = "PAYPAL_AUTH_FAILED";
    error.details = data;
    throw error;
  }

  cachedToken = data.access_token;
  cachedTokenExpiresAt = Date.now() + Number(data.expires_in || 3600) * 1000;
  return cachedToken;
}

async function paypalRequest(path, { method = "GET", body } = {}) {
  const token = await getAccessToken();
  const res = await fetch(`${getPaypalBaseUrl()}${path}`, {
    method,
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Prefer: "return=representation",
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const message =
      data?.message ||
      data?.details?.[0]?.description ||
      `PayPal request failed (${res.status})`;
    const error = new Error(message);
    error.code = "PAYPAL_REQUEST_FAILED";
    error.status = res.status;
    error.details = data;
    throw error;
  }

  return data;
}

async function createPaypalOrder({
  orderId,
  totalAmount,
  currency = "USD",
  description = "ReBox order",
  returnUrl,
  cancelUrl,
}) {
  const amount = Number(totalAmount).toFixed(2);
  const payload = {
    intent: "CAPTURE",
    purchase_units: [
      {
        reference_id: String(orderId),
        description: String(description).slice(0, 127),
        custom_id: String(orderId),
        amount: {
          currency_code: currency,
          value: amount,
        },
      },
    ],
    application_context: {
      brand_name: "ReBox",
      landing_page: "NO_PREFERENCE",
      user_action: "PAY_NOW",
      return_url: returnUrl,
      cancel_url: cancelUrl,
    },
  };

  return paypalRequest("/v2/checkout/orders", {
    method: "POST",
    body: payload,
  });
}

async function capturePaypalOrder(paypalOrderId) {
  return paypalRequest(`/v2/checkout/orders/${paypalOrderId}/capture`, {
    method: "POST",
    body: {},
  });
}

async function getPaypalOrder(paypalOrderId) {
  return paypalRequest(`/v2/checkout/orders/${paypalOrderId}`);
}

function extractApproveUrl(paypalOrder) {
  const links = Array.isArray(paypalOrder?.links) ? paypalOrder.links : [];
  const approve = links.find((link) => link.rel === "approve");
  return approve?.href || null;
}

function extractCaptureId(captureResult) {
  const units = captureResult?.purchase_units || [];
  const captures = units[0]?.payments?.captures || [];
  return captures[0]?.id || "";
}

module.exports = {
  PLATFORM_FEE_PERCENT,
  isPaypalConfigured,
  calcFees,
  createPaypalOrder,
  capturePaypalOrder,
  getPaypalOrder,
  extractApproveUrl,
  extractCaptureId,
};
