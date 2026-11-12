#!/usr/bin/env bash
# Persiapan VPS Sudaya Coffee, sekali jalan, sebagai root. Ubuntu 24.04 atau 26.04 LTS kosong.
#
#   git clone https://github.com/SudayaCompany/sudaya-company-laravel.git /root/sudaya
#   sudo DOMAIN=sudayacoffee.com bash /root/sudaya/deploy/scripts/provision.sh
#
# Yang dipasang: Nginx, PHP-FPM (PPA ondrej/php), PostgreSQL client 17 (pg_dump, repo resmi
# PGDG), Node.js 22 (hanya untuk build aset saat deploy), Composer, certbot, firewall (SSH +
# HTTP/HTTPS), update keamanan otomatis, swap bila RAM < 2 GB, user `sudaya`, folder
# /var/www/sudaya, .env production (APP_KEY dibuat), cron Laravel, dan perintah
# `sudaya-deploy`, `sudaya-rollback`, `sudaya-artisan`.
# Aman diulang: langkah yang sudah ada dilewati, .env yang sudah ada tidak ditimpa.
set -euo pipefail

: "${DOMAIN:?Isi DOMAIN, contoh: sudo DOMAIN=sudayacoffee.com bash provision.sh}"
PHP_VERSION="${PHP_VERSION:-8.5}"
NODE_MAJOR="${NODE_MAJOR:-22}"
REPO_URL="${REPO_URL:-https://github.com/SudayaCompany/sudaya-company-laravel.git}"
BRANCH="${BRANCH:-main}"
APP_USER=sudaya
APP_DIR=/var/www/sudaya
BACKUP_DIR=/var/backups/sudaya
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

step() { printf '\n\033[1;32m==> %s\033[0m\n' "$1"; }
die() { printf '\033[1;31mBERHENTI: %s\033[0m\n' "$1" >&2; exit 1; }

[ "$(id -u)" = 0 ] || die "jalankan dengan sudo/root"
# shellcheck disable=SC1091
. /etc/os-release
[ "${ID:-}" = ubuntu ] || die "skrip ini untuk Ubuntu (24.04/26.04 LTS), terdeteksi: ${PRETTY_NAME:-?}"
[ -f "$SRC/deploy/nginx/sudaya.conf" ] || die "jalankan dari salinan repo (deploy/nginx/sudaya.conf tidak ditemukan)"
case "$DOMAIN" in *[!a-z0-9.-]*|.*|*.) die "DOMAIN tidak valid: $DOMAIN (contoh: sudayacoffee.com, tanpa https://)";; esac

export DEBIAN_FRONTEND=noninteractive

step "Paket dasar"
apt-get update -q
apt-get install -y -q software-properties-common ca-certificates curl gnupg git unzip rsync ufw cron unattended-upgrades

step "Repo PHP (ondrej/php), PostgreSQL (PGDG), Node.js ${NODE_MAJOR} (NodeSource)"
if ! grep -rqs "ondrej/php" /etc/apt/sources.list.d/; then
  add-apt-repository -y ppa:ondrej/php
fi
install -d /usr/share/postgresql-common/pgdg /etc/apt/keyrings
curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" > /etc/apt/sources.list.d/pgdg.list
curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" > /etc/apt/sources.list.d/nodesource.list
apt-get update -q

step "Nginx, PHP ${PHP_VERSION}, PostgreSQL client 17, Node.js, certbot"
php_pkgs=()
for ext in fpm cli pgsql intl curl mbstring xml zip bcmath readline; do
  apt-cache show "php${PHP_VERSION}-${ext}" >/dev/null 2>&1 || die "paket php${PHP_VERSION}-${ext} tidak tersedia (cek PHP_VERSION)"
  php_pkgs+=("php${PHP_VERSION}-${ext}")
done
# Sejak PHP 8.5 OPcache bawaan PHP (tidak ada paket terpisah); versi sebelumnya butuh paketnya.
if apt-cache show "php${PHP_VERSION}-opcache" >/dev/null 2>&1; then php_pkgs+=("php${PHP_VERSION}-opcache"); fi
apt-get install -y -q nginx "${php_pkgs[@]}" postgresql-client-17 nodejs certbot python3-certbot-nginx
php -r 'exit(function_exists("opcache_get_status") ? 0 : 1);' || die "OPcache tidak aktif di PHP ${PHP_VERSION}"
php -r 'foreach (["pdo_pgsql","intl","sodium","curl","mbstring","openssl","fileinfo","zip","tokenizer","xml","ctype"] as $e) if (!extension_loaded($e)) { fwrite(STDERR, "ekstensi PHP kurang: $e\n"); exit(1); }'

step "Composer"
if ! command -v composer >/dev/null; then
  expected="$(curl -fsSL https://composer.github.io/installer.sig)"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  actual="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
  [ "$expected" = "$actual" ] || die "installer Composer tidak cocok dengan tanda tangannya"
  php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

step "Swap (build aset butuh memori)"
mem_mb=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
if [ "$mem_mb" -lt 2000 ] && ! swapon --show | grep -q .; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
  grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  echo "swap 2 GB dibuat (RAM ${mem_mb} MB)"
else
  echo "dilewati (RAM ${mem_mb} MB)"
fi

step "User ${APP_USER} dan folder ${APP_DIR}"
id -u "$APP_USER" >/dev/null 2>&1 || adduser --system --group --home "$APP_DIR" --shell /bin/bash "$APP_USER"
install -d -o "$APP_USER" -g "$APP_USER" -m 755 "$APP_DIR" "$APP_DIR/releases" "$APP_DIR/shared"
for d in app/private app/public framework/cache/data framework/sessions framework/views framework/auth-tokens logs; do
  install -d "$APP_DIR/shared/storage/$d"
done
# Folder perantara yang dibuat `install -d` milik root: seluruh storage diserahkan ke user aplikasi.
chown -R "$APP_USER:$APP_USER" "$APP_DIR/shared/storage"
find "$APP_DIR/shared/storage" -type d -exec chmod 750 {} +
install -d -o "$APP_USER" -g "$APP_USER" -m 700 "$BACKUP_DIR"

step ".env production"
if [ ! -f "$APP_DIR/shared/.env" ]; then
  key="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
  sed -e "s/__DOMAIN__/${DOMAIN}/g" -e "s#^APP_KEY=.*#APP_KEY=${key}#" "$SRC/deploy/env.production.example" > "$APP_DIR/shared/.env"
  chown "$APP_USER:$APP_USER" "$APP_DIR/shared/.env"
  chmod 640 "$APP_DIR/shared/.env"
  echo "dibuat: $APP_DIR/shared/.env (APP_KEY baru). Isi DB_URL dan SUPABASE_* sebelum deploy pertama."
else
  echo "sudah ada, tidak diubah"
fi

step "Konfigurasi deploy"
cat > "$APP_DIR/deploy.conf" <<CONF
# Dibaca sudaya-deploy / sudaya-rollback.
DOMAIN=${DOMAIN}
REPO_URL=${REPO_URL}
BRANCH=${BRANCH}
PHP_VERSION=${PHP_VERSION}
CONF
install -m 755 "$SRC/deploy/scripts/deploy.sh" /usr/local/sbin/sudaya-deploy
install -m 755 "$SRC/deploy/scripts/rollback.sh" /usr/local/sbin/sudaya-rollback
cat > /usr/local/sbin/sudaya-artisan <<'WRAP'
#!/usr/bin/env bash
# Jalankan php artisan aplikasi Sudaya sebagai user sudaya: sudo sudaya-artisan sudaya:doctor
set -euo pipefail
cd /var/www/sudaya/current
exec runuser -u sudaya -- php artisan "$@"
WRAP
chmod 755 /usr/local/sbin/sudaya-artisan

step "PHP-FPM"
install -m 644 "$SRC/deploy/php/sudaya-fpm.conf" "/etc/php/${PHP_VERSION}/fpm/pool.d/sudaya.conf"
[ -f "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" ] && mv "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf.disabled"
install -m 644 "$SRC/deploy/php/99-sudaya.ini" "/etc/php/${PHP_VERSION}/fpm/conf.d/99-sudaya.ini"
install -m 644 "$SRC/deploy/php/99-sudaya.ini" "/etc/php/${PHP_VERSION}/cli/conf.d/99-sudaya.ini"
"php-fpm${PHP_VERSION}" -t
systemctl enable --now "php${PHP_VERSION}-fpm" >/dev/null
systemctl restart "php${PHP_VERSION}-fpm"

step "Nginx"
sed "s/__DOMAIN__/${DOMAIN}/g" "$SRC/deploy/nginx/sudaya.conf" > /etc/nginx/sites-available/sudaya
ln -sfn /etc/nginx/sites-available/sudaya /etc/nginx/sites-enabled/sudaya
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now nginx >/dev/null
systemctl reload nginx

step "Cron Laravel"
install -m 644 "$SRC/deploy/cron.sudaya" /etc/cron.d/sudaya
systemctl enable --now cron >/dev/null

step "Firewall (SSH, HTTP, HTTPS) dan update keamanan otomatis"
ufw allow OpenSSH >/dev/null
ufw allow 'Nginx Full' >/dev/null
ufw --force enable >/dev/null
ufw status | sed -n '1,8p'
dpkg-reconfigure -f noninteractive unattended-upgrades >/dev/null 2>&1 || true

step "Selesai"
cat <<NEXT
Langkah berikutnya (docs/DEPLOY_VPS.md):
  1. Isi DB_URL dan SUPABASE_* di ${APP_DIR}/shared/.env   (sudo nano ${APP_DIR}/shared/.env)
  2. Deploy pertama + pasang database:                     sudo sudaya-deploy --first
  3. HTTPS (DNS ${DOMAIN} dan www.${DOMAIN} sudah mengarah ke server ini):
       sudo certbot --nginx -d ${DOMAIN} -d www.${DOMAIN} --redirect -m <email-admin>
  4. Super Admin pertama, menu asli, lalu cek kesehatan:
       sudo sudaya-artisan sudaya:admin-bootstrap --email=<email> --name="<nama>"
       sudo sudaya-artisan sudaya:menu-asli
       sudo sudaya-artisan sudaya:doctor
NEXT
