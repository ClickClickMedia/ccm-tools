#!/usr/bin/env bash
#
# Push a built release to the CCM update service.
#
#   ./release.sh 8.10.0             stage it: uploaded, recorded, NOT yet served
#   ./release.sh 8.10.0 --publish   stage it and turn it on for the fleet
#   ./release.sh 8.10.0 --publish --security
#                                   ... and serve it to blocked sites too
#
# Staging and publishing are separate on purpose. Publishing is the deploy for
# this repo: the moment a version is published, every entitled site is offered
# it on its next check. Uploading first means the artifact can be downloaded
# and inspected before anything is pointed at it, and the service refuses to
# publish a version whose zip is not actually there.
#
# The admin credential is read from ~/.ccm-tools-release.env (chmod 600):
#
#   CCM_RELEASE_API=https://updates.clickclick.media
#   CCM_RELEASE_TOKEN=...
#
# It is deliberately not in this repo and never passed on a command line.

set -euo pipefail

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
    echo "usage: $0 <version> [--publish] [--security]" >&2
    exit 2
fi
shift || true

PUBLISH=0
SECURITY=0
for arg in "$@"; do
    case "$arg" in
        --publish)  PUBLISH=1 ;;
        --security) SECURITY=1 ;;
        *) echo "unknown option: $arg" >&2; exit 2 ;;
    esac
done

ENV_FILE="${CCM_RELEASE_ENV:-$HOME/.ccm-tools-release.env}"
if [ ! -f "$ENV_FILE" ]; then
    cat >&2 <<EOF
missing $ENV_FILE

Create it with:

  CCM_RELEASE_API=https://updates.clickclick.media
  CCM_RELEASE_TOKEN=<the ADMIN_SECRET from the ccm-tools-updates Worker>

then: chmod 600 $ENV_FILE
EOF
    exit 1
fi

# shellcheck disable=SC1090
. "$ENV_FILE"
: "${CCM_RELEASE_API:?CCM_RELEASE_API not set in $ENV_FILE}"
: "${CCM_RELEASE_TOKEN:?CCM_RELEASE_TOKEN not set in $ENV_FILE}"

ZIP="ccm-tools.zip"
ARCHIVE="archive/ccm-tools-$VERSION.zip"

if [ ! -f "$ZIP" ] || [ ! -f "$ARCHIVE" ]; then
    echo "no build for $VERSION — run ./build-zip.sh $VERSION first" >&2
    exit 1
fi

# The archive copy is the one tied to this version number; ccm-tools.zip is
# whatever was built last. Refuse if they have drifted.
if ! cmp -s "$ZIP" "$ARCHIVE"; then
    echo "$ZIP does not match $ARCHIVE — rebuild with ./build-zip.sh $VERSION" >&2
    exit 1
fi

# The version in the zip must be the version being released. Getting this
# wrong would serve the fleet a package that reports a different version than
# the one they were offered, and the update would appear to fail forever.
IN_ZIP="$(unzip -p "$ZIP" ccm-tools/ccm.php | grep -m1 '^ \* Version:' | awk '{print $3}')"
if [ "$IN_ZIP" != "$VERSION" ]; then
    echo "the zip says version $IN_ZIP but you asked to release $VERSION" >&2
    exit 1
fi

SHA="$(sha256sum "$ZIP" 2>/dev/null | awk '{print $1}' || shasum -a 256 "$ZIP" | awk '{print $1}')"
SIZE="$(wc -c < "$ZIP" | tr -d ' ')"

# Release notes: this version's section of the changelog.
NOTES="$(awk -v v="## v$VERSION " '
    index($0, v) == 1 { on = 1; next }
    on && /^## v/     { exit }
    on                { print }
' CHANGELOG.md | sed '/^[[:space:]]*$/d' | head -20)"

api() {
    local method="$1" path="$2"
    shift 2
    curl -sS --fail-with-body -m 120 -X "$method" \
        -H "Authorization: Bearer $CCM_RELEASE_TOKEN" \
        "$@" "$CCM_RELEASE_API$path"
}

echo "releasing ccm-tools $VERSION"
echo "  sha256 $SHA"
echo "  size   $SIZE bytes"

echo "  recording the release..."
python3 - "$VERSION" "$SHA" "$SIZE" "$SECURITY" "$NOTES" <<'PYEOF' > /tmp/ccm-release-meta.json
import json, sys
version, sha, size, security, notes = sys.argv[1:6]
print(json.dumps({
    "version": version,
    "sha256": sha,
    "size_bytes": int(size),
    "is_security": security == "1",
    "requires_wp": "5.8",
    "tested_wp": "6.8",
    "requires_php": "7.4",
    "notes": notes.strip(),
    "published": False,
}))
PYEOF
api POST /v1/admin/releases -H 'Content-Type: application/json' \
    --data-binary @/tmp/ccm-release-meta.json
echo

echo "  uploading the package..."
api PUT "/v1/admin/releases/$VERSION/artifact" \
    -H 'Content-Type: application/zip' --data-binary "@$ZIP"
echo

rm -f /tmp/ccm-release-meta.json

if [ "$PUBLISH" = "1" ]; then
    echo "  publishing..."
    api POST "/v1/admin/releases/$VERSION/publish" \
        -H 'Content-Type: application/json' -d '{"published":true}'
    echo
    echo "$VERSION is live. Every entitled site is offered it on its next check."
    if [ "$SECURITY" = "1" ]; then
        echo "Flagged as a security release, so blocked sites are offered it too."
    fi
else
    echo
    echo "$VERSION is staged but NOT being served."
    echo "Check it, then: ./release.sh $VERSION --publish"
fi
