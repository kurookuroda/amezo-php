#!/bin/sh
# 使い方: sh run.sh   (PORT=8000 WORKERS=2 で変えられる)
cd "$(dirname "$0")" || exit 1
# blist.txt の板フォルダを作る。index.html が無ければ転送ページを置く
cut -d, -f1 blist.txt | while read -r b; do
  [ -z "$b" ] && continue
  mkdir -p "$b"
  [ -f "$b/index.html" ] || printf '<html><head><meta http-equiv="refresh" content="0;url=../amezo.cgi?folder=%s"></head></html>\n' "$b" > "$b/index.html"
done
echo "http://127.0.0.1:${PORT:-8000}/"
PHP_CLI_SERVER_WORKERS="${WORKERS:-2}" exec php -S "127.0.0.1:${PORT:-8000}" router.php
