#!/bin/bash
#
# Verifies that the bundled Adyen Web SDK files in src/Resources/public/ match
# the SHA-384 hashes recorded in src/Resources/public/adyen-web-sdk.json.
# Makes no network calls. Exits 1 on any mismatch or missing file.
#
# To update the SDK, run update-web-sdk.sh instead of editing the files.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PUBLIC_DIR="$ROOT_DIR/src/Resources/public"
MANIFEST="$PUBLIC_DIR/adyen-web-sdk.json"

if [ ! -f "$MANIFEST" ]; then
    echo "Manifest not found: $MANIFEST" >&2
    exit 1
fi

VERSION="$(sed -n 's/^ *"version": *"\([^"]*\)".*/\1/p' "$MANIFEST")"
ENTRIES="$(sed -n 's/^ *"\([^"]*\/[^"]*\)": *"\(sha384-[^"]*\)".*/\1 \2/p' "$MANIFEST")"

if [ -z "$ENTRIES" ]; then
    echo "No file entries found in $MANIFEST" >&2
    exit 1
fi

echo "Verifying Adyen Web SDK ${VERSION:-unknown} against manifest"

failed=0
while read -r path expected; do
    file="$PUBLIC_DIR/$path"
    if [ ! -f "$file" ]; then
        echo "  MISSING  $path"
        failed=1
        continue
    fi
    actual="sha384-$(openssl dgst -sha384 -binary "$file" | openssl base64 -A)"
    if [ "$actual" = "$expected" ]; then
        echo "  OK       $path"
    else
        echo "  MISMATCH $path"
        echo "           expected $expected"
        echo "           actual   $actual"
        failed=1
    fi
done <<< "$ENTRIES"

if [ "$failed" -ne 0 ]; then
    echo "Bundled Adyen Web SDK does not match the manifest. Re-run update-web-sdk.sh instead of editing the files." >&2
    exit 1
fi
