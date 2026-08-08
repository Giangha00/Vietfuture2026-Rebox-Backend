const express = require("express");
const Category = require("../models/Category");
const Station = require("../models/Station");

const router = express.Router();

router.get("/categories", async (req, res) => {
  try {
    const categories = await Category.find().sort({ name: 1 });
    res.status(200).json({ categories });
  } catch (error) {
    res.status(500).json({ message: "Failed to fetch categories.", error: error.message });
  }
});

router.get("/stations", async (req, res) => {
  try {
    const stations = await Station.find({ isActive: true }).sort({ city: 1, address: 1 });
    res.status(200).json({ stations });
  } catch (error) {
    res.status(500).json({ message: "Failed to fetch stations.", error: error.message });
  }
});

module.exports = router;

