#!/usr/bin/env bash
# Kembali ke rilis sebelumnya bila versi baru bermasalah. Dipasang sebagai /usr/local/sbin/sudaya-rollback.
#
#   sudo sudaya-rollback           ke rilis sebelum yang aktif
#   sudo sudaya-rollback <nama>    ke rilis tertentu (daftar: ls /var/www/sudaya/releases)
#
# Hanya kode yang dikembalikan. Migration database bersifat aditif (kolom/tabel baru tidak
# mengganggu kode lama), jadi tidak dibalik. Staf tidak ter-logout (storage/ dibagi).
set -euo pipefail

APP_DIR=/var/www/sudaya
CONF="$APP_DIR/deploy.conf"
die() { printf '\033[1;31mBERHENTI: %s\033[0m\n' "$1" >&2; exit 1; }

[ "$(id -u)" = 0 ] || die "jalankan dengan sudo"
# shellcheck disable=SC1090
. "$CONF"
current="$(readlink -f "$APP_DIR/current")" || die "belum ada rilis aktif"

if [ $# -ge 1 ]; then
  target="$APP_DIR/releases/$1"
else
  # Rilis tepat sebelum yang aktif (urut nama = urut waktu).
  target="$(ls -1d "$APP_DIR"/releases/*/ | sed 's#/$##' | sort | awk -v cur="$current" '$0 == cur {print prev; exit} {prev = $0}')"
fi
[ -n "${target:-}" ] && [ -d "$target" ] || die "rilis tujuan tidak ditemukan. Daftar rilis: $(ls -1 "$APP_DIR/releases" | tr '\n' ' ')"
[ "$(readlink -f "$target")" != "$current" ] || die "$(basename "$target") sudah aktif"

mkdir -p /run/lock
exec 9>/run/lock/sudaya-deploy.lock
flock -n 9 || die "deploy lain sedang berjalan"

ln -sfn "$target" "$APP_DIR/current.new"
mv -Tf "$APP_DIR/current.new" "$APP_DIR/current"
(cd "$target" && runuser -u sudaya -- php artisan optimize >/dev/null)
systemctl reload "php${PHP_VERSION}-fpm"
echo "aktif: $(basename "$target") (sebelumnya $(basename "$current"))"
echo "cek: sudo sudaya-artisan sudaya:doctor"
