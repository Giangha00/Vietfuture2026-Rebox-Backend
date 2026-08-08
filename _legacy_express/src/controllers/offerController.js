const Offer = require("../models/Offer");
const Product = require("../models/Product");
const { OFFER_PERCENTS, calcOfferPrice } = require("../models/Offer");
const { createAndPushNotification } = require("../services/notificationService");
const { expireIfNeeded } = require("../services/offerService");
const { formatMoney } = require("../utils/money");

const OFFER_TTL_HOURS = Number(process.env.OFFER_TTL_HOURS || 48);

function sanitizeOffer(offer) {
  return {
    id: offer._id,
    product: offer.product,
    buyer: offer.buyer,
    seller: offer.seller,
    listPrice: offer.listPrice,
    discountPercent: offer.discountPercent,
    offerPrice: offer.offerPrice,
    message: offer.message || "",
    status: offer.status,
    expiresAt: offer.expiresAt,
    acceptedAt: offer.acceptedAt,
    rejectedAt: offer.rejectedAt,
    cancelledAt: offer.cancelledAt,
    order: offer.order,
    createdAt: offer.createdAt,
    updatedAt: offer.updatedAt,
  };
}

const POPULATE = [
  { path: "product", select: "title price images status moderationStatus seller" },
  { path: "buyer", select: "fullName email phone avatarUrl" },
  { path: "seller", select: "fullName email phone avatarUrl" },
];

async function populateOffer(query) {
  return query.populate(POPULATE[0]).populate(POPULATE[1]).populate(POPULATE[2]);
}

async function createOffer(req, res) {
  try {
    const productId = req.body?.productId;
    const discountPercent = Number(req.body?.discountPercent);
    const message = String(req.body?.message || "").trim().slice(0, 300);

    if (!productId) {
      return res.status(400).json({ message: "productId is required." });
    }
    if (!OFFER_PERCENTS.includes(discountPercent)) {
      return res.status(400).json({
        message: `discountPercent must be one of: ${OFFER_PERCENTS.join(", ")}.`,
      });
    }

    const product = await Product.findById(productId);
    if (!product) {
      return res.status(400).json({ message: "Product is not available for offers." });
    }
    if (product.moderationStatus !== "approved") {
      return res.status(400).json({
        message: "This listing is still pending admin approval, so offers are disabled.",
      });
    }
    if (product.status !== "active") {
      return res.status(400).json({
        message: `This product is ${product.status}, so offers are not available.`,
      });
    }
    if (product.acceptsOffers === false) {
      return res.status(400).json({
        message: "The seller is not accepting offers on this listing.",
      });
    }

    if (String(product.seller) === String(req.user._id)) {
      return res.status(400).json({ message: "You cannot offer on your own listing." });
    }

    const existingPending = await Offer.findOne({
      product: product._id,
      buyer: req.user._id,
      status: "pending",
    });
    if (existingPending) {
      await expireIfNeeded(existingPending);
      if (existingPending.status === "pending") {
        return res.status(409).json({
          message: "You already have a pending offer on this product. Cancel it first.",
          offer: sanitizeOffer(existingPending),
        });
      }
    }

    const existingAccepted = await Offer.findOne({
      product: product._id,
      buyer: req.user._id,
      status: "accepted",
      order: null,
    });
    if (existingAccepted) {
      return res.status(409).json({
        message: "You already have an accepted offer. Complete checkout or cancel it.",
        offer: sanitizeOffer(existingAccepted),
      });
    }

    const listPrice = Number(product.price);
    const offerPrice = calcOfferPrice(listPrice, discountPercent);
    const expiresAt = new Date(Date.now() + OFFER_TTL_HOURS * 60 * 60 * 1000);

    const offer = await Offer.create({
      product: product._id,
      buyer: req.user._id,
      seller: product.seller,
      listPrice,
      discountPercent,
      offerPrice,
      message,
      status: "pending",
      expiresAt,
    });

    createAndPushNotification({
      userId: product.seller,
      title: "New price offer",
      body: `${req.user.fullName || "A buyer"} offered ${formatMoney(offerPrice)} (−${discountPercent}%) on "${product.title}".`,
      type: "offer",
      link: "/offers/selling",
      data: { offerId: String(offer._id), productId: String(product._id) },
    }).catch(() => {});

    const populated = await populateOffer(Offer.findById(offer._id));
    return res.status(201).json({
      message: "Offer sent. Waiting for seller response.",
      offer: sanitizeOffer(populated),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to create offer.",
      error: error.message,
    });
  }
}

async function getMyOffers(req, res) {
  try {
    const offers = await populateOffer(
      Offer.find({ buyer: req.user._id }).sort({ createdAt: -1 }),
    );
    for (const offer of offers) {
      await expireIfNeeded(offer);
    }
    return res.status(200).json({
      offers: offers.map(sanitizeOffer),
      allowedPercents: OFFER_PERCENTS,
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to fetch offers.",
      error: error.message,
    });
  }
}

async function getSellingOffers(req, res) {
  try {
    const offers = await populateOffer(
      Offer.find({ seller: req.user._id }).sort({ createdAt: -1 }),
    );
    for (const offer of offers) {
      await expireIfNeeded(offer);
    }
    return res.status(200).json({
      offers: offers.map(sanitizeOffer),
      allowedPercents: OFFER_PERCENTS,
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to fetch selling offers.",
      error: error.message,
    });
  }
}

async function getOfferById(req, res) {
  try {
    const offer = await populateOffer(Offer.findById(req.params.id));
    if (!offer) return res.status(404).json({ message: "Offer not found." });
    await expireIfNeeded(offer);

    const uid = String(req.user._id);
    const isParty =
      String(offer.buyer._id || offer.buyer) === uid ||
      String(offer.seller._id || offer.seller) === uid ||
      req.user.role === "admin";
    if (!isParty) return res.status(403).json({ message: "Forbidden." });

    return res.status(200).json({ offer: sanitizeOffer(offer) });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to fetch offer.",
      error: error.message,
    });
  }
}

async function acceptOffer(req, res) {
  try {
    const offer = await Offer.findById(req.params.id);
    if (!offer) return res.status(404).json({ message: "Offer not found." });
    await expireIfNeeded(offer);

    if (String(offer.seller) !== String(req.user._id) && req.user.role !== "admin") {
      return res.status(403).json({ message: "Only the seller can accept." });
    }
    if (offer.status !== "pending") {
      return res.status(400).json({
        message: `Offer cannot be accepted in status "${offer.status}".`,
      });
    }

    const product = await Product.findById(offer.product);
    if (!product || product.moderationStatus !== "approved" || product.status !== "active") {
      return res.status(400).json({
        message: "Product is no longer available.",
      });
    }

    offer.status = "accepted";
    offer.acceptedAt = new Date();
    await offer.save();

    // Do not reserve the listing here — other buyers can still offer/buy
    // until someone completes checkout (order creation locks the product).

    createAndPushNotification({
      userId: offer.buyer,
      title: "Offer accepted",
      body: `Your −${offer.discountPercent}% offer (${formatMoney(offer.offerPrice)}) was accepted. Checkout soon — the item stays listed until paid.`,
      type: "offer",
      link: `/order?offerId=${offer._id}`,
      data: { offerId: String(offer._id), productId: String(product._id) },
    }).catch(() => {});

    const populated = await populateOffer(Offer.findById(offer._id));
    return res.status(200).json({
      message:
        "Offer accepted. Buyer can checkout at the offered price. Listing stays open until someone pays.",
      offer: sanitizeOffer(populated),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to accept offer.",
      error: error.message,
    });
  }
}

async function rejectOffer(req, res) {
  try {
    const offer = await Offer.findById(req.params.id);
    if (!offer) return res.status(404).json({ message: "Offer not found." });
    await expireIfNeeded(offer);

    if (String(offer.seller) !== String(req.user._id) && req.user.role !== "admin") {
      return res.status(403).json({ message: "Only the seller can reject." });
    }
    if (offer.status !== "pending") {
      return res.status(400).json({
        message: `Offer cannot be rejected in status "${offer.status}".`,
      });
    }

    const reason = String(req.body?.reason || "").trim();
    offer.status = "rejected";
    offer.rejectedAt = new Date();
    if (reason) offer.message = reason.slice(0, 300);
    await offer.save();

    createAndPushNotification({
      userId: offer.buyer,
      title: "Offer rejected",
      body: reason
        ? `Your offer was rejected: ${reason}`
        : "Your offer was rejected by the seller.",
      type: "offer",
      link: `/products/${offer.product}`,
      data: { offerId: String(offer._id) },
    }).catch(() => {});

    const populated = await populateOffer(Offer.findById(offer._id));
    return res.status(200).json({
      message: "Offer rejected.",
      offer: sanitizeOffer(populated),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to reject offer.",
      error: error.message,
    });
  }
}

async function cancelOffer(req, res) {
  try {
    const offer = await Offer.findById(req.params.id);
    if (!offer) return res.status(404).json({ message: "Offer not found." });
    await expireIfNeeded(offer);

    const isBuyer = String(offer.buyer) === String(req.user._id);
    if (!isBuyer && req.user.role !== "admin") {
      return res.status(403).json({ message: "Only the buyer can cancel." });
    }

    if (!["pending", "accepted"].includes(offer.status)) {
      return res.status(400).json({
        message: `Offer cannot be cancelled in status "${offer.status}".`,
      });
    }

    const wasAccepted = offer.status === "accepted";
    offer.status = "cancelled";
    offer.cancelledAt = new Date();
    await offer.save();

    if (wasAccepted && !offer.order) {
      await Product.updateOne(
        { _id: offer.product, status: "reserved" },
        { $set: { status: "active" } },
      );
    }

    createAndPushNotification({
      userId: offer.seller,
      title: "Offer cancelled",
      body: "The buyer cancelled their offer.",
      type: "offer",
      link: "/offers/selling",
      data: { offerId: String(offer._id) },
    }).catch(() => {});

    const populated = await populateOffer(Offer.findById(offer._id));
    return res.status(200).json({
      message: "Offer cancelled.",
      offer: sanitizeOffer(populated),
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to cancel offer.",
      error: error.message,
    });
  }
}

async function getOfferOptions(_req, res) {
  return res.status(200).json({
    allowedPercents: OFFER_PERCENTS,
    ttlHours: OFFER_TTL_HOURS,
  });
}

module.exports = {
  createOffer,
  getMyOffers,
  getSellingOffers,
  getOfferById,
  acceptOffer,
  rejectOffer,
  cancelOffer,
  getOfferOptions,
  sanitizeOffer,
  OFFER_PERCENTS,
  calcOfferPrice,
};
