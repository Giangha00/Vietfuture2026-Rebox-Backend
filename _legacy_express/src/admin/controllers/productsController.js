const Product = require("../../models/Product");
const Category = require("../../models/Category");
const Station = require("../../models/Station");
const User = require("../../models/User");
const { renderAdmin } = require("../utils/render");
const { createAndPushNotification } = require("../../services/notificationService");
const { assertProductPrice } = require("../../utils/money");

async function list(req, res) {
  const q = String(req.query.q || "").trim();
  const moderation = String(req.query.moderation || "").trim();
  const filter = {};
  if (q) filter.title = new RegExp(q, "i");
  if (["pending", "approved", "rejected"].includes(moderation)) {
    filter.moderationStatus = moderation;
  }

  const products = await Product.find(filter)
    .populate("seller", "fullName email")
    .populate("category", "name")
    .populate("station", "city partnerName lockerCode")
    .sort({ createdAt: -1 });

  const pendingCount = await Product.countDocuments({ moderationStatus: "pending" });

  return renderAdmin(res, "products/index", {
    title: "Products",
    products,
    q,
    moderation,
    pendingCount,
    success: req.query.success || null,
    error: req.query.error || null,
  });
}

async function loadFormData() {
  const [categories, stations, sellers] = await Promise.all([
    Category.find().sort({ name: 1 }),
    Station.find().sort({ city: 1 }),
    User.find().sort({ fullName: 1 }).select("fullName email"),
  ]);
  return { categories, stations, sellers };
}

async function showCreate(req, res) {
  const formData = await loadFormData();
  return renderAdmin(res, "products/form", {
    title: "Create Product",
    product: null,
    error: null,
    ...formData,
  });
}

async function create(req, res) {
  try {
    const title = String(req.body.title || "").trim();
    const description = String(req.body.description || "").trim();

    if (title.length < 3) {
      throw new Error("Title must be at least 3 characters.");
    }
    if (description.length < 10) {
      throw new Error("Description must be at least 10 characters.");
    }
    const priceCheck = assertProductPrice(req.body.price);
    if (!priceCheck.ok) throw new Error(priceCheck.message);
    if (!req.body.seller || !req.body.category || !req.body.station) {
      throw new Error("Seller, category, and station are required.");
    }

    const images = String(req.body.images || "")
      .split("\n")
      .map((s) => s.trim())
      .filter(Boolean);

    await Product.create({
      title,
      description,
      price: priceCheck.value,
      condition: req.body.condition || "Good",
      images,
      isVerified: req.body.isVerified === "on",
      moderationStatus: req.body.moderationStatus || "approved",
      seller: req.body.seller,
      category: req.body.category,
      station: req.body.station,
      status: req.body.status || "active",
      acceptsOffers: req.body.acceptsOffers === "on",
    });
    return res.redirect("/admin/products?success=Product created");
  } catch (error) {
    const formData = await loadFormData();
    res.status(400);
    return renderAdmin(res, "products/form", {
      title: "Create Product",
      product: req.body,
      error: error.message,
      ...formData,
    });
  }
}

async function showEdit(req, res) {
  const product = await Product.findById(req.params.id);
  if (!product) return res.redirect("/admin/products?error=Product not found");
  const formData = await loadFormData();
  return renderAdmin(res, "products/form", {
    title: "Edit Product",
    product,
    error: null,
    ...formData,
  });
}

async function showReview(req, res) {
  const product = await Product.findById(req.params.id)
    .populate("seller", "fullName email phone avatarUrl bio createdAt")
    .populate("category", "name slug icon")
    .populate("station", "city address partnerName lockerCode isActive")
    .populate("moderatedBy", "fullName email");

  if (!product) return res.redirect("/admin/products?error=Product not found");

  return renderAdmin(res, "products/review", {
    title: "Review Product",
    product,
    success: req.query.success || null,
    error: req.query.error || null,
  });
}

async function update(req, res) {
  try {
    const product = await Product.findById(req.params.id);
    if (!product) return res.redirect("/admin/products?error=Product not found");

    const title = String(req.body.title || "").trim();
    const description = String(req.body.description || "").trim();
    const priceCheck = assertProductPrice(req.body.price);

    if (title.length < 3) throw new Error("Title must be at least 3 characters.");
    if (description.length < 10) {
      throw new Error("Description must be at least 10 characters.");
    }
    if (!priceCheck.ok) throw new Error(priceCheck.message);
    if (!req.body.seller || !req.body.category || !req.body.station) {
      throw new Error("Seller, category, and station are required.");
    }

    const images = String(req.body.images || "")
      .split("\n")
      .map((s) => s.trim())
      .filter(Boolean);

    product.title = title;
    product.description = description;
    product.price = priceCheck.value;
    product.condition = req.body.condition || "Good";
    product.images = images;
    product.isVerified = req.body.isVerified === "on";
    product.seller = req.body.seller;
    product.category = req.body.category;
    product.station = req.body.station;
    product.status = req.body.status || "active";
    product.acceptsOffers = req.body.acceptsOffers === "on";
    if (["pending", "approved", "rejected"].includes(req.body.moderationStatus)) {
      product.moderationStatus = req.body.moderationStatus;
    }
    await product.save();

    return res.redirect("/admin/products?success=Product updated");
  } catch (error) {
    const formData = await loadFormData();
    res.status(400);
    return renderAdmin(res, "products/form", {
      title: "Edit Product",
      product: { ...req.body, _id: req.params.id },
      error: error.message,
      ...formData,
    });
  }
}

async function approve(req, res) {
  try {
    const product = await Product.findById(req.params.id);
    if (!product) return res.redirect("/admin/products?error=Product not found");

    product.moderationStatus = "approved";
    product.rejectionReason = "";
    product.moderatedAt = new Date();
    product.moderatedBy = req.admin._id;
    if (req.body.isVerified === "on") {
      product.isVerified = true;
    }
    await product.save();

    createAndPushNotification({
      userId: product.seller,
      title: "Listing approved",
      body: `"${product.title}" is now live on ReBox.`,
      type: "listing",
      link: `/products/${product._id}`,
      data: {
        productId: String(product._id),
        moderationStatus: "approved",
      },
    }).catch(() => {});

    return res.redirect(
      `/admin/products/${product._id}/review?success=${encodeURIComponent("Product approved and published")}`,
    );
  } catch (error) {
    return res.redirect(
      `/admin/products/${req.params.id}/review?error=${encodeURIComponent(error.message)}`,
    );
  }
}

async function reject(req, res) {
  try {
    const reason = String(req.body.rejectionReason || "").trim();
    if (reason.length < 5) {
      return res.redirect(
        `/admin/products/${req.params.id}/review?error=${encodeURIComponent("Rejection reason must be at least 5 characters")}`,
      );
    }

    const product = await Product.findById(req.params.id);
    if (!product) return res.redirect("/admin/products?error=Product not found");

    product.moderationStatus = "rejected";
    product.rejectionReason = reason;
    product.moderatedAt = new Date();
    product.moderatedBy = req.admin._id;
    product.isVerified = false;
    await product.save();

    createAndPushNotification({
      userId: product.seller,
      title: "Listing rejected",
      body: `"${product.title}" was not approved. Reason: ${reason}`,
      type: "listing",
      link: "/profile",
      data: {
        productId: String(product._id),
        moderationStatus: "rejected",
        rejectionReason: reason,
      },
    }).catch(() => {});

    return res.redirect(
      `/admin/products/${product._id}/review?success=${encodeURIComponent("Product rejected and seller notified")}`,
    );
  } catch (error) {
    return res.redirect(
      `/admin/products/${req.params.id}/review?error=${encodeURIComponent(error.message)}`,
    );
  }
}

async function destroy(req, res) {
  try {
    const product = await Product.findById(req.params.id);
    if (!product) {
      return res.redirect("/admin/products?error=Product not found");
    }

    const title = product.title;
    const sellerId = product.seller;
    const productId = String(product._id);
    await product.deleteOne();

    if (sellerId) {
      createAndPushNotification({
        userId: sellerId,
        title: "Listing removed by admin",
        body: `"${title}" was deleted by ReBox admin and is no longer listed.`,
        type: "listing",
        link: "/profile",
        data: {
          productId,
          action: "deleted_by_admin",
        },
      }).catch(() => {});
    }

    return res.redirect("/admin/products?success=Product deleted");
  } catch (error) {
    return res.redirect(`/admin/products?error=${encodeURIComponent(error.message)}`);
  }
}

module.exports = {
  list,
  showCreate,
  create,
  showReview,
  showEdit,
  update,
  approve,
  reject,
  destroy,
};
