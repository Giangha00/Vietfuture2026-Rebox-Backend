const Offer = require("../../models/Offer");
const { renderAdmin } = require("../utils/render");

async function list(req, res) {
  const offers = await Offer.find()
    .populate("product", "title price")
    .populate("buyer", "fullName email")
    .sort({ createdAt: -1 });

  return renderAdmin(res, "offers/index", {
    title: "Offers",
    offers,
    success: req.query.success || null,
    error: req.query.error || null,
  });
}

async function updateStatus(req, res) {
  try {
    const offer = await Offer.findById(req.params.id);
    if (!offer) return res.redirect("/admin/offers?error=Offer not found");
    const status = req.body.status;
    if (!["pending", "accepted", "rejected", "cancelled"].includes(status)) {
      return res.redirect("/admin/offers?error=Invalid status");
    }
    offer.status = status;
    await offer.save();
    return res.redirect("/admin/offers?success=Offer updated");
  } catch (error) {
    return res.redirect(`/admin/offers?error=${encodeURIComponent(error.message)}`);
  }
}

async function destroy(req, res) {
  try {
    await Offer.findByIdAndDelete(req.params.id);
    return res.redirect("/admin/offers?success=Offer deleted");
  } catch (error) {
    return res.redirect(`/admin/offers?error=${encodeURIComponent(error.message)}`);
  }
}

module.exports = { list, updateStatus, destroy };
