const Product = require("../models/Product");

async function getProducts(req, res) {
  try {
    const products = await Product.find()
      .populate("seller", "fullName email avatarUrl")
      .populate("category", "name slug")
      .populate("station", "city address lockerCode partnerName")
      .sort({ createdAt: -1 });

    return res.status(200).json({ products });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to fetch products.",
      error: error.message,
    });
  }
}

async function getProductById(req, res) {
  try {
    const { id } = req.params;
    const product = await Product.findById(id)
      .populate("seller", "fullName email avatarUrl")
      .populate("category", "name slug")
      .populate("station", "city address lockerCode partnerName");

    if (!product) {
      return res.status(404).json({ message: "Product not found." });
    }

    return res.status(200).json({ product });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to fetch product.",
      error: error.message,
    });
  }
}

async function createProduct(req, res) {
  try {
    const { title, description, price, condition, images, category, station } = req.body;

    if (!title || !description || !price || !category || !station) {
      return res.status(400).json({
        message: "title, description, price, category, station are required.",
      });
    }

    const product = await Product.create({
      title,
      description,
      price,
      condition,
      images: Array.isArray(images) ? images : [],
      category,
      station,
      seller: req.user._id,
    });

    const createdProduct = await Product.findById(product._id)
      .populate("seller", "fullName email avatarUrl")
      .populate("category", "name slug")
      .populate("station", "city address lockerCode partnerName");

    return res.status(201).json({
      message: "Create product successful.",
      product: createdProduct,
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to create product.",
      error: error.message,
    });
  }
}

module.exports = { getProducts, getProductById, createProduct };
