const { initializeApp, getApps, cert } = require("firebase-admin/app");
const { getMessaging } = require("firebase-admin/messaging");

let initialized = false;
let ready = false;

function getServiceAccount() {
  if (process.env.FIREBASE_SERVICE_ACCOUNT_JSON) {
    try {
      return JSON.parse(process.env.FIREBASE_SERVICE_ACCOUNT_JSON);
    } catch (error) {
      console.error("Invalid FIREBASE_SERVICE_ACCOUNT_JSON:", error.message);
      return null;
    }
  }

  if (
    process.env.FIREBASE_PROJECT_ID &&
    process.env.FIREBASE_CLIENT_EMAIL &&
    process.env.FIREBASE_PRIVATE_KEY
  ) {
    return {
      projectId: process.env.FIREBASE_PROJECT_ID,
      clientEmail: process.env.FIREBASE_CLIENT_EMAIL,
      privateKey: process.env.FIREBASE_PRIVATE_KEY.replace(/\\n/g, "\n"),
    };
  }

  return null;
}

function initFirebaseAdmin() {
  if (initialized) return ready;

  const serviceAccount = getServiceAccount();
  if (!serviceAccount) {
    console.warn(
      "Firebase Admin is not configured. Push delivery will be skipped (in-app notifications still work).",
    );
    initialized = true;
    ready = false;
    return false;
  }

  try {
    if (!getApps().length) {
      initializeApp({
        credential: cert(serviceAccount),
      });
    }
    initialized = true;
    ready = true;
    return true;
  } catch (error) {
    console.error("Failed to initialize Firebase Admin:", error.message);
    initialized = true;
    ready = false;
    return false;
  }
}

function isFirebaseReady() {
  return initFirebaseAdmin();
}

async function sendFcmToTokens(tokens, { title, body, data = {}, link = "" }) {
  if (!tokens?.length) {
    return { successCount: 0, failureCount: 0, invalidTokens: [] };
  }

  if (!isFirebaseReady()) {
    return {
      successCount: 0,
      failureCount: tokens.length,
      invalidTokens: [],
      skipped: true,
    };
  }

  const payloadData = {
    ...Object.fromEntries(
      Object.entries(data).map(([key, value]) => [key, String(value ?? "")]),
    ),
  };
  if (link) payloadData.link = link;

  const response = await getMessaging().sendEachForMulticast({
    tokens,
    notification: { title, body },
    data: payloadData,
    webpush: {
      fcmOptions: link ? { link } : undefined,
      notification: {
        title,
        body,
        icon: "/icon-192.svg",
      },
    },
  });

  const invalidTokens = [];
  response.responses.forEach((result, index) => {
    if (result.success) return;
    const code = result.error?.code || "";
    if (
      code.includes("registration-token-not-registered") ||
      code.includes("invalid-registration-token")
    ) {
      invalidTokens.push(tokens[index]);
    }
  });

  return {
    successCount: response.successCount,
    failureCount: response.failureCount,
    invalidTokens,
  };
}

module.exports = {
  initFirebaseAdmin,
  isFirebaseReady,
  sendFcmToTokens,
};
