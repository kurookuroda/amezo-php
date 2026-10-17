<?php
// 古いスレッド(o*.html)が大量にある板での、再生成と、ページ送りの数え上げのメモリ・時間
require __DIR__ . '/../lib.php';
$folder = $argv[1] ?? 'huge';
function show(string $label, callable $f): void {
    $base = memory_get_usage();
    memory_reset_peak_usage();
    $t = microtime(true);
    $r = $f();
    printf("%-44s peak増分 %.2f MB  %.0f ms\n", $label, (memory_get_peak_usage() - $base) / 1048576, (microtime(true) - $t) * 1000);
    return;
}
show('count_threads (ページ送りの範囲確認)', fn() => count_threads($GLOBALS['folder']));
show('page_clamp(page=999)', fn() => page_clamp($GLOBALS['folder'], 999));
show('regen 1ページ目 (初回: kako.html を作る)', fn() => regen_index($GLOBALS['folder'], 1, false, false));
show('regen 1ページ目 (通常: kako.txt/html あり)', fn() => regen_index($GLOBALS['folder'], 1, false, false));
show('regen 2ページ目', fn() => regen_index($GLOBALS['folder'], 2, false, false));
@unlink("./{$folder}/kako.txt");
show('regen 1ページ目 (kako.txt が無く作り直し)', fn() => regen_index($GLOBALS['folder'], 1, false, false));
printf("kako.txt: %d 行, kako.html: %d 行\n", count(file("./{$folder}/kako.txt")), count(file("./{$folder}/kako.html")));
