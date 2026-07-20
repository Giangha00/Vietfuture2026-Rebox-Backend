const express = require("express");
const cors = require("cors");

const authRoutes = require("./routes/authRoutes");
const productRoutes = require("./routes/productRoutes");
const lookupRoutes = require("./routes/lookupRoutes");

const app = express();

app.use(cors());
app.use(express.json());

app.get("/api/health", (req, res) => {
  res.status(200).json({ status: "ok", service: "rebox-backend" });
});

app.use("/api/auth", authRoutes);
app.use("/api/products", productRoutes);
app.use("/api", lookupRoutes);

app.use((req, res) => {
  res.status(404).json({ message: "Route not found." });
});

module.exports = app;
