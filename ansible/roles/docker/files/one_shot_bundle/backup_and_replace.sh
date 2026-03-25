#!/usr/bin/env bash

set -euo pipefail

# Run this script from the project root directory.

BACKUP_ROOT="./backup"
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP_DIR="${BACKUP_ROOT}/backup_${TIMESTAMP}"

SRC1="../ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php"
SRC2="../ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php"
SRC3="../ansible/roles/docker/files/apache/webroot/src/Views/media/list.php"

DST1="apache/webroot/src/Controllers/MediaController.php"
DST2="apache/webroot/src/Repositories/SessionRepository.php"
DST3="apache/webroot/src/Views/media/list.php"

echo "Creating backup directory: ${BACKUP_DIR}"
mkdir -p "${BACKUP_DIR}"

# Verify all source files exist
for f in "${SRC1}" "${SRC2}" "${SRC3}"; do
    if [[ ! -f "${f}" ]]; then
        echo "ERROR: Source file not found: ${f}"
        exit 1
    fi
done

# Verify all destination files exist before backing them up
for f in "${DST1}" "${DST2}" "${DST3}"; do
    if [[ ! -f "${f}" ]]; then
        echo "ERROR: Destination file not found: ${f}"
        exit 1
    fi
done

echo "Backing up destination files..."
cp -p "${DST1}" "${BACKUP_DIR}/MediaController.php"
cp -p "${DST2}" "${BACKUP_DIR}/SessionRepository.php"
cp -p "${DST3}" "${BACKUP_DIR}/list.php"

echo "Overwriting destination files with source files..."
cp -p "${SRC1}" "${DST1}"
cp -p "${SRC2}" "${DST2}"
cp -p "${SRC3}" "${DST3}"

echo "Done."
echo "Backup saved in: ${BACKUP_DIR}"
