const path = require("path");
const ejs = require("ejs");
const { formatMoney } = require("../../utils/money");

const viewsRoot = path.join(__dirname, "../../views");

async function renderAdmin(res, view, data = {}) {
  try {
    const locals = { ...res.locals, ...data, formatMoney };
    const body = await ejs.renderFile(
      path.join(viewsRoot, "admin", `${view}.ejs`),
      locals,
    );

    return res.render("admin/layouts/main", {
      ...locals,
      body,
    });
  } catch (error) {
    return res.status(500).send(`Render error: ${error.message}`);
  }
}

module.exports = { renderAdmin };
