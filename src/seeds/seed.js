require("dotenv").config();

const connectDB = require("../config/db");
const User = require("../models/User");
const Category = require("../models/Category");
const Station = require("../models/Station");
const Product = require("../models/Product");
const Offer = require("../models/Offer");

async function seed() {
  try {
    await connectDB();

    await Promise.all([
      Offer.deleteMany({}),
      Product.deleteMany({}),
      Category.deleteMany({}),
      Station.deleteMany({}),
      User.deleteMany({}),
    ]);

    const [marcus, linh, buyer] = await User.create([
      {
        fullName: "Marcus Chen",
        email: "marcus@rebox.com",
        phone: "0900000001",
        password: "Rebox@123",
      },
      {
        fullName: "Linh Tran",
        email: "linh@rebox.com",
        phone: "0900000002",
        password: "Rebox@123",
      },
      {
        fullName: "Buyer Demo",
        email: "buyer@rebox.com",
        phone: "0900000003",
        password: "Rebox@123",
      },
    ]);

    const [tech, fashion] = await Category.create([
      { name: "Tech", slug: "tech", icon: "laptop" },
      { name: "Fashion", slug: "fashion", icon: "shirt" },
    ]);

    const [stationA, stationB] = await Station.create([
      {
        city: "Ho Chi Minh",
        address: "Circle K Nguyen Hue, Q1",
        lockerCode: "A12",
        partnerName: "Circle K",
      },
      {
        city: "Ho Chi Minh",
        address: "GS25 Le Loi, Q1",
        lockerCode: "B04",
        partnerName: "GS25",
      },
    ]);

    const [watch, sneaker] = await Product.create([
      {
        title: "Precision Chronograph Series 7",
        description: "Like new, full box and documents.",
        price: 495,
        condition: "Like New",
        images: [
          "https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=800&q=80",
        ],
        seller: marcus._id,
        category: tech._id,
        station: stationA._id,
        isVerified: true,
      },
      {
        title: "Nike Dunk Low Retro",
        description: "Used, still in good condition.",
        price: 145,
        condition: "Fair",
        images: [
          "https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=800&q=80",
        ],
        seller: linh._id,
        category: fashion._id,
        station: stationB._id,
      },
    ]);

    await Offer.create([
      {
        product: watch._id,
        buyer: buyer._id,
        offerPrice: 470,
        message: "Can pick up today.",
      },
      {
        product: sneaker._id,
        buyer: marcus._id,
        offerPrice: 130,
        message: "Offer for fast trade.",
      },
    ]);

    // eslint-disable-next-line no-console
    console.log("Seed completed.");
    process.exit(0);
  } catch (error) {
    // eslint-disable-next-line no-console
    console.error("Seed failed:", error.message);
    process.exit(1);
  }
}

seed();
