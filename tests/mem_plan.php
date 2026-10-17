<?php
// 「ここがメモリを食うはず」と言われた箇所を、実際に測る (板 big は php tests/mkbig.php で作る)
// 使い方: php tests/mem_plan.php   (many=10万スレッド、huge=古いスレッド5万本の板を、無ければ作る)
require __DIR__ . '/../lib.php';
if (!is_dir('many')) { mkdir('many'); for ($i = 0; $i < 100000; $i++) touch(sprintf('many/27%010d.html', $i)); }
if (!is_dir('huge')) { mkdir('huge'); for ($i = 0; $i < 50000; $i++) touch(sprintf('huge/o26%010d.html', $i)); for ($k = 0; $k < 200; $k++) touch(sprintf('huge/27%010d.html', $k)); }
function show(string $label, callable $f): void {
    $base = memory_get_usage(); memory_reset_peak_usage(); $t = microtime(true);
    $f();
    printf("%-58s peak増分 %7.2f MB  %6.0f ms\n", $label, (memory_get_peak_usage() - $base) / 1048576, (microtime(true) - $t) * 1000);
}
// (a) 表示中のスレッド一覧: 200本 (MAX_THREADS の既定) と、上限なしで10万本
show('list_threads: 表示中200本 (big 板)',          fn() => list_threads('big'));
show('list_threads: 表示中10万本 (上限なしの場合)', fn() => list_threads('many'));
show('  └ array_slice で20件にしても、上の配列は先にできている', function () { $f = list_threads('many')[0]; $w = array_slice($f, 0, 20); });
// 窓だけ持つ版 (参考): 上位 K 件だけ残しながら読む
function list_window(string $folder, int $k): array {
    $keep = []; $d = opendir("./$folder");
    while (($f = readdir($d)) !== false) {
        if (!preg_match('/^\d+\.html$/', $f)) continue;
        $keep[] = [sprintf('%011d', (int)filemtime("./$folder/$f")), $f];
        if (count($keep) > $k * 2) { usort($keep, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]); $keep = array_slice($keep, 0, $k); }
    }
    closedir($d); usort($keep, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
    return array_slice($keep, 0, $k);
}
show('参考: 上位120件だけ残す読み方 (10万本)',      fn() => list_window('many', 120));
// 管理画面のスレッド一覧: 古いスレッド5万本の板
// admin.php は読み込むと実行されてしまうので、一覧を作る関数だけを取り出す
$adm = file_get_contents(__DIR__ . '/../admin.php');
eval(substr($adm, strpos($adm, 'function admin_list_threads'), strpos($adm, 'function decode_text') - strpos($adm, 'function admin_list_threads')));
show('admin_list_threads: 古いスレッド5万本の板 (scandir)', fn() => admin_list_threads('huge'));
// NG ワード: 10000行の ng.txt を file() で読む
file_put_contents('/tmp/ng_big.txt', implode("\n", array_map(fn($i) => "ngword{$i},c", range(1, 10000))) . "\n");
show('file(ng.txt 10000行)',                       fn() => file('/tmp/ng_big.txt', FILE_IGNORE_NEW_LINES));
show('fgets で1行ずつ (参考)',                      function () { $h = fopen('/tmp/ng_big.txt', 'r'); while (($l = fgets($h)) !== false) { $x = rtrim($l, "\r\n"); } fclose($h); });
