const path = require("path");
const express = require("express");
const cors = require("cors");
const cookieParser = require("cookie-parser");

const authRoutes = require("./routes/authRoutes");
const productRoutes = require("./routes/productRoutes");
const lookupRoutes = require("./routes/lookupRoutes");
const uploadRoutes = require("./routes/uploadRoutes");
const notificationRoutes = require("./routes/notificationRoutes");
const orderRoutes = require("./routes/orderRoutes");
const paymentRoutes = require("./routes/paymentRoutes");
const offerRoutes = require("./routes/offerRoutes");
const adminRoutes = require("./admin/routes");
const { uploadsRoot } = require("./middleware/uploadMiddleware");
const { initFirebaseAdmin } = require("./services/fcmService");

const app = express();

initFirebaseAdmin();

app.set("view engine", "ejs");
app.set("views", path.join(__dirname, "views"));

app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));
app.use(cookieParser());
app.use("/uploads", express.static(uploadsRoot));

app.get("/api/health", (req, res) => {
  res.status(200).json({ status: "ok", service: "rebox-backend" });
});

app.use("/api/auth", authRoutes);
app.use("/api/products", productRoutes);
app.use("/api/uploads", uploadRoutes);
app.use("/api/notifications", notificationRoutes);
app.use("/api/orders", orderRoutes);
app.use("/api/payments", paymentRoutes);
app.use("/api/offers", offerRoutes);
app.use("/api", lookupRoutes);
app.use("/admin", adminRoutes);

app.use((err, _req, res, next) => {
  if (err instanceof Error && err.message) {
    if (err.code === "LIMIT_FILE_SIZE") {
      return res.status(400).json({ message: "Each image must be under 5MB." });
    }
    if (err.message.includes("Only image files")) {
      return res.status(400).json({ message: err.message });
    }
  }
  return next(err);
});

app.use((req, res) => {
  if (req.path.startsWith("/admin")) {
    return res.status(404).send("Admin page not found.");
  }
  return res.status(404).json({ message: "Route not found." });
});

module.exports = app;
