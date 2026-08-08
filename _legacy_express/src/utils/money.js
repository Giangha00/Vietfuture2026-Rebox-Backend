/**
 * Format USD amounts with "." thousand separators.
 * e.g. 12 → "$12", 1234 → "$1.234", 12.5 → "$12,5"
 */
const MAX_PRODUCT_PRICE_DIGITS = 11;
const MAX_PRODUCT_PRICE = 99_999_999_999; // 11 digits

function formatIntWithDots(intDigits) {
  const digits = String(intDigits || "0").replace(/\D/g, "") || "0";
  return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
}

function formatMoney(amount) {
  const value = Number(amount);
  const safe = Number.isFinite(value) ? value : 0;
  const negative = safe < 0;
  const [intPart, decPart] = Math.abs(safe).toFixed(2).split(".");
  const intFormatted = formatIntWithDots(intPart);
  const trimmedDec = decPart.replace(/0+$/, "");
  const body = trimmedDec ? `${intFormatted},${trimmedDec}` : intFormatted;
  return `${negative ? "-" : ""}$${body}`;
}

function assertProductPrice(price) {
  const digits = String(price ?? "").replace(/\D/g, "");
  if (digits.length > MAX_PRODUCT_PRICE_DIGITS) {
    return {
      ok: false,
      message: `price can have at most ${MAX_PRODUCT_PRICE_DIGITS} digits.`,
    };
  }

  const value = Number(price);
  if (!Number.isFinite(value) || value <= 0) {
    return { ok: false, message: "price must be a number greater than 0." };
  }
  if (value > MAX_PRODUCT_PRICE) {
    return {
      ok: false,
      message: `price must be at most ${formatMoney(MAX_PRODUCT_PRICE)}.`,
    };
  }
  return { ok: true, value };
}

module.exports = {
  formatMoney,
  assertProductPrice,
  MAX_PRODUCT_PRICE,
  MAX_PRODUCT_PRICE_DIGITS,
};
