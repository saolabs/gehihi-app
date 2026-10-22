#!/bin/sh
# DB riêng cho phpunit (RefreshDatabase xoá sạch mỗi lần chạy), không đụng dữ liệu dev.
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -c "CREATE DATABASE ${POSTGRES_DB}_testing OWNER \"$POSTGRES_USER\";"
