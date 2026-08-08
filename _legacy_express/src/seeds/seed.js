require("dotenv").config();

const connectDB = require("../config/db");
const User = require("../models/User");
const Category = require("../models/Category");
const Station = require("../models/Station");
const Product = require("../models/Product");
const Offer = require("../models/Offer");
const Order = require("../models/Order");
const Notification = require("../models/Notification");
const OtpCode = require("../models/OtpCode");

const CONDITIONS = ["Like New", "Good", "Fair"];
const PRODUCT_STATUSES = ["active", "reserved", "sold", "archived"];
const MODERATION_STATUSES = ["pending", "approved", "rejected"];

function img(id) {
  return `https://images.unsplash.com/photo-${id}?w=800&q=80`;
}

// Each template has images that match the product name (verified reachable).
const PRODUCT_TEMPLATES = [
  {
    title: "Precision Chronograph Watch",
    category: "tech",
    basePrice: 420,
    images: [img("1523275335684-37898b6baf30")],
  },
  {
    title: "Wireless Noise-Cancel Headphones",
    category: "tech",
    basePrice: 180,
    images: [img("1505740420928-5e560c06d30e")],
  },
  {
    title: "Mechanical Keyboard TKL",
    category: "tech",
    basePrice: 95,
    images: [img("1511467687858-23d96c32e4ae")],
  },
  {
    title: 'USB-C Portable Monitor 15"',
    category: "tech",
    basePrice: 220,
    images: [img("1517336714731-489689fd1ca8")],
  },
  {
    title: "Action Camera 4K Bundle",
    category: "cameras",
    basePrice: 260,
    images: [img("1502920917128-1aa500764cbd")],
  },
  {
    title: "Mirrorless Lens 35mm",
    category: "cameras",
    basePrice: 310,
    images: [img("1516035069371-29a1b244cc32")],
  },
  {
    title: "Vintage Film Camera",
    category: "cameras",
    basePrice: 145,
    images: [img("1495707902641-75cac588d2e9")],
  },
  {
    title: "Nike Dunk Low Retro",
    category: "fashion",
    basePrice: 145,
    images: [img("1542291026-7eec264c27ff")],
  },
  {
    title: "Leather Crossbody Bag",
    category: "fashion",
    basePrice: 88,
    images: [img("1584917865442-de89df76afd3")],
  },
  {
    title: "Linen Summer Shirt",
    category: "fashion",
    basePrice: 42,
    images: [img("1596755094514-f87e34085b2c")],
  },
  {
    title: "Denim Jacket Classic Fit",
    category: "fashion",
    basePrice: 68,
    images: [img("1551537482-f2075a1d41f2")],
  },
  {
    title: "Running Shoes Size 42",
    category: "sports",
    basePrice: 79,
    images: [img("1595950653106-6c9ebd614d3a")],
  },
  {
    title: "Yoga Mat Premium 6mm",
    category: "sports",
    basePrice: 35,
    images: [img("1592432678016-e910b452f9a2")],
  },
  {
    title: "Dumbbell Set 10kg Pair",
    category: "sports",
    basePrice: 55,
    images: [img("1571019613454-1cb2f99b2d8b")],
  },
  {
    title: "Folding Bike Helmet",
    category: "sports",
    basePrice: 48,
    images: [img("1485965120184-e220f721d03e")],
  },
  {
    title: "Mid-Century Accent Chair",
    category: "home",
    basePrice: 190,
    images: [img("1567538096630-e0c55bd6374c")],
  },
  {
    title: "Ceramic Table Lamp",
    category: "home",
    basePrice: 54,
    images: [img("1507473885765-e6ed057f782c")],
  },
  {
    title: "Oak Side Table",
    category: "home",
    basePrice: 120,
    images: [img("1533090161767-e6ffed986c88")],
  },
  {
    title: "Air Purifier Compact",
    category: "home",
    basePrice: 99,
    images: [img("1585771724684-38269d6639fd")],
  },
  {
    title: "Switch OLED Console",
    category: "gaming",
    basePrice: 280,
    images: [img("1578303512597-81e6cc155b3e")],
  },
  {
    title: "Wireless Controller Pro",
    category: "gaming",
    basePrice: 62,
    images: [img("1592840496694-26d035b52b48")],
  },
  {
    title: "RGB Gaming Mouse",
    category: "gaming",
    basePrice: 45,
    images: [img("1527814050087-3793815479db")],
  },
  {
    title: "Hardcover Novel Bundle",
    category: "books",
    basePrice: 28,
    images: [img("1512820790803-83ca734da794")],
  },
  {
    title: "Design Sketchbook Pack",
    category: "books",
    basePrice: 18,
    images: [img("1531346878377-a5be20888e57")],
  },
  {
    title: "Language Learning Card Set",
    category: "books",
    basePrice: 22,
    images: [img("1512820790803-83ca734da794")],
  },
  {
    title: "Bluetooth Speaker Mini",
    category: "tech",
    basePrice: 39,
    images: [img("1608043152269-423dbba4e7e1")],
  },
  {
    title: "Smartwatch Sport Edition",
    category: "tech",
    basePrice: 165,
    images: [img("1579586337278-3befd40fd17a")],
  },
  {
    title: "Tablet Stand Aluminum",
    category: "tech",
    basePrice: 32,
    images: [img("1544244015-0df4b3ffc6b0")],
  },
  {
    title: "Polarized Sunglasses",
    category: "fashion",
    basePrice: 55,
    images: [img("1511499767150-a48a237f0083")],
  },
  {
    title: "Canvas Tote Everyday",
    category: "fashion",
    basePrice: 26,
    images: [img("1544816155-12df9643f363")],
  },
  {
    title: "Resistance Band Kit",
    category: "sports",
    basePrice: 24,
    images: [img("1571019613454-1cb2f99b2d8b")],
  },
  {
    title: "Camping Folding Chair",
    category: "sports",
    basePrice: 41,
    images: [img("1504280390367-361c6d9f38f4")],
  },
  {
    title: "Wall Clock Minimal Brass",
    category: "home",
    basePrice: 47,
    images: [img("1563861826100-9cb868fdbe1c")],
  },
  {
    title: "Desk Organizer Wood Set",
    category: "home",
    basePrice: 33,
    images: [img("1524758631624-e2822e304c36")],
  },
  {
    title: "Retro Handheld Console",
    category: "gaming",
    basePrice: 72,
    images: [img("1493711662062-fa541adb3fc8")],
  },
  {
    title: "Photography Lighting Kit",
    category: "cameras",
    basePrice: 118,
    images: [img("1516035069371-29a1b244cc32")],
  },
  {
    title: "Cookbook Vietnamese Classics",
    category: "books",
    basePrice: 19,
    images: [img("1466637574441-749b8f19452f")],
  },
  {
    title: "Kids Illustrated Story Box",
    category: "books",
    basePrice: 27,
    images: [img("1512820790803-83ca734da794")],
  },
  {
    title: "Phone Tripod Flexible",
    category: "cameras",
    basePrice: 29,
    images: [img("1520390138845-fd2d229dd553")],
  },
  {
    title: "Wool Scarf Soft Knit",
    category: "fashion",
    basePrice: 34,
    images: [img("1520903920243-00d872a2d1c9")],
  },
];

const USER_PROFILES = [
  {
    fullName: "Marcus Chen",
    email: "marcus@rebox.com",
    phone: "0900000001",
    bio: "Tech reseller in District 1.",
  },
  {
    fullName: "Linh Tran",
    email: "linh@rebox.com",
    phone: "0900000002",
    bio: "Fashion & lifestyle seller.",
  },
  {
    fullName: "Buyer Demo",
    email: "buyer@rebox.com",
    phone: "0900000003",
    bio: "Demo buyer account.",
  },
  {
    fullName: "Minh Nguyen",
    email: "minh@rebox.com",
    phone: "0900000004",
    bio: "Camera gear collector.",
  },
  {
    fullName: "An Pham",
    email: "an@rebox.com",
    phone: "0900000005",
    bio: "Home & furniture finds.",
  },
  {
    fullName: "Hoa Le",
    email: "hoa@rebox.com",
    phone: "0900000006",
    bio: "Books and stationery.",
  },
  {
    fullName: "Khoa Vo",
    email: "khoa@rebox.com",
    phone: "0900000007",
    bio: "Gaming peripherals.",
  },
  {
    fullName: "Thu Dang",
    email: "thu@rebox.com",
    phone: "0900000008",
    bio: "Sneaker rotation.",
  },
  {
    fullName: "Bao Hoang",
    email: "bao@rebox.com",
    phone: "0900000009",
    bio: "Sports equipment.",
  },
  {
    fullName: "Nga Bui",
    email: "nga@rebox.com",
    phone: "0900000010",
    bio: "Minimal wardrobe seller.",
  },
  {
    fullName: "Quang Do",
    email: "quang@rebox.com",
    phone: "0900000011",
    bio: "Laptop & accessories.",
  },
  {
    fullName: "Yen Mai",
    email: "yen@rebox.com",
    phone: "0900000012",
    bio: "Kids & home soft goods.",
  },
  {
    fullName: "Dat Phan",
    email: "dat@rebox.com",
    phone: "0900000013",
    bio: "Audio gear specialist.",
  },
  {
    fullName: "Trang Vu",
    email: "trang@rebox.com",
    phone: "0900000014",
    bio: "Bags and accessories.",
  },
  {
    fullName: "Hung Tran",
    email: "hung@rebox.com",
    phone: "0900000015",
    bio: "Outdoor camping kit.",
  },
  {
    fullName: "My Chau",
    email: "my@rebox.com",
    phone: "0900000016",
    bio: "Decor & lighting.",
  },
  {
    fullName: "Son Le",
    email: "son@rebox.com",
    phone: "0900000017",
    bio: "Console games & devices.",
  },
  {
    fullName: "Lan Huynh",
    email: "lan@rebox.com",
    phone: "0900000018",
    bio: "Study & reading materials.",
  },
  {
    fullName: "Phuc Ngo",
    email: "phuc@rebox.com",
    phone: "0900000019",
    bio: "Phone photography kits.",
  },
  {
    fullName: "Ha Dinh",
    email: "ha@rebox.com",
    phone: "0900000020",
    bio: "Everyday essentials.",
  },
];

function pick(list, index) {
  return list[index % list.length];
}

function priceFor(basePrice, index) {
  const jitter = ((index * 17) % 41) - 20;
  return Math.max(8, Math.round(basePrice + jitter));
}

function buildModeration(status, adminId, index) {
  if (status === "approved") {
    return {
      moderationStatus: "approved",
      moderatedAt: new Date(Date.now() - index * 3600_000),
      moderatedBy: adminId,
      rejectionReason: "",
    };
  }
  if (status === "rejected") {
    return {
      moderationStatus: "rejected",
      moderatedAt: new Date(Date.now() - index * 3600_000),
      moderatedBy: adminId,
      rejectionReason: pick(
        [
          "Blurry product photos.",
          "Incomplete description.",
          "Price looks unrealistic.",
          "Item does not match category.",
        ],
        index,
      ),
    };
  }
  return {
    moderationStatus: "pending",
    moderatedAt: null,
    moderatedBy: null,
    rejectionReason: "",
  };
}

async function seed() {
  try {
    await connectDB();

    await Promise.all([
      Offer.deleteMany({}),
      Order.deleteMany({}),
      Notification.deleteMany({}),
      OtpCode.deleteMany({}),
      Product.deleteMany({}),
      Category.deleteMany({}),
      Station.deleteMany({}),
      User.deleteMany({}),
    ]);

    const users = await User.create([
      ...USER_PROFILES.map((profile, index) => ({
        ...profile,
        password: "Rebox@123",
        role: "user",
        emailVerified: true,
        emailVerifiedAt: new Date(),
        deliveryAddress: {
          fullName: profile.fullName,
          phone: profile.phone,
          line1: `${12 + index} Nguyen Trai`,
          line2: `Floor ${(index % 5) + 1}`,
          city: index % 2 === 0 ? "Ho Chi Minh" : "Ha Noi",
          district: index % 2 === 0 ? "District 1" : "Cau Giay",
          note: "Ring the bell",
        },
        pickupAddress: {
          fullName: profile.fullName,
          phone: profile.phone,
          line1: `${30 + index} Le Lai`,
          line2: "",
          city: index % 2 === 0 ? "Ho Chi Minh" : "Ha Noi",
          district: index % 2 === 0 ? "District 3" : "Dong Da",
          note: "Seller pickup point",
        },
      })),
      {
        fullName: "Shipper Demo",
        email: "shipper@rebox.com",
        phone: "0912345678",
        password: "Rebox@123",
        role: "shipper",
        bio: "Demo shipper account.",
        emailVerified: true,
        emailVerifiedAt: new Date(),
        deliveryAddress: {
          fullName: "Shipper Demo",
          phone: "0912345678",
          line1: "1 Shipper Depot",
          city: "Ho Chi Minh",
          district: "Tan Binh",
          note: "",
        },
        pickupAddress: {
          fullName: "Shipper Demo",
          phone: "0912345678",
          line1: "1 Shipper Depot",
          city: "Ho Chi Minh",
          district: "Tan Binh",
          note: "",
        },
      },
      {
        fullName: "ReBox Admin",
        email: "admin@rebox.com",
        phone: "0900000000",
        password: "Admin@123",
        role: "admin",
        bio: "System administrator",
        emailVerified: true,
        emailVerifiedAt: new Date(),
        deliveryAddress: {
          fullName: "ReBox Admin",
          phone: "0900000000",
          line1: "100 Admin Street",
          city: "Ho Chi Minh",
          district: "District 1",
          note: "",
        },
        pickupAddress: {
          fullName: "ReBox Admin",
          phone: "0900000000",
          line1: "100 Admin Street",
          city: "Ho Chi Minh",
          district: "District 1",
          note: "",
        },
      },
    ]);

    const sellers = users.filter((user) => user.role === "user");
    const admin = users.find((user) => user.role === "admin");
    const shipper = users.find((user) => user.role === "shipper");

    const categories = await Category.create([
      { name: "Tech", slug: "tech", icon: "laptop" },
      { name: "Fashion", slug: "fashion", icon: "shirt" },
      { name: "Home", slug: "home", icon: "sofa" },
      { name: "Gaming", slug: "gaming", icon: "gamepad" },
      { name: "Books", slug: "books", icon: "book" },
      { name: "Sports", slug: "sports", icon: "bolt" },
      { name: "Cameras", slug: "cameras", icon: "camera" },
      { name: "More", slug: "more", icon: "more" },
    ]);

    const categoryBySlug = Object.fromEntries(
      categories.map((category) => [category.slug, category]),
    );

    const stations = await Station.create([
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
      {
        city: "Ho Chi Minh",
        address: "Circle K Thao Dien, Q2",
        lockerCode: "C08",
        partnerName: "Circle K",
      },
      {
        city: "Ho Chi Minh",
        address: "GS25 Nguyen Trai, Q5",
        lockerCode: "D15",
        partnerName: "GS25",
      },
      {
        city: "Ha Noi",
        address: "Circle K Hang Bai, Hoan Kiem",
        lockerCode: "E03",
        partnerName: "Circle K",
      },
      {
        city: "Da Nang",
        address: "GS25 Bach Dang",
        lockerCode: "F21",
        partnerName: "GS25",
      },
    ]);

    const productsPayload = Array.from({ length: 100 }, (_, index) => {
      const template = pick(PRODUCT_TEMPLATES, index);
      const seller = pick(sellers, index);
      const category = categoryBySlug[template.category] || categoryBySlug.more;
      const station = pick(stations, index);
      const condition = pick(CONDITIONS, index);
      // Bias toward buyable listings so checkout/PayPal demos work out of the box.
      // Keep ~30% as moderation/status variety for admin review screens.
      const status =
        index % 10 < 7 ? "active" : pick(PRODUCT_STATUSES, index);
      const moderationStatus =
        index % 10 < 7
          ? "approved"
          : pick(MODERATION_STATUSES, Math.floor(index / 2));
      const moderation = buildModeration(moderationStatus, admin._id, index);
      const images = Array.isArray(template.images) ? template.images : [];

      return {
        title: `${template.title} #${String(index + 1).padStart(3, "0")}`,
        description: [
          `${template.title} in ${condition.toLowerCase()} condition.`,
          `Listed by ${seller.fullName}.`,
          status === "active"
            ? "Ready for escrow-protected pickup."
            : `Current listing status: ${status}.`,
          moderationStatus === "rejected"
            ? "Needs seller updates before approval."
            : "Includes original accessories when available.",
        ].join(" "),
        price: priceFor(template.basePrice, index),
        condition,
        images,
        isVerified: moderationStatus === "approved" && index % 4 === 0,
        seller: seller._id,
        category: category._id,
        station: station._id,
        status,
        acceptsOffers: index % 5 !== 0,
        ...moderation,
      };
    });

    const products = await Product.insertMany(productsPayload);

    const approvedActive = products.filter(
      (product) =>
        product.moderationStatus === "approved" && product.status === "active",
    );

    if (approvedActive.length >= 2) {
      const ttl = new Date(Date.now() + 48 * 60 * 60 * 1000);
      await Offer.create([
        {
          product: approvedActive[0]._id,
          buyer: sellers[2]._id,
          seller: approvedActive[0].seller,
          listPrice: approvedActive[0].price,
          discountPercent: 10,
          offerPrice: Math.round(approvedActive[0].price * 0.9 * 100) / 100,
          message: "Can pick up today.",
          expiresAt: ttl,
        },
        {
          product: approvedActive[1]._id,
          buyer: sellers[0]._id,
          seller: approvedActive[1].seller,
          listPrice: approvedActive[1].price,
          discountPercent: 5,
          offerPrice: Math.round(approvedActive[1].price * 0.95 * 100) / 100,
          message: "Offer for fast trade.",
          expiresAt: ttl,
        },
        {
          product: approvedActive[Math.min(5, approvedActive.length - 1)]._id,
          buyer: sellers[5]._id,
          seller: approvedActive[Math.min(5, approvedActive.length - 1)].seller,
          listPrice: approvedActive[Math.min(5, approvedActive.length - 1)].price,
          discountPercent: 15,
          offerPrice:
            Math.round(
              approvedActive[Math.min(5, approvedActive.length - 1)].price *
                0.85 *
                100,
            ) / 100,
          message: "Interested if box is included.",
          expiresAt: ttl,
        },
      ]);
    }

    const counts = {
      users: sellers.length,
      shipper: users.filter((u) => u.role === "shipper").length,
      admin: 1,
      categories: categories.length,
      stations: stations.length,
      products: products.length,
      byStatus: Object.fromEntries(
        PRODUCT_STATUSES.map((status) => [
          status,
          products.filter((product) => product.status === status).length,
        ]),
      ),
      byModeration: Object.fromEntries(
        MODERATION_STATUSES.map((status) => [
          status,
          products.filter((product) => product.moderationStatus === status)
            .length,
        ]),
      ),
    };

    // eslint-disable-next-line no-console
    console.log("Seed completed.");
    // eslint-disable-next-line no-console
    console.log(JSON.stringify(counts, null, 2));
    // eslint-disable-next-line no-console
    console.log(
      "Demo logins: *@rebox.com / Rebox@123 | shipper@rebox.com / Rebox@123 | admin@rebox.com / Admin@123",
    );
    process.exit(0);
  } catch (error) {
    // eslint-disable-next-line no-console
    console.error("Seed failed:", error.message);
    process.exit(1);
  }
}

seed();
