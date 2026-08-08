const Product = require("../models/Product");
const { createAndPushNotification } = require("../services/notificationService");
const { assertProductPrice } = require("../utils/money");

function populateProduct(query) {
  return query
    .populate("seller", "fullName email avatarUrl")
    .populate("category", "name slug")
    .populate("station", "city address lockerCode partnerName");
}

function isOwner(product, user) {
  return String(product.seller?._id || product.seller) === String(user._id);
}

async function getProducts(req, res) {
  try {
    const products = await populateProduct(
      Product.find({
        moderationStatus: "approved",
        status: "active",
      }),
    ).sort({ createdAt: -1 });

    return res.status(200).json({ products });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to fetch products.",
      error: error.message,
    });
  }
}

async function getMyProducts(req, res) {
  try {
    const products = await populateProduct(
      Product.find({ seller: req.user._id }),
    ).sort({ createdAt: -1 });

    return res.status(200).json({ products });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to fetch your products.",
      error: error.message,
    });
  }
}

async function getProductById(req, res) {
  try {
    const { id } = req.params;
    const product = await populateProduct(Product.findById(id));

    if (!product) {
      return res.status(404).json({ message: "Product not found." });
    }

    const approved = product.moderationStatus === "approved";
    const owner =
      req.user && isOwner(product, req.user);

    if (!approved && !owner) {
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
    const { title, description, price, condition, images, category, station, acceptsOffers } =
      req.body;

    if (!title || !description || price === undefined || price === null || !category || !station) {
      return res.status(400).json({
        message: "title, description, price, category, station are required.",
      });
    }

    const cleanTitle = String(title).trim();
    const cleanDescription = String(description).trim();
    if (cleanTitle.length < 3) {
      return res.status(400).json({ message: "Title must be at least 3 characters." });
    }
    if (cleanDescription.length < 10) {
      return res.status(400).json({
        message: "Description must be at least 10 characters.",
      });
    }

    const priceCheck = assertProductPrice(price);
    if (!priceCheck.ok) {
      return res.status(400).json({ message: priceCheck.message });
    }

    const product = await Product.create({
      title: cleanTitle,
      description: cleanDescription,
      price: priceCheck.value,
      condition,
      images: Array.isArray(images) ? images : [],
      category,
      station,
      seller: req.user._id,
      moderationStatus: "pending",
      rejectionReason: "",
      isVerified: false,
      acceptsOffers: acceptsOffers === undefined ? true : Boolean(acceptsOffers),
    });

    const createdProduct = await populateProduct(Product.findById(product._id));

    createAndPushNotification({
      userId: req.user._id,
      title: "Listing submitted for review",
      body: `"${product.title}" is waiting for admin approval before it appears in the marketplace.`,
      type: "listing",
      link: "/profile",
      data: {
        productId: String(product._id),
        moderationStatus: "pending",
      },
    }).catch((error) => {
      console.error("Failed to notify seller about new listing:", error.message);
    });

    return res.status(201).json({
      message: "Product submitted for admin review.",
      product: createdProduct,
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to create product.",
      error: error.message,
    });
  }
}

async function updateProduct(req, res) {
  try {
    const product = await Product.findById(req.params.id);
    if (!product) {
      return res.status(404).json({ message: "Product not found." });
    }
    if (!isOwner(product, req.user)) {
      return res.status(403).json({ message: "You can only edit your own products." });
    }

    const { title, description, price, condition, images, category, station, acceptsOffers } =
      req.body;

    const onlyOffersToggle =
      acceptsOffers !== undefined &&
      title === undefined &&
      description === undefined &&
      price === undefined &&
      condition === undefined &&
      images === undefined &&
      category === undefined &&
      station === undefined;

    if (price !== undefined) {
      const priceCheck = assertProductPrice(price);
      if (!priceCheck.ok) {
        return res.status(400).json({ message: priceCheck.message });
      }
      product.price = priceCheck.value;
    }

    if (title !== undefined) {
      const cleanTitle = String(title).trim();
      if (cleanTitle.length < 3) {
        return res.status(400).json({ message: "Title must be at least 3 characters." });
      }
      product.title = cleanTitle;
    }
    if (description !== undefined) {
      const cleanDescription = String(description).trim();
      if (cleanDescription.length < 10) {
        return res.status(400).json({
          message: "Description must be at least 10 characters.",
        });
      }
      product.description = cleanDescription;
    }
    if (condition !== undefined) product.condition = condition;
    if (images !== undefined) product.images = Array.isArray(images) ? images : [];
    if (category !== undefined) product.category = category;
    if (station !== undefined) product.station = station;
    if (acceptsOffers !== undefined) product.acceptsOffers = Boolean(acceptsOffers);

    // Re-submit for review after content edits (not for offers toggle alone).
    if (!onlyOffersToggle) {
      product.moderationStatus = "pending";
      product.rejectionReason = "";
      product.moderatedAt = null;
      product.moderatedBy = null;
      product.isVerified = false;
    }

    await product.save();

    const updated = await populateProduct(Product.findById(product._id));

    if (!onlyOffersToggle) {
      createAndPushNotification({
        userId: req.user._id,
        title: "Listing resubmitted",
        body: `"${product.title}" was updated and is waiting for admin review again.`,
        type: "listing",
        link: "/profile",
        data: {
          productId: String(product._id),
          moderationStatus: "pending",
        },
      }).catch(() => {});
    }

    return res.status(200).json({
      message: onlyOffersToggle
        ? "Offer settings updated."
        : "Product updated and resubmitted for review.",
      product: updated,
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to update product.",
      error: error.message,
    });
  }
}

async function deleteProduct(req, res) {
  try {
    const product = await Product.findById(req.params.id);
    if (!product) {
      return res.status(404).json({ message: "Product not found." });
    }
    if (!isOwner(product, req.user)) {
      return res.status(403).json({ message: "You can only delete your own products." });
    }

    const title = product.title;
    const productId = String(product._id);
    await product.deleteOne();

    createAndPushNotification({
      userId: req.user._id,
      title: "Listing removed",
      body: `"${title}" has been deleted and is no longer listed for sale.`,
      type: "listing",
      link: "/profile",
      data: {
        productId,
        action: "deleted",
      },
    }).catch((error) => {
      console.error("Failed to notify seller about deleted listing:", error.message);
    });

    return res.status(200).json({ message: "Product deleted." });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to delete product.",
      error: error.message,
    });
  }
}

module.exports = {
  getProducts,
  getMyProducts,
  getProductById,
  createProduct,
  updateProduct,
  deleteProduct,
};
