#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

DEFAULT_NAME="dvashop_clean_$(date +%Y%m%d_%H%M%S).tar.gz"
OUTPUT="${1:-../$DEFAULT_NAME}"

if ! command -v git >/dev/null 2>&1; then
    echo "git is required to create a clean archive" >&2
    exit 1
fi

if [ ! -d .git ]; then
    echo "scripts/make-clean-archive.sh must be run from a git working tree" >&2
    exit 1
fi

ARTISAN_CMD=(php artisan)
if ! command -v php >/dev/null 2>&1 || [ ! -f vendor/autoload.php ]; then
    if command -v docker >/dev/null 2>&1 && docker compose ps -q app >/dev/null 2>&1; then
        ARTISAN_CMD=(docker compose exec -T app php artisan)
    else
        echo "Cannot run php artisan. Install dependencies locally or start Docker app service." >&2
        exit 1
    fi
fi

"${ARTISAN_CMD[@]}" project:check-clean-tree

git archive --format=tar.gz --prefix=dvashop/ --output="$OUTPUT" HEAD

ARCHIVE_LIST="$(mktemp)"
tar -tzf "$OUTPUT" > "$ARCHIVE_LIST"

for pattern in \
    '^dvashop/\.env$' \
    '^dvashop/\.env\.(?!example$|docker\.example$)' \
    '^dvashop/public/storage($|/)' \
    '^dvashop/public/hot($|/)' \
    '^dvashop/bootstrap/cache/(packages|services|config|events|routes.*)\.php$' \
    '\.patch$' \
    ':Zone\.Identifier$' \
    '^dvashop/vendor/' \
    '^dvashop/node_modules/'
do
    if grep -Pq "$pattern" "$ARCHIVE_LIST"; then
        echo "Clean archive contains forbidden path matching: $pattern" >&2
        grep -P "$pattern" "$ARCHIVE_LIST" >&2 || true
        rm -f "$ARCHIVE_LIST"
        exit 1
    fi
done

for required in \
    'dvashop/.env.example' \
    'dvashop/.env.docker.example' \
    'dvashop/bootstrap/cache/.gitignore' \
    'dvashop/storage/app/.gitignore' \
    'dvashop/storage/app/public/.gitignore' \
    'dvashop/storage/framework/cache/data/.gitignore' \
    'dvashop/storage/framework/sessions/.gitignore' \
    'dvashop/storage/framework/views/.gitignore' \
    'dvashop/storage/logs/.gitignore'
do
    if ! grep -Fxq "$required" "$ARCHIVE_LIST"; then
        echo "Clean archive is missing required path: $required" >&2
        rm -f "$ARCHIVE_LIST"
        exit 1
    fi
done

rm -f "$ARCHIVE_LIST"
echo "Clean archive created: $OUTPUT"
