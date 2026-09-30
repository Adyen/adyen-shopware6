#!/bin/bash
#
# Downloads the Adyen Web SDK from the Adyen CDN into src/Resources/public/
# byte-for-byte and records the version and SHA-384 hashes in
# src/Resources/public/adyen-web-sdk.json.
#
# Usage:
#   .github/workflows/scripts/update-web-sdk.sh <version> [--expect-js sha384-...] [--expect-css sha384-...]
#
# Never edit the downloaded files by hand. The CI step verify-web-sdk.sh fails
# when a committed file no longer matches the manifest.

set -euo pipefail

usage() {
    echo "Usage: $0 <version> [--expect-js sha384-...] [--expect-css sha384-...]" >&2
    exit 2
}

[ $# -ge 1 ] || usage

VERSION="$1"
shift
EXPECT_JS=""
EXPECT_CSS=""

while [ $# -gt 0 ]; do
    case "$1" in
        --expect-js) [ $# -ge 2 ] || usage; EXPECT_JS="$2"; shift 2 ;;
        --expect-css) [ $# -ge 2 ] || usage; EXPECT_CSS="$2"; shift 2 ;;
        *) usage ;;
    esac
done

if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Invalid version '$VERSION', expected MAJOR.MINOR.PATCH" >&2
    exit 2
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PUBLIC_DIR="$ROOT_DIR/src/Resources/public"
MANIFEST="$PUBLIC_DIR/adyen-web-sdk.json"
BASE_URL="https://checkoutshopper-live.adyen.com/checkoutshopper/sdk/$VERSION/"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

sri() {
    echo "sha384-$(openssl dgst -sha384 -binary "$1" | openssl base64 -A)"
}

download() {
    echo "Downloading ${BASE_URL}$1"
    curl --proto '=https' --tlsv1.2 -fsSL -o "$TMP_DIR/$1" "${BASE_URL}$1"
}

for file in adyen.js adyen.js.map adyen.css adyen.css.map; do
    download "$file"
done

JS_HASH="$(sri "$TMP_DIR/adyen.js")"
CSS_HASH="$(sri "$TMP_DIR/adyen.css")"

if [ -n "$EXPECT_JS" ] && [ "$JS_HASH" != "$EXPECT_JS" ]; then
    echo "adyen.js hash mismatch: expected $EXPECT_JS, got $JS_HASH" >&2
    exit 1
fi

if [ -n "$EXPECT_CSS" ] && [ "$CSS_HASH" != "$EXPECT_CSS" ]; then
    echo "adyen.css hash mismatch: expected $EXPECT_CSS, got $CSS_HASH" >&2
    exit 1
fi

# Move files into place only after every download and hash check succeeded.
mv "$TMP_DIR/adyen.js" "$TMP_DIR/adyen.js.map" "$PUBLIC_DIR/js/"
mv "$TMP_DIR/adyen.css" "$TMP_DIR/adyen.css.map" "$PUBLIC_DIR/css/"

cat > "$MANIFEST" <<EOF
{
    "version": "$VERSION",
    "source": "$BASE_URL",
    "files": {
        "js/adyen.js": "$JS_HASH",
        "css/adyen.css": "$CSS_HASH"
    }
}
EOF

echo
echo "Adyen Web SDK $VERSION vendored:"
echo "  js/adyen.js   $JS_HASH"
echo "  css/adyen.css $CSS_HASH"
echo "Manifest written to src/Resources/public/adyen-web-sdk.json"
