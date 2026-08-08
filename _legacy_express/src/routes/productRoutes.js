const express = require("express");
const {
  getProducts,
  getMyProducts,
  getProductById,
  createProduct,
  updateProduct,
  deleteProduct,
} = require("../controllers/productController");
const {
  protect,
  optionalProtect,
  requireVerified,
} = require("../middleware/authMiddleware");

const router = express.Router();

router.get("/", getProducts);
router.get("/mine", protect, requireVerified, getMyProducts);
router.get("/:id", optionalProtect, getProductById);
router.post("/", protect, requireVerified, createProduct);
router.patch("/:id", protect, requireVerified, updateProduct);
router.delete("/:id", protect, requireVerified, deleteProduct);

module.exports = router;
