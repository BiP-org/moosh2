#!/bin/bash
#
# Clears the Moodle database and dataroot, then restores from dump files.
# Supports Moodle 5.2+ via MOODLE_DIR environment variable.
# Expects dump.sql.gz and data.tar.gz in the test_data_setup directory.
#
# Usage:
#   MOODLE_DIR=/var/www/html/moodle52 bash clear.sh
#

set -euo pipefail

if [ -z "${MOODLE_DIR:-}" ]; then
    echo "ERROR: MOODLE_DIR environment variable is not set."
    exit 1
fi

if [ ! -d "$MOODLE_DIR" ]; then
    echo "ERROR: Directory $MOODLE_DIR does not exist."
    exit 1
fi

VERSION_FILE="$MOODLE_DIR/public/version.php"
if [ ! -f "$VERSION_FILE" ]; then
    echo "ERROR: $VERSION_FILE not found."
    exit 1
fi

BRANCH=$(grep -oP "\\\$branch\s*=\s*'\K[0-9]+" "$VERSION_FILE")
if [ -z "$BRANCH" ]; then
    echo "ERROR: Could not detect branch from $VERSION_FILE."
    exit 1
fi

# Convert branch '501' -> 'moodle51', '502' -> 'moodle52'
MOODLE_MAJOR="${BRANCH:0:1}"
MOODLE_MINOR="${BRANCH:2:1}"
MOODLE_VERSION="moodle${MOODLE_MAJOR}${MOODLE_MINOR}"

CONFIG_FILE="${CONFIG_FILE:-$MOODLE_DIR/config.php}"
if [ ! -f "$CONFIG_FILE" ]; then
    echo "ERROR: $CONFIG_FILE not found."
    exit 1
fi

DB_NAME=$(grep -oP "\\\$CFG->dbname\s*=\s*'\K[^']+" "$CONFIG_FILE")
DB_USER=$(grep -oP "\\\$CFG->dbuser\s*=\s*'\K[^']+" "$CONFIG_FILE")
DB_PASS=$(grep -oP "\\\$CFG->dbpass\s*=\s*'\K[^']+" "$CONFIG_FILE")
DB_HOST=$(grep -oP "\\\$CFG->dbhost\s*=\s*'\K[^']+" "$CONFIG_FILE")
DATAROOT="${DATAROOT:-/opt/data/$MOODLE_VERSION}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MYSQL_OPTS="${MYSQL_OPTS:-}"
SETUP_DIR="$SCRIPT_DIR/test_data_setup_${MOODLE_VERSION}"
DUMP_FILE="$SETUP_DIR/dump.sql.gz"
DATA_FILE="$SETUP_DIR/data.tar.gz"
# current shell user
USER=$(whoami)

# Check dump files exist.
if [[ ! -f "$DUMP_FILE" ]]; then
    echo "ERROR: $DUMP_FILE not found. Run dump.sh first."
    exit 1
fi
if [[ ! -f "$DATA_FILE" ]]; then
    echo "ERROR: $DATA_FILE not found. Run dump.sh first."
    exit 1
fi

TOTAL_START=$SECONDS

echo "=== $MOODLE_VERSION clear & restore ==="

# Drop and recreate database.
echo "Dropping and recreating database '$DB_NAME'..."
STEP_START=$SECONDS
mysql $MYSQL_OPTS -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" <<SQL
DROP DATABASE IF EXISTS \`$DB_NAME\`;
CREATE DATABASE \`$DB_NAME\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL
echo "Database recreated. ($((SECONDS - STEP_START))s)"

# Restore database from dump.
echo "Restoring database from dump.sql.gz..."
STEP_START=$SECONDS
gunzip -c "$DUMP_FILE" | mysql $MYSQL_OPTS --abort-source-on-error -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$DB_NAME"
echo "Database restored. ($((SECONDS - STEP_START))s)"

# Clear dataroot.
echo "Clearing dataroot '$DATAROOT'..."
STEP_START=$SECONDS
sudo rm -rf "${DATAROOT:?}"/*
echo "Dataroot cleared. ($((SECONDS - STEP_START))s)"

# Restore dataroot from archive.
echo "Restoring dataroot from data.tar.gz..."
STEP_START=$SECONDS
# data.tar.gz was created with `tar -C "$(dirname DATAROOT)" "$(basename DATAROOT)"`
# at dump time, so it contains a single top-level directory named whatever
# DATAROOT's basename was back then (eg. "moodle52") - which does not
# necessarily match this restore's $DATAROOT (eg. CI's ".../moodledata").
# Extract into a scratch directory and use the archive's own top-level
# entry name, rather than assuming it matches $(basename "$DATAROOT").
RESTORE_TMP="$(mktemp -d)"
trap 'rm -rf "$RESTORE_TMP"' EXIT

sudo tar -xzf "$DATA_FILE" -C "$RESTORE_TMP"

ARCHIVE_TOP=$(find "$RESTORE_TMP" -mindepth 1 -maxdepth 1 -type d | head -n1)
if [ -z "$ARCHIVE_TOP" ]; then
    echo "ERROR: $DATA_FILE did not contain a top-level directory as expected."
    exit 1
fi

mkdir -p "$(dirname "$DATAROOT")"
sudo rm -rf "$DATAROOT"
sudo mv "$ARCHIVE_TOP" "$DATAROOT"
rm -rf "$RESTORE_TMP"
trap - EXIT

sudo chown -R "$USER" "$DATAROOT"
echo "Dataroot restored. ($((SECONDS - STEP_START))s)"

echo "=== Done in $((SECONDS - TOTAL_START))s ==="
