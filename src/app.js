const express = require("express");
const cors = require("cors");

const authRoutes = require("./routes/authRoutes");
const productRoutes = require("./routes/productRoutes");
const lookupRoutes = require("./routes/lookupRoutes");
const uploadRoutes = require("./routes/uploadRoutes");
const { uploadsRoot } = require("./middleware/uploadMiddleware");

const app = express();

app.use(cors());
app.use(express.json());
app.use("/uploads", express.static(uploadsRoot));

app.get("/api/health", (req, res) => {
  res.status(200).json({ status: "ok", service: "rebox-backend" });
});

app.use("/api/auth", authRoutes);
app.use("/api/products", productRoutes);
app.use("/api/uploads", uploadRoutes);
app.use("/api", lookupRoutes);

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
  res.status(404).json({ message: "Route not found." });
});

module.exports = app;
