#!/bin/bash
# ページ送りの確認: 実在するページにしか飛ばない / 範囲外は最後のページ / 1ページだけの板ではページ送りを出さない
# 前提: http://127.0.0.1:8123 で router.php を起動済み。板 big (php tests/mkbig.php で200スレッド=10ページ) と、スレッドが少ない板 main がある
B=http://127.0.0.1:8123; DIR=${1:-/tmp/phpt}; bad=0
go() { # 板 クエリ -> 転送先のファイル名
  curl -s -i "$B/amezo.cgi?folder=$1&$2" -H 'CF-Connecting-IP: 8.2.2.2' | grep -i '^location' | tr -d '\r' | sed "s|Location: ../$1/||"
}
check() { # 説明 実際 期待
  if [ "$2" = "$3" ]; then echo "OK   $1 -> $2"; else echo "NG   $1 -> $2 (期待: $3)"; bad=$((bad+1)); fi
}
pager_text() { grep -o '<div class="row links pager">.*</div>' "$1" | head -1 | sed 's/<a href="[^"]*">//g; s/<\/a>//g; s/<[^>]*>//g'; }
rm -f $DIR/big/index[0-9]*.html
check "big page=1"            "$(go big page=1)"     index.html
check "big page=2"            "$(go big page=2)"     index2.html
check "big page=3"            "$(go big page=3)"     index3.html
check "big page=10 (最後)"     "$(go big page=10)"    index10.html
check "big page=11 (範囲外)"   "$(go big page=11)"    index10.html
check "big page=500 (範囲外)"  "$(go big page=500)"   index10.html
check "big page=0"            "$(go big page=0)"     index.html
check "big page=abc"          "$(go big page=abc)"   index.html
check "big page なし"          "$(go big x=1)"        index.html
check "main page=500 (1ページだけ)" "$(go main page=500)" index.html
check "古い next=21 (21番目から) は2ページ目" "$(go big next=21)" index2.html
check "古い next=99999 は最後のページ"        "$(go big next=99999)" index10.html
for f in index.html index2.html index10.html; do
  check "big/$f の本文スレッド数" "$(grep -c '<table border=1' $DIR/big/$f)" 20
done
check "pager が上下に出る (big)"  "$(grep -c 'class="row links pager"' $DIR/big/index.html)" 2
check "pager が出ない (main)"     "$(grep -c 'class="row links pager"' $DIR/main/index.html)" 0
check "古い『次のページ』欄が無い" "$(grep -c '次のページ' $DIR/big/index.html)" 0
check "1ページ目: 前へ が無い"     "$(pager_text $DIR/big/index.html)" "[1] 2 3 4 5 6 7 8 9 10　次の20件 →"
check "3ページ目: 前へ も 次へ も" "$(pager_text $DIR/big/index3.html)" "← 前の20件　1 2 [3] 4 5 6 7 8 9 10　次の20件 →"
check "最後のページ: 次へ が無い"  "$(pager_text $DIR/big/index10.html)" "← 前の20件　1 2 3 4 5 6 7 8 9 [10]"
check "現在のページは [ ] で囲み、リンクにしない" "$(grep -o '<b>\[3\]</b>' $DIR/big/index3.html | head -1)" '<b>[3]</b>'
check "2ページ目のリンク先"       "$(grep -o 'href="[^"]*page=2"' $DIR/big/index.html | head -1)" 'href="../amezo.cgi?folder=big&amp;page=2"'
check "生成される index*.html の数 (ページ数-1+1)" "$(ls $DIR/big | grep -cE '^index[0-9]*\.html$')" 4
echo "NG の数: $bad"; exit $((bad>0))
