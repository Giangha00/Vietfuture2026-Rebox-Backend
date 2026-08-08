/**
 * Keep heavy folders off iCloud Desktop/Documents sync.
 * macOS iCloud ignores paths ending in `.nosync`. After `npm install`
 * recreates a real `node_modules`, move it back behind a symlink.
 */
const fs = require("fs");
const path = require("path");

function ensureNosync(name) {
  const dir = path.resolve(process.cwd(), name);
  const nosync = path.resolve(process.cwd(), `${name}.nosync`);

  if (!fs.existsSync(dir)) return;

  const stat = fs.lstatSync(dir);
  if (stat.isSymbolicLink()) return;

  if (fs.existsSync(nosync)) {
    fs.rmSync(nosync, { recursive: true, force: true });
  }

  fs.renameSync(dir, nosync);
  fs.symlinkSync(`${name}.nosync`, dir);
  console.log(`[ensure-nosync] ${name} -> ${name}.nosync`);
}

ensureNosync("node_modules");
