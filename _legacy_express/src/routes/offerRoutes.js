const express = require("express");
const { protect, requireVerified } = require("../middleware/authMiddleware");
const {
  createOffer,
  getMyOffers,
  getSellingOffers,
  getOfferById,
  acceptOffer,
  rejectOffer,
  cancelOffer,
  getOfferOptions,
} = require("../controllers/offerController");

const router = express.Router();

router.get("/options", getOfferOptions);

router.use(protect, requireVerified);

router.get("/mine", getMyOffers);
router.get("/selling", getSellingOffers);
router.get("/:id", getOfferById);
router.post("/", createOffer);
router.post("/:id/accept", acceptOffer);
router.post("/:id/reject", rejectOffer);
router.post("/:id/cancel", cancelOffer);

module.exports = router;
