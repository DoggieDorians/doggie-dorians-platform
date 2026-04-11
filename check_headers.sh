#!/bin/bash

BASE="https://dorianspetcare.com"

URLS=(
  "/"
  "/contact.php"
  "/login.php"
  "/admin-login.php"
  "/walker-login.php"
  "/dashboard.php"
  "/payment-portal.php"
  "/payment-success.php"
  "/payment-cancel.php"
  "/memberships.php"
  "/book-service.php"
  "/admin.php"
  "/admin-dashboard.php"
)

for path in "${URLS[@]}"; do
  echo "=================================================="
  echo "$BASE$path"
  curl -s -I -L "$BASE$path" | grep -Ei \
    '^(HTTP/|location:|content-security-policy:|strict-transport-security:|x-frame-options:|x-content-type-options:|referrer-policy:|permissions-policy:|set-cookie:)'
  echo
done
