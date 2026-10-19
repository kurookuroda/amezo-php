<?php
// 過去ログに退避されるスレッドの .lock が消えることの確認 (MAX_THREADS=2)
define('POST_INTERVAL', 0); define('GLOBAL_WINDOW', 0); define('MAX_THREADS', 2);
require __DIR__ . '/../lib.php';
$f = 'lockret'; @mkdir($f);
foreach (glob("$f/*") as $x) unlink($x);
foreach ([['261001000001', 1000], ['261001000002', 2000], ['261001000003', 3000]] as [$id, $mt]) {
    file_put_contents("$f/$id.html", "<html><head><title>t$id </title></head><body><dl><dt>1<font size=+2 color=red><b>t$id </b></font>投稿者：<font color=forestgreen><b>x </b></font><font size=-1>　投稿日：d</font><br><dd>b </dl>\n" . reply_form($id) . "</body></html>\n");
    touch("$f/$id.html", 1700000000 + $mt);
}
thread_set_lock($f, '261001000001', true);   // いちばん古いスレッドに鍵をかける
touch("$f/261001000001.html", 1700000000 + 1000);   // 鍵をかけるとファイルを書くので、更新日時を元に戻す (古いままにする)
echo 'before: ', file_exists("$f/261001000001.lock") ? 'lock あり' : 'lock なし', "\n";
regen_index($f, 1, false, false);
$ok = file_exists("$f/o261001000001.html") && !file_exists("$f/261001000001.html") && !file_exists("$f/261001000001.lock");
echo 'after : ', $ok ? 'OK  退避され、.lock も消えた' : 'NG', "\n";
echo '残り  : ', implode(' ', array_diff(scandir($f), ['.', '..'])), "\n";
foreach (glob("$f/*") as $x) unlink($x); rmdir($f);
exit($ok ? 0 : 1);
