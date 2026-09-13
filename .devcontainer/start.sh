#!/bin/bash

set -e

echo "Starting MariaDB..."

sudo service mariadb start

echo "Creating database..."

sudo mariadb -e "
CREATE DATABASE IF NOT EXISTS vendor_cat_mobil
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
"

echo "Importing database..."

if [ -f /workspaces/Web_Vendor_Cat/vendor_cat_mobil.sql ]; then
    sudo mariadb vendor_cat_mobil < /workspaces/Web_Vendor_Cat/vendor_cat_mobil.sql
fi

echo "Database ready."