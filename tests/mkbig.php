<?php
// 大きなスレッド1本 (5000レス) と 200本のスレッドを持つ板を作る
require __DIR__ . '/../lib.php';
$res = '260101000000';
$fp = fopen("big/$res.html", 'w');
$cnt = 5000;
for ($i = 1; $i <= $cnt; $i++) {
    $head = $i === 1 ? '<html><head><meta charset="UTF-8"><title>big </title><link rel="stylesheet" href="../style.css">' . SCRIPT_TAG . '</head><body>' : '';
    $subj2 = $i === 1 ? '<font size=+2 color=red><b>big </b></font>' : '';
    fwrite($fp, "{$head}<dl><dt>{$i}{$subj2}投稿者：<font color=forestgreen><b>n{$i} </b></font><font size=-1 class=\"meta\" data-id=\"abcdefgh\">　投稿日：2026年10月16日(金)12時00分00秒 ID:abcdefgh</font><br><dd>" . str_repeat('あ', 300) . " </dl>\n");
}
fwrite($fp, reply_form($res) . "</body></html>\n");
fclose($fp);
for ($k = 0; $k < 200; $k++) {
    $r = sprintf('26%010d', 1000000 + $k);
    file_put_contents("big/$r.html", "<html><head><title>t$k </title></head><body><dl><dt>1<font size=+2 color=red><b>t$k </b></font>投稿者：<font color=forestgreen><b>x </b></font><font size=-1>　投稿日：d</font><br><dd>本文$k </dl>\n" . reply_form($r) . "</body></html>\n");
    touch("big/$r.html", 1700000000 + $k);
}
echo filesize("big/$res.html"), " bytes\n";
