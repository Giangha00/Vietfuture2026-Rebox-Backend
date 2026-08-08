const User = require("../../models/User");
const Product = require("../../models/Product");
const Category = require("../../models/Category");
const Station = require("../../models/Station");
const Offer = require("../../models/Offer");
const Notification = require("../../models/Notification");
const { renderAdmin } = require("../utils/render");

async function dashboard(req, res) {
  try {
    const [
      usersCount,
      productsCount,
      categoriesCount,
      stationsCount,
      offersCount,
      notificationsCount,
      verifiedProducts,
      activeProducts,
      soldProducts,
      adminsCount,
    ] = await Promise.all([
      User.countDocuments(),
      Product.countDocuments(),
      Category.countDocuments(),
      Station.countDocuments(),
      Offer.countDocuments(),
      Notification.countDocuments(),
      Product.countDocuments({ isVerified: true }),
      Product.countDocuments({ status: "active" }),
      Product.countDocuments({ status: "sold" }),
      User.countDocuments({ role: "admin" }),
    ]);

    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 29);
    thirtyDaysAgo.setHours(0, 0, 0, 0);

    const [usersByDay, productsByDay, productsByCategory, productsByStatus, recentUsers, recentProducts] =
      await Promise.all([
        User.aggregate([
          { $match: { createdAt: { $gte: thirtyDaysAgo } } },
          {
            $group: {
              _id: { $dateToString: { format: "%Y-%m-%d", date: "$createdAt" } },
              count: { $sum: 1 },
            },
          },
          { $sort: { _id: 1 } },
        ]),
        Product.aggregate([
          { $match: { createdAt: { $gte: thirtyDaysAgo } } },
          {
            $group: {
              _id: { $dateToString: { format: "%Y-%m-%d", date: "$createdAt" } },
              count: { $sum: 1 },
            },
          },
          { $sort: { _id: 1 } },
        ]),
        Product.aggregate([
          {
            $lookup: {
              from: "categories",
              localField: "category",
              foreignField: "_id",
              as: "categoryDoc",
            },
          },
          { $unwind: { path: "$categoryDoc", preserveNullAndEmptyArrays: true } },
          {
            $group: {
              _id: "$categoryDoc.name",
              count: { $sum: 1 },
            },
          },
          { $sort: { count: -1 } },
        ]),
        Product.aggregate([
          { $group: { _id: "$status", count: { $sum: 1 } } },
          { $sort: { count: -1 } },
        ]),
        User.find().sort({ createdAt: -1 }).limit(5).select("fullName email role createdAt"),
        Product.find()
          .sort({ createdAt: -1 })
          .limit(5)
          .populate("seller", "fullName")
          .populate("category", "name"),
      ]);

    const dayLabels = [];
    for (let i = 29; i >= 0; i -= 1) {
      const d = new Date();
      d.setDate(d.getDate() - i);
      dayLabels.push(d.toISOString().slice(0, 10));
    }

    const usersMap = Object.fromEntries(usersByDay.map((row) => [row._id, row.count]));
    const productsMap = Object.fromEntries(productsByDay.map((row) => [row._id, row.count]));

    const chartData = {
      labels: dayLabels,
      users: dayLabels.map((day) => usersMap[day] || 0),
      products: dayLabels.map((day) => productsMap[day] || 0),
      categories: {
        labels: productsByCategory.map((row) => row._id || "Uncategorized"),
        values: productsByCategory.map((row) => row.count),
      },
      statuses: {
        labels: productsByStatus.map((row) => row._id || "unknown"),
        values: productsByStatus.map((row) => row.count),
      },
    };

    return renderAdmin(res, "dashboard", {
      title: "Dashboard",
      stats: {
        usersCount,
        productsCount,
        categoriesCount,
        stationsCount,
        offersCount,
        notificationsCount,
        verifiedProducts,
        activeProducts,
        soldProducts,
        adminsCount,
      },
      chartData: JSON.stringify(chartData),
      recentUsers,
      recentProducts,
    });
  } catch (error) {
    return res.status(500).send(`Dashboard error: ${error.message}`);
  }
}

module.exports = { dashboard };
