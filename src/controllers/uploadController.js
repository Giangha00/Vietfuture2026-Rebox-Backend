function absoluteBaseUrl(req) {
  if (process.env.PUBLIC_BASE_URL) {
    return process.env.PUBLIC_BASE_URL.replace(/\/$/, "");
  }

  const host = req.get("host");
  const protocol = req.protocol || "http";
  return `${protocol}://${host}`;
}

async function uploadImages(req, res) {
  try {
    const files = Array.isArray(req.files) ? req.files : [];

    if (files.length === 0) {
      return res.status(400).json({ message: "No image files uploaded." });
    }

    const baseUrl = absoluteBaseUrl(req);
    const urls = files.map((file) => `${baseUrl}/uploads/${file.filename}`);

    return res.status(201).json({
      message: "Upload successful.",
      urls,
    });
  } catch (error) {
    return res.status(500).json({
      message: "Failed to upload images.",
      error: error.message,
    });
  }
}

module.exports = { uploadImages };
