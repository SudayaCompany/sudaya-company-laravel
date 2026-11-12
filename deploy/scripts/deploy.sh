#!/usr/bin/env bash
# Rilis versi baru Sudaya Coffee tanpa mengganggu outlet. Dipasang provision.sh sebagai
# /usr/local/sbin/sudaya-deploy. Jalankan sebagai root:
#
#   sudo sudaya-deploy                  rilis branch utama terbaru dari GitHub
#   sudo sudaya-deploy --ref <commit>   rilis commit/tag tertentu
#   sudo sudaya-deploy --migrate        izinkan migration database baru (baca isinya dulu!)
#   sudo sudaya-deploy --first          deploy pertama: pasang database baru (schema + data awal + bucket)
#
# Alur: unduh kode ke folder rilis baru → composer + build aset → (migration) → optimize →
# pindah symlink `current` (atomik) → reload PHP-FPM → cek /up dan /api/health. Bila cek gagal,
# otomatis kembali ke rilis sebelumnya. Folder storage/ dan .env dibagi antar rilis, jadi staf
# tidak ter-logout. Lima rilis terakhir disimpan untuk rollback (sudo sudaya-rollback).
set -euo pipefail

APP_DIR=/var/www/sudaya
APP_USER=sudaya
KEEP_RELEASES=5
CONF="$APP_DIR/deploy.conf"

die() { printf '\033[1;31mBERHENTI: %s\033[0m\n' "$1" >&2; exit 1; }
step() { printf '\n\033[1;32m==> %s\033[0m\n' "$1"; }
as_app() { runuser -u "$APP_USER" -- "$@"; }

[ "$(id -u)" = 0 ] || die "jalankan dengan sudo"
[ -f "$CONF" ] || die "$CONF tidak ada; jalankan provision.sh dulu"
# shellcheck disable=SC1090
. "$CONF"
: "${DOMAIN:?DOMAIN kosong di $CONF}" "${REPO_URL:?}" "${BRANCH:?}" "${PHP_VERSION:?}"

REF="$BRANCH"
MIGRATE=0
FIRST=0
while [ $# -gt 0 ]; do
  case "$1" in
    --ref) REF="${2:?--ref butuh commit/tag}"; shift 2 ;;
    --migrate) MIGRATE=1; shift ;;
    --first) FIRST=1; MIGRATE=1; shift ;;
    -h|--help) sed -n '2,16p' "$0"; exit 0 ;;
    *) die "opsi tidak dikenal: $1 (lihat --help)" ;;
  esac
done

mkdir -p /run/lock
exec 9>/run/lock/sudaya-deploy.lock
flock -n 9 || die "deploy lain sedang berjalan"

[ -f "$APP_DIR/shared/.env" ] || die "$APP_DIR/shared/.env tidak ada"
if grep -Eq '^(DB_URL|SUPABASE_URL|SUPABASE_SERVICE_ROLE_KEY)=$' "$APP_DIR/shared/.env"; then
  die "DB_URL / SUPABASE_URL / SUPABASE_SERVICE_ROLE_KEY di $APP_DIR/shared/.env masih kosong"
fi

step "Ambil kode ($REF)"
if [ ! -d "$APP_DIR/repo.git" ]; then
  as_app git clone --quiet --mirror "$REPO_URL" "$APP_DIR/repo.git"
fi
as_app git --git-dir="$APP_DIR/repo.git" remote update --prune >/dev/null
SHA="$(as_app git --git-dir="$APP_DIR/repo.git" rev-parse --verify --quiet "${REF}^{commit}")" || die "commit/branch $REF tidak ditemukan di $REPO_URL"
NAME="$(date -u +%Y%m%d-%H%M%S)-${SHA:0:7}"
REL="$APP_DIR/releases/$NAME"
PREV="$(readlink -f "$APP_DIR/current" 2>/dev/null || true)"
echo "commit $SHA → $REL"

# Rilis yang gagal sebelum aktif selalu dibuang; rilis yang sudah aktif disimpan.
ACTIVATED=0
trap '[ "$ACTIVATED" = 1 ] || rm -rf "$REL"' EXIT

as_app mkdir -p "$REL"
as_app bash -c "git --git-dir='$APP_DIR/repo.git' archive '$SHA' | tar -x -C '$REL'"
# Test dan alat pengembangan tidak ikut ke server (pengaman test live, docs/STAGING_PREFLIGHT.md §4).
rm -rf "$REL/tests" "$REL/phpunit.xml" "$REL/phpunit.live.xml"
as_app ln -sfn "$APP_DIR/shared/.env" "$REL/.env"
rm -rf "$REL/storage"
as_app ln -sfn "$APP_DIR/shared/storage" "$REL/storage"
as_app bash -c "echo '$SHA $(date -u +%Y-%m-%dT%H:%M:%SZ)' > '$REL/REVISION'"

step "Composer (tanpa paket pengembangan)"
(cd "$REL" && as_app composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist --quiet)

step "Build aset tampilan"
(cd "$REL" && as_app npm ci --no-audit --no-fund --loglevel=error && as_app npm run build --silent && rm -rf "$REL/node_modules")
[ -f "$REL/public/build/manifest.json" ] || die "build aset gagal (public/build/manifest.json tidak ada)"

cd "$REL"
step "Migration database"
if [ "$FIRST" = 1 ]; then
  as_app php artisan migrate --force
  as_app php artisan db:seed --force
  as_app php artisan sudaya:storage-setup
else
  status="$(as_app php artisan migrate:status --pending --no-ansi 2>&1 || true)"
  case "$status" in
    *"Migration table not found"*) die "database belum dipasang. Untuk project Supabase baru: sudo sudaya-deploy --first" ;;
  esac
  pending="$(echo "$status" | grep -E ' Pending *$' | awk '{print $1}' || true)"
  if [ -n "$pending" ]; then
    if [ "$MIGRATE" != 1 ]; then
      echo "Migration baru belum dijalankan:"
      echo "$pending" | sed 's/^/  - database\/migrations\//; s/$/.php/'
      die "baca isi file di atas, lalu ulangi dengan: sudo sudaya-deploy --migrate"
    fi
    as_app php artisan migrate --force
  else
    echo "tidak ada migration baru"
  fi
fi

step "Optimize"
as_app php artisan optimize >/dev/null

step "Aktifkan rilis"
as_app ln -sfn "$REL" "$APP_DIR/current.new"
mv -Tf "$APP_DIR/current.new" "$APP_DIR/current"
ACTIVATED=1
systemctl reload "php${PHP_VERSION}-fpm"

health() {
  local url scheme
  if [ -d "/etc/letsencrypt/live/$DOMAIN" ]; then scheme=https; else scheme=http; fi
  for path in /up /api/health; do
    url="$scheme://$DOMAIN$path"
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 --resolve "$DOMAIN:80:127.0.0.1" --resolve "$DOMAIN:443:127.0.0.1" "$url" || true)"
    if [ "$code" != 200 ]; then echo "$url → $code"; return 1; fi
  done
}

step "Cek kesehatan"
sleep 1
if ! health; then
  if [ -n "$PREV" ] && [ -d "$PREV" ]; then
    ln -sfn "$PREV" "$APP_DIR/current.new" && mv -Tf "$APP_DIR/current.new" "$APP_DIR/current"
    systemctl reload "php${PHP_VERSION}-fpm"
    # Rilis rusak dibuang agar tidak terpilih rollback dan tidak menggusur rilis sehat.
    rm -rf "$REL"
    die "rilis baru tidak sehat; otomatis kembali ke $(basename "$PREV"). Lihat $APP_DIR/shared/storage/logs/"
  fi
  die "rilis pertama tidak sehat. Lihat $APP_DIR/shared/storage/logs/ dan /var/log/nginx/sudaya.error.log"
fi
echo "sehat: /up dan /api/health → 200"

step "Bersihkan rilis lama (simpan $KEEP_RELEASES)"
# shellcheck disable=SC2012
ls -1dt "$APP_DIR"/releases/*/ 2>/dev/null | tail -n +$((KEEP_RELEASES + 1)) | while read -r old; do
  [ "$(readlink -f "$old")" = "$(readlink -f "$APP_DIR/current")" ] || rm -rf "$old"
done

printf '\n\033[1;32mSelesai: %s aktif.\033[0m Cek lengkap: sudo sudaya-artisan sudaya:doctor\n' "$NAME"

# Skrip deploy/rollback di server ikut versi terbaru untuk deploy berikutnya. Baris terakhir:
# `install` membuat file baru, jadi skrip yang sedang berjalan tidak terganggu.
install -m 755 "$REL/deploy/scripts/rollback.sh" /usr/local/sbin/sudaya-rollback
install -m 755 "$REL/deploy/scripts/deploy.sh" /usr/local/sbin/sudaya-deploy
exit 0
