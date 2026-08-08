const express = require("express");
const { requireAdmin, redirectIfAuthed } = require("../middleware/adminAuth");
const authController = require("../controllers/authController");
const dashboardController = require("../controllers/dashboardController");
const usersController = require("../controllers/usersController");
const productsController = require("../controllers/productsController");
const categoriesController = require("../controllers/categoriesController");
const stationsController = require("../controllers/stationsController");
const offersController = require("../controllers/offersController");
const ordersController = require("../controllers/ordersController");

const router = express.Router();

router.get("/login", redirectIfAuthed, authController.showLogin);
router.post("/login", redirectIfAuthed, authController.login);
router.post("/logout", requireAdmin, authController.logout);

router.use(requireAdmin);

router.get("/", dashboardController.dashboard);

router.get("/users", usersController.list);
router.get("/users/new", usersController.showCreate);
router.post("/users", usersController.create);
router.get("/users/:id/edit", usersController.showEdit);
router.post("/users/:id", usersController.update);
router.post("/users/:id/delete", usersController.destroy);

router.get("/products", productsController.list);
router.get("/products/new", productsController.showCreate);
router.post("/products", productsController.create);
router.get("/products/:id/review", productsController.showReview);
router.get("/products/:id/edit", productsController.showEdit);
router.post("/products/:id", productsController.update);
router.post("/products/:id/approve", productsController.approve);
router.post("/products/:id/reject", productsController.reject);
router.post("/products/:id/delete", productsController.destroy);

router.get("/categories", categoriesController.list);
router.get("/categories/new", categoriesController.showCreate);
router.post("/categories", categoriesController.create);
router.get("/categories/:id/edit", categoriesController.showEdit);
router.post("/categories/:id", categoriesController.update);
router.post("/categories/:id/delete", categoriesController.destroy);

router.get("/stations", stationsController.list);
router.get("/stations/new", stationsController.showCreate);
router.post("/stations", stationsController.create);
router.get("/stations/:id/edit", stationsController.showEdit);
router.post("/stations/:id", stationsController.update);
router.post("/stations/:id/delete", stationsController.destroy);

router.get("/offers", offersController.list);
router.post("/offers/:id/status", offersController.updateStatus);
router.post("/offers/:id/delete", offersController.destroy);

router.get("/orders", ordersController.list);
router.get("/orders/:id", ordersController.show);
router.post("/orders/:id/assign-shipper", ordersController.assignShipper);
router.post("/orders/:id/resolve-dispute", ordersController.resolveDispute);

module.exports = router;
