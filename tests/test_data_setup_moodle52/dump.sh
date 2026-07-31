#!/bin/bash
#
# Dumps the moodle52 database and data directory into compressed archives.
# Output: dump.sql.gz and data.tar.gz in the current directory.
#

set -euo pipefail

DATAROOT="${DATAROOT:-/opt/data/$MOODLE_VERSION}"
CONFIG_FILE="${CONFIG_FILE:-$MOODLE_DIR/config.php}"

echo "=== Moodle backup ==="

echo "MOODLE_DIR: $MOODLE_DIR"
echo "DATAROOT: $DATAROOT"
echo "CONFIG_FILE: $CONFIG_FILE"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "ERROR: $CONFIG_FILE not found."
    exit 1
fi

DB_NAME=$(grep -oP "\\\$CFG->dbname\s*=\s*'\K[^']+" "$CONFIG_FILE")
DB_USER=$(grep -oP "\\\$CFG->dbuser\s*=\s*'\K[^']+" "$CONFIG_FILE")
DB_PASS=$(grep -oP "\\\$CFG->dbpass\s*=\s*'\K[^']+" "$CONFIG_FILE")
DB_HOST=$(grep -oP "\\\$CFG->dbhost\s*=\s*'\K[^']+" "$CONFIG_FILE")


echo "Dumping database '$DB_NAME'..."
mysqldump $MYSQLDUMP_OPTS -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$DB_NAME" | gzip > dump.sql.gz
echo "Created dump.sql.gz ($(du -h dump.sql.gz | cut -f1))"

echo "Archiving dataroot '$DATAROOT'..."
rm -rf $DATAROOT/sessions/*  # Exclude session files
tar czf data.tar.gz -C "$(dirname "$DATAROOT")" "$(basename "$DATAROOT")"
echo "Created data.tar.gz ($(du -h data.tar.gz | cut -f1))"

echo "=== Done ==="
