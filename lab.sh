#!/usr/bin/env bash
# Lệnh tiện ích, cùng quy ước với các bài Lab của môn học.
#   bash lab.sh check | sample | seed | run | test
set -u
cd "$(dirname "$0")"
PORT="${PORT:-8108}"
export GTFS_DB="${GTFS_DB:-$PWD/data/gtfs.sqlite}"

need_php() {
  command -v php >/dev/null || { echo "THIEU=php (cai: sudo apt install php-cli php-sqlite3 php-zip php-mbstring)"; exit 1; }
}

case "${1:-help}" in
  check)
    need_php
    ok=1
    php -r 'exit(version_compare(PHP_VERSION,"8.1.0",">=")?0:1);' && echo "PHP=$(php -r 'echo PHP_VERSION;')" || { echo "PHP can >= 8.1"; ok=0; }
    for ext in pdo_sqlite zip mbstring; do
      php -m | grep -qix "$ext" && echo "EXT_$ext=OK" || { echo "EXT_$ext=THIEU"; ok=0; }
    done
    bad=0; for f in $(find src public tests tools -name '*.php'); do php -l "$f" >/dev/null 2>&1 || { echo "LOI_CU_PHAP=$f"; bad=1; }; done
    [ $bad = 0 ] && echo "CU_PHAP=OK" || ok=0
    [ $ok = 1 ] && echo "CHECK_ENV=PASS" || { echo "CHECK_ENV=FAIL"; exit 1; }
    ;;
  sample)
    need_php; php tools/make_sample.php data/sample ;;
  seed)
    need_php
    php tools/make_sample.php data/sample >/dev/null
    php tools/seed.php ;;
  run)
    need_php
    [ -f data/sample/gtfs_v1.zip ] || php tools/make_sample.php data/sample >/dev/null
    if [ -z "${GTFS_ADMIN_PASSWORD:-}" ]; then
      GTFS_ADMIN_PASSWORD="$(head -c 9 /dev/urandom | od -An -tx1 | tr -d ' \n')"
      export GTFS_ADMIN_PASSWORD
      echo "Chua dat GTFS_ADMIN_PASSWORD -> mat khau quan ly tam thoi (chi dung cho lan chay nay): $GTFS_ADMIN_PASSWORD"
    fi
    echo "Mo http://127.0.0.1:$PORT"
    php -S "127.0.0.1:$PORT" -t public public/index.php ;;
  test)
    need_php
    php tests/run.php; rc=$?
    bash tests/smoke.sh; rc2=$?
    exit $((rc || rc2)) ;;
  *)
    echo "Dung: bash lab.sh [check|sample|seed|run|test]"
    echo "  check   kiem tra moi truong va cu phap"
    echo "  sample  tao du lieu mau (data/sample/*.zip)"
    echo "  seed    tao CSDL, nhap v1 + v2 + ban loi, chon v1 lam ban dang dung, luu 3 truy van mau"
    echo "  run     chay web tai http://127.0.0.1:$PORT"
    echo "  test    chay kiem thu tu dong va kiem thu tich hop web" ;;
esac
