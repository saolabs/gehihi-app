#!/usr/bin/env bash
# Deploy commit hiện tại lên production: kiểm tra, test, build, rsync, cài trên máy, kiểm tra live.
# Dùng: npm run deploy   (hoặc: bash scripts/deploy.sh)
# Máy chủ được chuẩn bị một lần: PHP 8.4-fpm (pool gehihi), PostgreSQL, supervisor (gehihi-worker), cron schedule:run,
# nginx app.gehihi.vn; .env production chỉ nằm trên máy (DEPLOY_DEST/.env), không bao giờ rsync đè.
set -euo pipefail

ORIGIN="https://app.gehihi.vn"
cd "$(dirname "$0")/.."

# Thông tin máy chủ không vào git: .deploy.env bị ignore (xem .deploy.env.example).
[[ -f .deploy.env ]] && source .deploy.env
: "${DEPLOY_HOST:?Thiếu DEPLOY_HOST, tạo .deploy.env từ .deploy.env.example}"
: "${DEPLOY_DEST:?Thiếu DEPLOY_DEST, tạo .deploy.env từ .deploy.env.example}"

# Production phải khớp một commit đã push; build đọc từ working tree.
if [[ -n "$(git status --porcelain)" ]]; then
    echo "✗ Còn thay đổi chưa commit. Commit (và push) trước khi deploy:" >&2
    git status --short >&2
    exit 1
fi
git pull --ff-only --quiet
if [[ -n "$(git log '@{u}..' --oneline 2>/dev/null)" ]]; then
    echo "✗ Còn commit chưa push. Push trước khi deploy." >&2
    exit 1
fi
REV="$(git rev-parse --short HEAD)"
echo "→ Deploy commit $REV: $(git log -1 --format=%s)"

php artisan test --compact          # cần Postgres test: docker compose up -d
npm run --silent build:all
npm run --silent test:ui

# Code + assets đã build. Không đụng dữ liệu/cấu hình của máy: .env, vendor, storage, cache.
rsync -az --delete \
    --exclude .git/ --exclude node_modules/ --exclude vendor/ --exclude .env --exclude '.env.*' --exclude .deploy.env \
    --exclude tests/ --exclude docker/ --exclude docker-compose.yml --exclude public/hot --exclude database/database.sqlite \
    --exclude 'storage/app/*' --exclude 'storage/framework/*/*' --exclude 'storage/logs/*' --exclude 'bootstrap/cache/*.php' \
    --exclude .phpunit.result.cache --exclude .codebase-memory/ \
    ./ "$DEPLOY_HOST:$DEPLOY_DEST/"

ssh "$DEPLOY_HOST" "DEST='$DEPLOY_DEST' bash -s" <<'REMOTE'
set -euo pipefail
cd "$DEST"
chown -R gehihi:gehihi "$DEST"
run() { sudo -u gehihi -H env COMPOSER_HOME=/tmp/composer-gehihi "$@"; }
run composer install --no-dev --optimize-autoloader --no-interaction --quiet
# APP_KEY chỉ sinh một lần: đổi key sẽ làm mất mọi API key provider đã mã hoá.
grep -q '^APP_KEY=base64:' .env || run php artisan key:generate --force
run php artisan migrate --force
run php artisan saola:assets
run php artisan config:cache
supervisorctl restart gehihi-worker >/dev/null
systemctl reload php8.4-fpm
REMOTE

failed=0
for path in / /api/bootstrap; do
    code="$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$ORIGIN$path")"
    echo "  $code $ORIGIN$path"
    [[ "$code" == 200 ]] || failed=1
done
((failed == 0)) || { echo "✗ Có đường dẫn không trả 200 sau khi deploy" >&2; exit 1; }
echo "✓ $ORIGIN đang chạy commit $REV"
