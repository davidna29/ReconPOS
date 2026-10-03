#!/usr/bin/env bash
#
# deploy.sh — kirim kode lokal ke Hostinger shared hosting.
#
#   ./scripts/deploy.sh --dry-run              # HANYA menampilkan perubahan (read-only di server)
#   ./scripts/deploy.sh                        # eksekusi sungguhan (minta ketik KIRIM dulu)
#   ./scripts/deploy.sh --yes ...              # lewati prompt KIRIM (hanya bila konfirmasi sudah eksplisit)
# Flags: --force --tanpa-test --tanpa-backup --tanpa-maintenance --yes
#
# Urutan: cek lokal -> SSH -> deteksi layout -> [dry-run|rsync] -> down ->
# backup DB hosting -> sambung-public -> composer -> migrate --force ->
# chmod/cache -> up -> verifikasi HTTP.
# Migrasi yang dipakai HANYA "php artisan migrate --force" (tak ada fresh/drop).
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DRY_RUN=0; FORCE=0; TANPA_TEST=0; BACKUP=1; MAINTENANCE=1; YES=0
for a in "$@"; do
  case "$a" in
    --dry-run) DRY_RUN=1 ;;
    --force) FORCE=1 ;;
    --tanpa-test) TANPA_TEST=1 ;;
    --tanpa-backup) BACKUP=0 ;;
    --tanpa-maintenance) MAINTENANCE=0 ;;
    --yes) YES=1 ;;
    *) echo "Flag tak dikenal: $a (lihat header file ini)"; exit 1 ;;
  esac
done

# --- baca DEPLOY_* dari .env lokal (tak pernah dikirim) ---
if [ -f .env ]; then
  # shellcheck disable=SC1091
  set -a; source <(grep -E '^DEPLOY_' .env); set +a
fi
SSH_HOST="${DEPLOY_SSH_HOST:-46.202.138.28}"
SSH_PORT="${DEPLOY_SSH_PORT:-65002}"
SSH_USER="${DEPLOY_SSH_USER:-u549935065}"
SSH_KEY="${DEPLOY_SSH_KEY:-}"
DEPLOY_PATH="${DEPLOY_PATH:-}"
DEPLOY_DOCROOT="${DEPLOY_DOCROOT:-}"
DEPLOY_PHP="${DEPLOY_PHP:-/opt/alt/php84/usr/bin/php}"
DEPLOY_URL="${DEPLOY_URL:-https://reconpos.misnu.sch.id}"
# Tanpa sed: sintaks BRE \? beda arti di macOS (BSD) vs GNU — pakai ekspansi
# parameter bash yg portabel di mana pun.
DOMAIN="${DEPLOY_URL#https://}"
DOMAIN="${DOMAIN#http://}"
DOMAIN="${DOMAIN%%/*}"

SSH_OPTS=(-p "$SSH_PORT" -o BatchMode=yes -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new)
[ -n "$SSH_KEY" ] && SSH_OPTS+=(-i "$SSH_KEY")
RSYNC_SSH="ssh -p $SSH_PORT -o BatchMode=yes -o StrictHostKeyChecking=accept-new"
[ -n "$SSH_KEY" ] && RSYNC_SSH="$RSYNC_SSH -i '$SSH_KEY'"

log() { echo "[$(date +%H:%M:%S)] $*"; }
remote() { ssh "${SSH_OPTS[@]}" "$SSH_USER@$SSH_HOST" "$1"; }

if [ "$DRY_RUN" -eq 1 ]; then log "== MODE DRY-RUN (tidak mengubah server) =="; else log "== KIRIM KE HOSTING =="; fi

# 1. Prasyarat lokal
if [ -n "$(git status --porcelain)" ] && [ "$FORCE" -eq 0 ]; then
  log "GAGAL: working tree kotor. Commit dulu atau pakai --force."; exit 1
fi
if [ "$TANPA_TEST" -eq 0 ]; then
  log "--- php artisan test (bisa ±1 menit, jangan diinterupsi) ---"
  php artisan test || { log "GAGAL: test merah."; exit 1; }
fi
MANIFEST="public/build/manifest.json"
if [ ! -f "$MANIFEST" ]; then log "GAGAL: $MANIFEST hilang. Jalankan: npm run build"; exit 1; fi
if [ -n "$(find resources/css resources/js -type f -newer "$MANIFEST" 2>/dev/null | head -1)" ]; then
  log "GAGAL: build kedaluwarsa (resources lebih baru). Jalankan: npm run build"; exit 1
fi

# 2. Kunci SSH
remote "echo SSH-OK && $DEPLOY_PHP -v" | head -3 || { log "GAGAL: SSH. Pasang key: ssh-copy-id -p $SSH_PORT $SSH_USER@$SSH_HOST"; exit 1; }

# 3. Deteksi layout proyek di server
PROJECT="$DEPLOY_PATH"
if [ -z "$PROJECT" ]; then
  for k in "/home/$SSH_USER/domains/$DOMAIN/reconpos" "/home/$SSH_USER/domains/$DOMAIN/public_html/reconpos"; do
    if remote "test -d '$k' && echo ADA" | grep -q ADA; then PROJECT="$k"; break; fi
  done
fi
if [ -z "$PROJECT" ]; then log "GAGAL: path proyek tak ditemukan. Isi DEPLOY_PATH manual."; exit 1; fi
DOCROOT="${DEPLOY_DOCROOT:-/home/$SSH_USER/domains/$DOMAIN/public_html}"
remote "test -d '$DOCROOT' && echo ADA" | grep -q ADA || { log "GAGAL: docroot tak ditemukan: $DOCROOT"; exit 1; }
log "Project : $PROJECT"
log "Docroot : $DOCROOT"

# Exclude rsync — SAMA PERSIS daftar lama (plus tests/, backups-db/, debugbar, public/hot, .DS_Store).
# bootstrap/cache/*.php WAJIB dikecualikan: file cache lokal dibangun DENGAN
# dev-deps (mis. PailServiceProvider) → bila tersalin, SEMUA artisan di server
# fatal (vendor --no-dev tak punya kelasnya) termasuk `up` (insiden down 2026-10-03).
# .gitignore di dir itu tetap ikut (pola hanya *.php).
EXCLUDES=(.env* node_modules/ vendor/ .git/ tests/ database/*.sqlite* bootstrap/cache/*.php storage/logs/* storage/framework/cache/* storage/framework/sessions/* storage/framework/views/* storage/app/private/backups/* storage/app/private/backups-db/* storage/app/private/export/* storage/debugbar/ public/hot .DS_Store .phpunit.result.cache)
RSYNC_ARGS=(-az --delete)
for e in "${EXCLUDES[@]}"; do RSYNC_ARGS+=(--exclude="$e"); done

# 4. Dry-run: hanya tampilkan, lalu berhenti.
if [ "$DRY_RUN" -eq 1 ]; then
  log "--- rsync dry-run (tidak mengubah server) ---"
  rsync -azn --itemize-changes "${RSYNC_ARGS[@]:1}" -e "$RSYNC_SSH" ./ "$SSH_USER@$SSH_HOST:$PROJECT/"
  log "[dry-run] Perintah remote yg AKAN dijalankan:"
  [ "$BACKUP" -eq 1 ] && log "  \$ $DEPLOY_PHP artisan app:cadangkan-db"
  log "  \$ rm -f bootstrap/cache/*.php (basi)"
  log "  \$ $DEPLOY_PHP artisan app:sambung-public --docroot='$DOCROOT'"
  log "  \$ composer install --no-dev --optimize-autoloader"
  log "  \$ $DEPLOY_PHP artisan migrate --force"
  log "  \$ chmod -R 775 storage bootstrap/cache"
  log "  \$ $DEPLOY_PHP artisan optimize:clear && $DEPLOY_PHP artisan config:cache && $DEPLOY_PHP artisan route:cache && $DEPLOY_PHP artisan view:cache"
  log "DRY-RUN SELESAI — server tidak diubah."
  exit 0
fi

# 5. Konfirmasi ketik KIRIM (wajib terminal interaktif, kecuali --yes).
if [ "$YES" -eq 0 ]; then
  if [ ! -t 0 ]; then log "GAGAL: butuh terminal interaktif untuk konfirmasi KIRIM (atau pakai --yes bila sudah setuju eksplisit)."; exit 1; fi
  read -r -p "Ketik KIRIM untuk eksekusi sungguhan: " KONFIRM
  [ "$KONFIRM" = "KIRIM" ] || { log "Dibatalkan."; exit 1; }
else
  log "Konfirmasi KIRIM via --yes (persetujuan eksplisit sudah diberikan)."
fi

# Maintenance ON (kecuali diminta tanpa); trap menjamin UP kembali.
DID_DOWN=0
# Guard BASH_SUBSHELL: subshell $(...) mewarisi trap EXIT — tanpa guard ini
# `up` bisa jalan DINI di tengah deploy (insiden: up ganda 2026-10-01).
naikkan_up() { if [ "${BASH_SUBSHELL:-0}" -ne 0 ]; then return 0; fi; if [ "$DID_DOWN" -eq 1 ]; then log "--- artisan up ---"; run_remote "$DEPLOY_PHP artisan up" || log "DARURAT: artisan up GAGAL — situs mungkin masih down, intervensi manual!"; fi; }
trap naikkan_up EXIT
run_remote() { remote "cd '$PROJECT' && $1"; }
if [ "$MAINTENANCE" -eq 1 ]; then
  log "--- maintenance down ---"
  run_remote "$DEPLOY_PHP artisan down" || true
  DID_DOWN=1
fi

# 6. Backup DB hosting DULU (sebelum migrate).
if [ "$BACKUP" -eq 1 ]; then
  log "--- backup DB hosting ---"
  run_remote "$DEPLOY_PHP artisan app:cadangkan-db" || log "PERINGATAN: backup gagal — lanjut (migrasi bersifat aditif)."
fi

# 7. rsync sungguhan.
log "--- rsync kode ---"
rsync "${RSYNC_ARGS[@]}" -e "$RSYNC_SSH" ./ "$SSH_USER@$SSH_HOST:$PROJECT/"

# 7b. Buang sisa cache bootstrap di server (regenerable; PackageManifest
# membangun ulang dari vendor --no-dev saat dibutuhkan). Sabuk + suspender
# bersama EXCLUDES di atas (insiden Pail 2026-10-03).
log "--- buang bootstrap/cache basi ---"
run_remote "rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/routes-*.php bootstrap/cache/events.php"

# 8. Sambung public -> docroot.
log "--- sambung public ---"
run_remote "$DEPLOY_PHP artisan app:sambung-public --docroot='$DOCROOT'"

# 9. Dependensi + migrate (PERINTAH PERSIS: migrate --force, tanpa fresh/drop).
# Composer WAJIB dijalankan dgn binari PHP yg sama (default php server = 8.2,
# tak cocok dgn platform lock) — cari binernya dulu, gagalkan jelas bila tak ada.
log "--- composer install ---"
run_remote 'if command -v composer >/dev/null 2>&1; then CB=$(command -v composer); elif [ -f composer.phar ]; then CB=composer.phar; else echo BUTUH-COMPOSER-MANUAL; exit 1; fi; '"$DEPLOY_PHP"' $CB install --no-dev --optimize-autoloader'
log "--- migrate --force ---"
run_remote "$DEPLOY_PHP artisan migrate --force"
log "--- izin + cache ---"
run_remote "chmod -R 775 storage bootstrap/cache"
run_remote "$DEPLOY_PHP artisan optimize:clear && $DEPLOY_PHP artisan config:cache && $DEPLOY_PHP artisan route:cache && $DEPLOY_PHP artisan view:cache"

# 9b. Guard agregasi: latih query sensitif ONLY_FULL_GROUP_BY di DB hosting
# (SELECT saja, tanpa tulis). Gagal = pola GROUP BY tak portabel di MariaDB
# (insiden 1055 /beban-hutang 500: 2026-10-01). Trap tetap menaikkan `up`.
log "--- db:cek-agregasi (guard ONLY_FULL_GROUP_BY) ---"
run_remote "$DEPLOY_PHP artisan db:cek-agregasi" || { log "GAGAL: query agregat tak jalan di MariaDB hosting. Situs dinaikkan kembali — perbaiki pola GROUP BY lalu deploy ulang."; exit 1; }

# 10. Verifikasi HTTP (ikut redirect: root tamu 302 ke publik/login itu normal).
KODE=$(curl -sL -o /dev/null -w "%{http_code}" --max-time 30 "$DEPLOY_URL")
log "HTTP $DEPLOY_URL => $KODE"
KODE_LOGIN=$(curl -s -o /dev/null -w "%{http_code}" --max-time 30 "$DEPLOY_URL/login")
log "HTTP $DEPLOY_URL/login => $KODE_LOGIN"
[ "$KODE" = "200" ] && [ "$KODE_LOGIN" = "200" ] || { log "GAGAL: verifikasi HTTP $KODE/$KODE_LOGIN. Cek storage/logs/laravel.log di server."; exit 1; }

log "SELESAI."
