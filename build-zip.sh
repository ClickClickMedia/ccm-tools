#!/usr/bin/env bash
#
# Build the CCM Tools release zips.
#
#   ./build-zip.sh 8.0.0
#
# Produces, in the repo root and in archive/:
#   ccm-tools.zip                 the stable filename the auto-updater fetches
#   archive/ccm-tools-X.Y.Z.zip   the versioned copy
#   ccm-tools.zip.sha256          checksum, verified by inc/update.php on install
#
# The archive MUST contain a top-level ccm-tools/ directory. A flat zip makes
# WordPress install to a folder named after the zip file, so a manual upload
# lands at wp-content/plugins/ccm-tools-8.0.0/ next to the real ccm-tools/,
# two copies run at once and the plugin deactivates itself. That is the bug
# fixed in v7.42.1; the wrapper folder is what keeps it fixed.

set -euo pipefail

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
    echo "usage: $0 <version>   e.g. $0 8.0.0" >&2
    exit 2
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

# Refuse to build a zip whose version does not match the source, which is how
# you end up shipping a package the updater will offer again immediately.
HEADER_VERSION="$(grep -m1 '^ \* Version:' ccm.php | sed 's/.*Version:[[:space:]]*//' | tr -d '\r')"
CONST_VERSION="$(grep -m1 "define('CCM_HELPER_VERSION'" ccm.php | sed "s/.*'\([0-9][^']*\)'.*/\1/" | tr -d '\r')"

if [ "$HEADER_VERSION" != "$VERSION" ] || [ "$CONST_VERSION" != "$VERSION" ]; then
    echo "version mismatch:" >&2
    echo "  requested            $VERSION" >&2
    echo "  ccm.php header       $HEADER_VERSION" >&2
    echo "  CCM_HELPER_VERSION   $CONST_VERSION" >&2
    exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/ccm-tools"
mkdir -p "$DEST"

# Only what the plugin needs at runtime. Everything else (tests, docs, the
# maintenance notes, the archive of old builds) stays out of the package.
for item in ccm.php index.php uninstall.php css inc js img assets; do
    [ -e "$item" ] && cp -R "$item" "$DEST/"
done

# Belt and braces: strip anything that should never ship even if it was
# nested inside one of the copied directories.
find "$DEST" -name '.DS_Store' -delete
find "$DEST" -name 'Thumbs.db' -delete
find "$DEST" -name '*.map' -delete
find "$DEST" -type d -name '.git' -prune -exec rm -rf {} + 2>/dev/null || true

mkdir -p archive
rm -f ccm-tools.zip "archive/ccm-tools-$VERSION.zip" ccm-tools.zip.sha256

# Built with Python's zipfile rather than `zip` or PowerShell's Compress-Archive.
# Git Bash on Windows ships no `zip`, and Windows PowerShell 5.1's Compress-Archive
# writes entry names with backslashes, which ZipArchive on a Linux host reads as one
# long filename instead of a directory tree. Python writes correct forward-slash
# entries everywhere, so the same command works on every machine we build from.
python - "$STAGE" "$ROOT/ccm-tools.zip" <<'PYEOF'
import os, sys, zipfile
stage, out = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for folder, _dirs, files in os.walk(stage):
        for name in sorted(files):
            full = os.path.join(folder, name)
            rel = os.path.relpath(full, stage).replace(os.sep, '/')
            z.write(full, rel)
PYEOF

cp ccm-tools.zip "archive/ccm-tools-$VERSION.zip"

# The updater looks for a ccm-tools.zip.sha256 asset on the release and, when
# it finds one, refuses to install a package that does not match.
if command -v sha256sum >/dev/null 2>&1; then
    sha256sum ccm-tools.zip | awk '{print $1}' > ccm-tools.zip.sha256
else
    shasum -a 256 ccm-tools.zip | awk '{print $1}' > ccm-tools.zip.sha256
fi

echo "built ccm-tools $VERSION"
echo "  ccm-tools.zip                  $(du -h ccm-tools.zip | cut -f1)"
echo "  archive/ccm-tools-$VERSION.zip"
echo "  sha256                         $(cat ccm-tools.zip.sha256)"
echo
echo "top-level entries in the archive (must be exactly ccm-tools/):"
unzip -Z1 ccm-tools.zip | cut -d/ -f1 | sort -u | sed 's/^/  /'
echo
echo "to publish:"
echo "  gh release create v$VERSION ccm-tools.zip \"archive/ccm-tools-$VERSION.zip\" ccm-tools.zip.sha256 \\"
echo "     --title \"v$VERSION\" --notes-file <(sed -n '/^## v$VERSION/,/^## v7/p' CHANGELOG.md | head -n -1)"
