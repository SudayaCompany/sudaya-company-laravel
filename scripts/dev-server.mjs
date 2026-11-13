// Server lokal multi-proses untuk development di Windows (BUKAN untuk production).
//
// `php artisan serve` / `php -S` hanya melayani SATU request dalam satu waktu (di Windows tidak ada
// PHP_CLI_SERVER_WORKERS). Dengan database dev yang jauh, satu request bisa beberapa detik, jadi
// alarm kasir, muat ulang otomatis, dan klik menu saling mengantre dan aplikasi terasa macet.
// Skrip ini menjalankan beberapa proses `php -S` di belakang proxy kecil: request dibagi ke proses
// yang sedang kosong, sisanya antre, dan request yang kliennya sudah batal (mis. klik menu lain)
// dibuang sebelum diproses.
//
//   npm run serve                          → http://127.0.0.1:8000, 4 proses
//   PORT=8001 WORKERS=6 npm run serve
//   PHP_BINARY=C:\path\ke\php.exe npm run serve   (bila `php` di PATH bukan PHP 8.4+)
//
// Proses PHP memakai ulang koneksi database dan mengirim tiap query dalam 1 round trip (DB_PERSISTENT,
// DB_DISABLE_PREPARES; hanya di server ini, .env tidak diubah): klik menu jauh lebih cepat ke database dev.
import { spawn, spawnSync } from "node:child_process";
import http from "node:http";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const PUBLIC_DIR = path.join(ROOT, "public");
const ROUTER = path.join(ROOT, "vendor", "laravel", "framework", "src", "Illuminate", "Foundation", "resources", "server.php");
const PHP = process.env.PHP_BINARY || "php";
const HOST = process.env.HOST || "127.0.0.1";
const PORT = Number(process.env.PORT || 8000);
const WORKERS = Math.max(1, Number(process.env.WORKERS || 4));
const FIRST_WORKER_PORT = Number(process.env.WORKER_PORT || PORT + 100);
const QUIET = /\.(js|css|map|png|jpe?g|webp|svg|ico|woff2?|webmanifest|txt)(\?|$)|^\/sw\.js|^\/offline\.html/;

// PHP yang salah (mis. `php` XAMPP lama tanpa pdo_pgsql) baru ketahuan di browser sebagai error 500:
// cek sekali di awal dan beri pesan yang jelas.
const check = spawnSync(PHP, ["-r", "echo PHP_VERSION, '|', extension_loaded('pdo_pgsql') ? 1 : 0;"], { encoding: "utf8" });
const [phpVersion = "", hasPgsql] = check.error || check.status !== 0 ? [] : check.stdout.trim().split("|");
const [major, minor] = phpVersion.split(".").map(Number);
if (!phpVersion || major < 8 || (major === 8 && minor < 3) || hasPgsql !== "1") {
  console.error(
    phpVersion
      ? `PHP ${phpVersion} (${PHP}) tidak cocok: butuh PHP 8.3+ dengan ekstensi pdo_pgsql.`
      : `PHP tidak bisa dijalankan (${PHP}).`,
  );
  console.error('Set PHP_BINARY ke php.exe yang benar. Git Bash: PHP_BINARY=C:/path/ke/php.exe npm run serve · PowerShell: $env:PHP_BINARY="C:\\path\\ke\\php.exe"; npm run serve');
  process.exit(1);
}

// Database dev jauh: pakai ulang koneksi per proses dan 1 round trip per query (lihat config/database.php).
// Bisa dimatikan: DB_PERSISTENT=false / DB_DISABLE_PREPARES=false.
const PHP_ENV = {
  ...process.env,
  DB_PERSISTENT: process.env.DB_PERSISTENT ?? "true",
  DB_DISABLE_PREPARES: process.env.DB_DISABLE_PREPARES ?? "true",
};

let stopping = false;
const workers = [];

function startWorker(worker) {
  // server.php Laravel memakai folder kerja sebagai folder public.
  worker.proc = spawn(PHP, ["-S", `127.0.0.1:${worker.port}`, "-t", PUBLIC_DIR, ROUTER], { cwd: PUBLIC_DIR, env: PHP_ENV, stdio: ["ignore", "ignore", "pipe"] });
  worker.proc.stderr.on("data", (chunk) => {
    // Log akses php -S tidak perlu; tampilkan hanya error PHP.
    for (const line of String(chunk).split(/\r?\n/)) {
      if (line && !/ (Accepted|Closing)$|\]: (GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS) /.test(line) && !/Development Server .* started/.test(line)) {
        console.error(`[php:${worker.port}] ${line}`);
      }
    }
  });
  worker.proc.on("error", (error) => {
    console.error(`Gagal menjalankan PHP (${PHP}): ${error.message}. Set PHP_BINARY ke php.exe versi 8.4+.`);
    stop(1);
  });
  worker.startedAt = Date.now();
  // Beri waktu php -S membuka port sebelum menerima request.
  setTimeout(() => {
    worker.ready = true;
    drain();
  }, 400);
  worker.proc.on("exit", () => {
    worker.ready = false;
    worker.busy = false;
    if (stopping) return;
    // Mati berulang segera setelah start (mis. port terpakai): hentikan, jangan berputar terus.
    worker.fails = Date.now() - worker.startedAt < 3_000 ? (worker.fails ?? 0) + 1 : 0;
    if (worker.fails >= 3) {
      console.error(`Proses PHP di port ${worker.port} terus berhenti (port terpakai?). Coba WORKER_PORT lain.`);
      stop(1);
      return;
    }
    setTimeout(() => startWorker(worker), 500);
  });
}

for (let i = 0; i < WORKERS; i++) {
  const worker = { port: FIRST_WORKER_PORT + i, busy: false, ready: false, proc: null };
  workers.push(worker);
  startWorker(worker);
}

const queue = [];

/** Proses yang memegang satu request lebih lama dari ini dianggap macet: dimatikan lalu dinyalakan ulang. */
const STUCK_MS = 180_000;

function drain() {
  while (queue.length > 0) {
    const worker = workers.find((w) => w.ready && !w.busy);
    if (!worker) return;
    const job = queue.shift();
    // Klien sudah pergi sebelum giliran tiba: jangan buang waktu server untuk request ini.
    if (job.gone) continue;
    run(worker, job);
  }
}

function run(worker, job) {
  const { req, res, started } = job;
  worker.busy = true;
  let released = false;
  const watchdog = setTimeout(() => {
    console.error(`[php:${worker.port}] ${req.method} ${req.url} lebih dari ${STUCK_MS / 1000} dtk: proses dinyalakan ulang`);
    worker.proc?.kill();
  }, STUCK_MS);
  const release = () => {
    if (released) return;
    released = true;
    clearTimeout(watchdog);
    worker.busy = false;
    drain();
  };
  // Log: status bila respons sampai ke klien, "batal" bila klien pergi lebih dulu.
  let logged = false;
  const log = () => {
    if (logged || QUIET.test(req.url ?? "")) return;
    logged = true;
    const status = res.writableFinished ? res.statusCode : "batal";
    console.log(`${new Date().toTimeString().slice(0, 8)} ${status} ${req.method} ${req.url} ${Date.now() - started} ms`);
  };
  res.once("finish", log);
  res.once("close", log);
  // Tanpa keep-alive ke php -S: satu koneksi per request, selesai = proses kosong lagi.
  const upstream = http.request({ host: "127.0.0.1", port: worker.port, method: req.method, path: req.url, headers: req.headers, agent: false }, (up) => {
    // Respons PHP selalu dibaca sampai habis, juga bila klien sudah pergi (mis. klik menu lain):
    // respons yang tidak dibaca membuat proses tercatat sibuk selamanya dan server macet.
    up.on("end", release);
    up.on("close", release);
    up.on("error", release);
    if (res.destroyed) {
      up.resume();
      return;
    }
    res.writeHead(up.statusCode ?? 502, up.headers);
    up.pipe(res);
    res.once("close", () => {
      if (!up.complete) {
        up.unpipe(res);
        up.resume();
      }
    });
  });
  upstream.on("close", release);
  upstream.on("error", () => {
    release();
    if (res.headersSent || res.destroyed) {
      res.destroy();
      return;
    }
    res.writeHead(502, { "content-type": "text/plain; charset=utf-8" });
    res.end("Server PHP lokal tidak merespons.");
  });
  // Klien putus saat body (mis. unggah foto) belum terkirim penuh: hentikan request ke PHP.
  req.once("close", () => {
    if (!req.complete) upstream.destroy();
  });
  req.pipe(upstream);
}

const server = http.createServer((req, res) => {
  // Klien menutup koneksi di tengah jalan: abaikan, jangan sampai proxy ikut mati.
  req.on("error", () => undefined);
  res.on("error", () => undefined);
  const job = { req, res, started: Date.now(), gone: false };
  res.once("close", () => {
    job.gone = true;
  });
  queue.push(job);
  drain();
});

function stop(code = 0) {
  stopping = true;
  for (const worker of workers) worker.proc?.kill();
  server.close();
  process.exit(code);
}

server.on("error", (error) => {
  console.error(String(error));
  stop(1);
});
server.listen(PORT, HOST, () => {
  console.log(`Sudaya dev server: http://${HOST}:${PORT} (${WORKERS} proses PHP ${phpVersion}, port ${FIRST_WORKER_PORT}-${FIRST_WORKER_PORT + WORKERS - 1})`);
});
process.on("SIGINT", () => stop());
process.on("SIGTERM", () => stop());
process.on("exit", () => workers.forEach((w) => w.proc?.kill()));
