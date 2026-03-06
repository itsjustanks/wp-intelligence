#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="wp-intelligence"
PLUGIN_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DISTIGNORE="$PLUGIN_ROOT/.distignore"

VERSION=$(grep -m1 "define('WPI_VERSION'" "$PLUGIN_ROOT/wp-intelligence.php" | sed "s/.*'\([0-9.]*\)'.*/\1/")
if [ -z "$VERSION" ]; then
  echo "Error: could not extract version from wp-intelligence.php"
  exit 1
fi

OUT_DIR="$PLUGIN_ROOT/dist"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"

echo "Packaging ${PLUGIN_SLUG} v${VERSION}..."

# Build webpack assets.
echo "→ Running npm build..."
cd "$PLUGIN_ROOT"
npm ci --silent
npm run build

# Prepare output directory.
rm -rf "$OUT_DIR"
mkdir -p "$OUT_DIR"

# Build the exclude args from .distignore.
EXCLUDE_ARGS=()
while IFS= read -r line; do
  line="${line%%#*}"      # strip inline comments
  line="${line## }"       # trim leading space
  line="${line%% }"       # trim trailing space
  [ -z "$line" ] && continue
  EXCLUDE_ARGS+=("--exclude=${PLUGIN_SLUG}/${line}" "--exclude=${PLUGIN_SLUG}/${line}/*")
done < "$DISTIGNORE"

# Also exclude the dist and scripts directories from the zip.
EXCLUDE_ARGS+=("--exclude=${PLUGIN_SLUG}/dist/*" "--exclude=${PLUGIN_SLUG}/scripts/*")

# Create the zip from the parent directory so the archive root is wp-intelligence/.
cd "$PLUGIN_ROOT/.."
zip -r "$OUT_DIR/$ZIP_NAME" "$PLUGIN_SLUG/" "${EXCLUDE_ARGS[@]}" -x "*.DS_Store"

ZIP_SIZE=$(du -h "$OUT_DIR/$ZIP_NAME" | cut -f1)
echo ""
echo "✓ Created dist/${ZIP_NAME} (${ZIP_SIZE})"
