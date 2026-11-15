#!/bin/sh
# Empties the test database and puts back a single administrator.
# Local development only — it refuses to touch anything that looks like production.
set -e
cd "$(dirname "$0")/.."
PHP=/c/xampp82/php/php.exe
PASS="Ksi-Test-2026!x"

printf '%s' "$PASS" > tests/admin-pass.txt
KSI_CONFIRM_RESET=yes "$PHP" bin/install.php reset
KSI_ADMIN_USERNAME=sysadmin KSI_ADMIN_FIRST_NAME=System KSI_ADMIN_LAST_NAME=Administrator \
  KSI_ADMIN_PHONE=255700000000 KSI_ADMIN_PASSWORD="$PASS" \
  "$PHP" bin/install.php create-admin

# The booking screens need somebody who may be named as the referring doctor.
/c/xampp82/mysql/bin/mysql.exe -uroot ksi_eye -e "
  INSERT IGNORE INTO user_designations (user_id, designation_id, assigned_at)
  SELECT u.id, d.id, UTC_TIMESTAMP(3) FROM users u, designations d
   WHERE u.username = 'sysadmin' AND d.code IN ('ophthalmologist', 'surgeon', 'optometrist');"
echo "Test database ready."
