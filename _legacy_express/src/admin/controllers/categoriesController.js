const Category = require("../../models/Category");
const { renderAdmin } = require("../utils/render");

function slugify(value) {
  return String(value || "")
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/(^-|-$)/g, "");
}

async function list(req, res) {
  const categories = await Category.find().sort({ name: 1 });
  return renderAdmin(res, "categories/index", {
    title: "Categories",
    categories,
    success: req.query.success || null,
    error: req.query.error || null,
  });
}

async function showCreate(req, res) {
  return renderAdmin(res, "categories/form", {
    title: "Create Category",
    category: null,
    error: null,
  });
}

async function create(req, res) {
  try {
    const name = String(req.body.name || "").trim();
    if (name.length < 2) {
      throw new Error("Category name must be at least 2 characters.");
    }
    const slug = String(req.body.slug || "").trim() || slugify(name);
    if (!slug) throw new Error("Slug is required.");
    await Category.create({
      name,
      slug,
      icon: String(req.body.icon || "").trim(),
    });
    return res.redirect("/admin/categories?success=Category created");
  } catch (error) {
    res.status(400);
    return renderAdmin(res, "categories/form", {
      title: "Create Category",
      category: req.body,
      error: error.message,
    });
  }
}

async function showEdit(req, res) {
  const category = await Category.findById(req.params.id);
  if (!category) return res.redirect("/admin/categories?error=Category not found");
  return renderAdmin(res, "categories/form", {
    title: "Edit Category",
    category,
    error: null,
  });
}

async function update(req, res) {
  try {
    const category = await Category.findById(req.params.id);
    if (!category) return res.redirect("/admin/categories?error=Category not found");

    category.name = String(req.body.name || "").trim();
    if (category.name.length < 2) {
      throw new Error("Category name must be at least 2 characters.");
    }
    category.slug =
      String(req.body.slug || "").trim() || slugify(category.name);
    if (!category.slug) throw new Error("Slug is required.");
    category.icon = String(req.body.icon || "").trim();
    await category.save();
    return res.redirect("/admin/categories?success=Category updated");
  } catch (error) {
    res.status(400);
    return renderAdmin(res, "categories/form", {
      title: "Edit Category",
      category: { ...req.body, _id: req.params.id },
      error: error.message,
    });
  }
}

async function destroy(req, res) {
  try {
    await Category.findByIdAndDelete(req.params.id);
    return res.redirect("/admin/categories?success=Category deleted");
  } catch (error) {
    return res.redirect(
      `/admin/categories?error=${encodeURIComponent(error.message)}`,
    );
  }
}

module.exports = { list, showCreate, create, showEdit, update, destroy };
