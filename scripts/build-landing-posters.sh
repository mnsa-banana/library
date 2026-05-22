#!/usr/bin/env bash
# One-shot pipeline:
#   1. Query Postgres for the top 112 kid-safe Netflix poster URLs.
#   2. Download the source images into storage/app/landing-posters-raw/.
#   3. Composite into a 16×7 sprite, encode WebP -q 75 → public/img/poster-field/sprite.webp.
# Requirements: psql, curl, cwebp, magick (brew install webp imagemagick). Run from repo root.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

# Load DB credentials from .env.
[[ -f .env ]] || { echo "ERROR: .env not found at $REPO_ROOT/.env" >&2; exit 1; }
env_value() { grep -E "^$1=" .env | head -n1 | cut -d= -f2- | tr -d "'\""; }
DB_HOST="$(env_value DB_HOST)"
DB_PORT="$(env_value DB_PORT)"
DB_DATABASE="$(env_value DB_DATABASE)"
DB_USERNAME="$(env_value DB_USERNAME)"
DB_PASSWORD="$(env_value DB_PASSWORD)"

# Tool checks.
for tool in psql curl cwebp magick; do
  command -v "$tool" >/dev/null || { echo "ERROR: $tool not found in PATH" >&2; exit 1; }
done

# Directories.
RAW_DIR="$REPO_ROOT/storage/app/landing-posters-raw"
OUT_DIR="$REPO_ROOT/public/img/poster-field"
mkdir -p "$RAW_DIR" "$OUT_DIR"

# Clean slate for the output directory.
rm -f "$OUT_DIR"/poster-*.webp "$OUT_DIR"/sprite.webp

# Query for the URLs.
SQL=$(cat <<'EOF'
SELECT poster_url
FROM streaming_titles t
WHERE EXISTS (
  SELECT 1 FROM streaming_title_offers o
  WHERE o.title_id = t.id AND o.service_id = 'netflix' AND o.region = 'US'
)
  AND t.us_certification IN ('G', 'PG', 'TV-Y', 'TV-Y7', 'TV-G')
  AND t.poster_url IS NOT NULL AND t.poster_url != ''
  AND t.deleted_at IS NULL
ORDER BY t.rating DESC NULLS LAST
LIMIT 112;
EOF
)

URLS=()
while IFS= read -r line; do
  URLS+=("$line")
done < <(PGPASSWORD="$DB_PASSWORD" psql \
  -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" \
  -At -c "$SQL")

if [[ ${#URLS[@]} -ne 112 ]]; then
  echo "ERROR: expected 112 URLs, got ${#URLS[@]}" >&2
  exit 1
fi

echo "Downloading 112 posters…"
for i in "${!URLS[@]}"; do
  n=$(printf "%03d" $((i + 1)))
  url="${URLS[$i]}"
  out="$RAW_DIR/poster-$n.jpg"
  curl -sS --fail --retry 3 -o "$out" "$url"
done

echo "Resizing tiles to 148×216 (cover-crop)…"
rm -f "$RAW_DIR"/poster-*.tile.png
for i in $(seq 1 112); do
  n=$(printf "%03d" "$i")
  magick "$RAW_DIR/poster-$n.jpg" -resize '148x216^' -gravity center -extent 148x216 +repage "$RAW_DIR/poster-$n.tile.png"
done

echo "Compositing 16×7 sprite (PNG)…"
magick montage "$RAW_DIR"/poster-???.tile.png -tile 16x7 -geometry +0+0 PNG24:"$RAW_DIR/sprite.png"

echo "Compressing sprite to WebP (q75)…"
cwebp -quiet -q 75 "$RAW_DIR/sprite.png" -o "$OUT_DIR/sprite.webp"

ls -lh "$OUT_DIR/sprite.webp"
echo "Done."
