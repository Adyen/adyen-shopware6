#!/bin/bash
#
# Builds the Shopware 6 plugin ZIP.
# Used by the upload-release-asset.yml workflow and for manual builds.
#
# Usage (from anywhere inside the repository):
#   .github/workflows/scripts/prepare-release-asset.sh [git-ref]
#   .github/workflows/scripts/prepare-release-asset.sh --dev
#
# Release build (default): AdyenPaymentShopware6.zip from the committed files of
# git-ref (default HEAD) only, so uncommitted changes, untracked files and IDE
# folders (.idea, ...) never end up in a release.
#
# Dev build (--dev): AdyenPaymentShopware6-dev.zip from the working tree, for
# local testing. Contains every tracked file with its uncommitted changes plus
# new untracked files under src/. Never publish a dev ZIP.
#
# In both cases vendor/ is installed from the committed composer.lock, and the
# build fails if the Adyen packages differ from it. The ZIP is written to the
# repository root.

set -euo pipefail

PLUGIN_NAME="AdyenPaymentShopware6"

usage() {
    echo "Usage: $0 [git-ref] | --dev" >&2
    exit 2
}

DEV=0
REF="HEAD"
case "$#" in
    0) ;;
    1) if [ "$1" = "--dev" ]; then DEV=1; elif [ "${1#-}" = "$1" ]; then REF="$1"; else usage; fi ;;
    *) usage ;;
esac

ROOT_DIR="$(git -C "$(dirname "${BASH_SOURCE[0]}")" rev-parse --show-toplevel)"
COMMIT="$(git -C "$ROOT_DIR" rev-parse --verify "$REF^{commit}")"

if [ "$DEV" -eq 1 ]; then
    ZIP_NAME="$PLUGIN_NAME-dev.zip"
else
    ZIP_NAME="$PLUGIN_NAME.zip"
fi
OUTPUT="$ROOT_DIR/$ZIP_NAME"

BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "$BUILD_DIR"' EXIT
PLUGIN_DIR="$BUILD_DIR/$PLUGIN_NAME"
mkdir -p "$PLUGIN_DIR"

if [ "$DEV" -eq 1 ]; then
    MODIFIED="$(git -C "$ROOT_DIR" status --porcelain --untracked-files=no | wc -l)"
    UNTRACKED="$(git -C "$ROOT_DIR" ls-files --others --exclude-standard -- src/ | wc -l)"
    echo "Building $ZIP_NAME from working tree (based on $COMMIT, $MODIFIED modified, $UNTRACKED untracked in src/)"

    # Tracked files with their current content (locally deleted files are
    # skipped), plus new untracked files under src/. Ignored files such as
    # vendor/ and .idea/ are never included.
    {
        git -C "$ROOT_DIR" ls-files -z --cached
        git -C "$ROOT_DIR" ls-files -z --others --exclude-standard -- src/
    } | while IFS= read -r -d '' file; do
        [ -e "$ROOT_DIR/$file" ] && printf '%s\0' "$file"
    done | tar -C "$ROOT_DIR" --null -T - -cf - | tar -x -C "$PLUGIN_DIR"
else
    echo "Building $ZIP_NAME from $REF ($COMMIT)"
    if [ "$REF" = "HEAD" ] && [ -n "$(git -C "$ROOT_DIR" status --porcelain --untracked-files=no)" ]; then
        echo "Warning: uncommitted changes are NOT included in the ZIP, it is built from the last commit. Use --dev for a local test build." >&2
    fi

    # Export committed files with line endings exactly as stored in git
    # (ignores the local core.autocrlf setting).
    git -C "$ROOT_DIR" -c core.autocrlf=false archive --format=tar --prefix="$PLUGIN_NAME/" "$COMMIT" \
        | tar -x -C "$BUILD_DIR"
fi

# Check the bundled Adyen Web SDK against its manifest, if this version has one.
if [ -f "$PLUGIN_DIR/.github/workflows/scripts/verify-web-sdk.sh" ]; then
    bash "$PLUGIN_DIR/.github/workflows/scripts/verify-web-sdk.sh"
fi

# Keep the original composer files, the ZIP ships them unchanged.
cp "$PLUGIN_DIR/composer.json" "$BUILD_DIR/composer.json.orig"
cp "$PLUGIN_DIR/composer.lock" "$BUILD_DIR/composer.lock.orig"

# Shopware is provided by the shop at runtime, so it must not be in vendor/.
# Removing it only drops Shopware and its dependencies from the lock; all other
# packages stay on their locked versions. Plugins and scripts are disabled so no
# third-party code runs during the build. Required PHP extensions are checked by
# the shop at install time, not on the build machine.
composer remove shopware/core shopware/storefront \
    --working-dir="$PLUGIN_DIR" \
    --update-no-dev \
    --no-plugins \
    --no-scripts \
    --ignore-platform-req="ext-*" \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

# Fail if any shipped package differs from the committed lock.
php -r '
    $locked = [];
    foreach (json_decode(file_get_contents($argv[1]), true)["packages"] as $p) {
        $locked[$p["name"]] = $p["source"]["reference"] ?? $p["dist"]["reference"] ?? $p["version"];
    }
    $installed = json_decode(file_get_contents($argv[2]), true);
    $installed = $installed["packages"] ?? $installed;
    $failed = false;
    foreach ($installed as $p) {
        $ref = $p["source"]["reference"] ?? $p["dist"]["reference"] ?? $p["version"];
        if (!isset($locked[$p["name"]]) || $locked[$p["name"]] !== $ref) {
            fwrite(STDERR, "Not matching composer.lock: {$p["name"]} {$p["version"]}\n");
            $failed = true;
        } else {
            echo "  shipped {$p["name"]} {$p["version"]}\n";
        }
    }
    exit($failed ? 1 : 0);
' "$BUILD_DIR/composer.lock.orig" "$PLUGIN_DIR/vendor/composer/installed.json"

cp "$BUILD_DIR/composer.json.orig" "$PLUGIN_DIR/composer.json"
cp "$BUILD_DIR/composer.lock.orig" "$PLUGIN_DIR/composer.lock"

# Files that are not part of the plugin.
rm -rf "$PLUGIN_DIR/.github" "$PLUGIN_DIR/.gitignore" "$PLUGIN_DIR/.gitattributes" "$PLUGIN_DIR/Dockerfile"

rm -f "$OUTPUT"
(cd "$BUILD_DIR" && zip -qr -X "$OUTPUT" "$PLUGIN_NAME" -x '*/.git*' '*/.idea/*' '*/.DS_Store' '*/__MACOSX/*')

echo
echo "Created $OUTPUT"
echo "  files:  $(unzip -Z1 "$OUTPUT" | grep -vc '/$')"
echo "  sha256: $(sha256sum "$OUTPUT" | cut -d' ' -f1)"
