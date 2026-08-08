require("dotenv").config();

const app = require("./app");
const connectDB = require("./config/db");
const { ensureAdminUser } = require("./admin/utils/ensureAdmin");

const PORT = process.env.PORT || 5001;

function assertAuthEnv() {
  if (!process.env.JWT_SECRET || process.env.JWT_SECRET === "replace_with_a_strong_secret") {
    throw new Error(
      "Set a strong JWT_SECRET in .env before starting the server.",
    );
  }
}

async function bootstrap() {
  try {
    assertAuthEnv();
    await connectDB();
    await ensureAdminUser();
    app.listen(PORT, () => {
      // eslint-disable-next-line no-console
      console.log(`Server is running at http://localhost:${PORT}`);
      // eslint-disable-next-line no-console
      console.log(`Admin panel: http://localhost:${PORT}/admin`);
    });
  } catch (error) {
    // eslint-disable-next-line no-console
    console.error("Failed to start server:", error.message);
    process.exit(1);
  }
}

bootstrap();
