#!/bin/bash
#
# Dumps the moodle51 database and data directory into compressed archives.
# Output: dump.sql.gz and data.tar.gz in the current directory.
#

set -euo pipefail

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

echo "=== Moodle 5.2 backup ==="

echo "Dumping database '$DB_NAME'..."
mysqldump -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$DB_NAME" | gzip > dump.sql.gz
echo "Created dump.sql.gz ($(du -h dump.sql.gz | cut -f1))"

echo "Archiving dataroot '$DATAROOT'..."
rm -rf $DATAROOT/sessions/*  # Exclude session files
tar czf data.tar.gz -C "$(dirname "$DATAROOT")" "$(basename "$DATAROOT")"
echo "Created data.tar.gz ($(du -h data.tar.gz | cut -f1))"

echo "=== Done ==="
