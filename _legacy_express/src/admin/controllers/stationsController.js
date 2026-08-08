const Station = require("../../models/Station");
const { renderAdmin } = require("../utils/render");

async function list(req, res) {
  const stations = await Station.find().sort({ city: 1, partnerName: 1 });
  return renderAdmin(res, "stations/index", {
    title: "Stations",
    stations,
    success: req.query.success || null,
    error: req.query.error || null,
  });
}

async function showCreate(req, res) {
  return renderAdmin(res, "stations/form", {
    title: "Create Station",
    station: null,
    error: null,
  });
}

async function create(req, res) {
  try {
    const city = String(req.body.city || "").trim();
    const address = String(req.body.address || "").trim();
    const lockerCode = String(req.body.lockerCode || "").trim();

    if (city.length < 2) throw new Error("City must be at least 2 characters.");
    if (address.length < 5) throw new Error("Address must be at least 5 characters.");
    if (!lockerCode) throw new Error("Locker code is required.");

    await Station.create({
      city,
      address,
      lockerCode,
      partnerName: req.body.partnerName || "Other",
      isActive: req.body.isActive === "on",
    });
    return res.redirect("/admin/stations?success=Station created");
  } catch (error) {
    res.status(400);
    return renderAdmin(res, "stations/form", {
      title: "Create Station",
      station: req.body,
      error: error.message,
    });
  }
}

async function showEdit(req, res) {
  const station = await Station.findById(req.params.id);
  if (!station) return res.redirect("/admin/stations?error=Station not found");
  return renderAdmin(res, "stations/form", {
    title: "Edit Station",
    station,
    error: null,
  });
}

async function update(req, res) {
  try {
    const station = await Station.findById(req.params.id);
    if (!station) return res.redirect("/admin/stations?error=Station not found");

    station.city = String(req.body.city || "").trim();
    station.address = String(req.body.address || "").trim();
    station.lockerCode = String(req.body.lockerCode || "").trim();
    station.partnerName = req.body.partnerName || "Other";
    station.isActive = req.body.isActive === "on";

    if (station.city.length < 2) throw new Error("City must be at least 2 characters.");
    if (station.address.length < 5) throw new Error("Address must be at least 5 characters.");
    if (!station.lockerCode) throw new Error("Locker code is required.");

    await station.save();
    return res.redirect("/admin/stations?success=Station updated");
  } catch (error) {
    res.status(400);
    return renderAdmin(res, "stations/form", {
      title: "Edit Station",
      station: { ...req.body, _id: req.params.id },
      error: error.message,
    });
  }
}

async function destroy(req, res) {
  try {
    await Station.findByIdAndDelete(req.params.id);
    return res.redirect("/admin/stations?success=Station deleted");
  } catch (error) {
    return res.redirect(
      `/admin/stations?error=${encodeURIComponent(error.message)}`,
    );
  }
}

module.exports = { list, showCreate, create, showEdit, update, destroy };
