#!/bin/bash
#
# Build a Debian source package for upload to the Launchpad PPA
# (https://launchpad.net/~zabuch/+archive/ubuntu/ppa).
#
# Usage:
#   bin/build-deb-source.sh            # signed, ready for dput (default)
#   bin/build-deb-source.sh -us -uc    # unsigned, for local testing
#
# Extra arguments are passed straight to debuild.
#
# The .orig.tar.gz is created from the current working tree, INCLUDING
# vendor/ (which is not tracked in git), because Launchpad builders have
# no network access and must compile the phar offline.
#
# After a successful signed build, upload with:
#   dput ppa:zabuch/ppa ../moosh2_<version>_source.changes
#
# @copyright  2012 onwards Tomasz Muras
# @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

set -e

cd "$(dirname "$0")/.."

VERSION=$(grep -oP "const VERSION = '\K[^']+" src/Application.php)
if [ -z "$VERSION" ]; then
    echo "Error: could not detect version from src/Application.php" >&2
    exit 1
fi

if [ ! -d vendor ]; then
    echo "Error: vendor/ is missing. Run first:" >&2
    echo "  composer install --no-dev --classmap-authoritative" >&2
    exit 1
fi

ORIG="../moosh2_${VERSION}.orig.tar.gz"

echo "Creating ${ORIG} (upstream version ${VERSION}, includes vendor/)"
tar --exclude-vcs \
    --exclude='./debian' \
    --exclude='./.claude' \
    --exclude='./.idea' \
    --exclude='./.github' \
    --exclude='./bin/moosh.phar' \
    --exclude='./tests' \
    --transform "s,^\.,moosh2-${VERSION},S" \
    -czf "${ORIG}" .

# -S: source-only (Launchpad rejects binary uploads)
# -sa: always include the orig tarball in the upload
debuild -S -sa "$@"
