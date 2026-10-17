<?php
// Python 版との突き合わせ: php tests/unit.php vectors.json
require __DIR__ . '/../lib.php';
$v = json_decode(file_get_contents($argv[1]), true);
$fail = 0; $n = 0;
function chk(string $what, $got, $want, $in): void {
    global $fail, $n; $n++;
    if ($got !== $want) { $fail++; echo "NG $what: in=" . json_encode($in, JSON_UNESCAPED_UNICODE) . " got=" . json_encode($got, JSON_UNESCAPED_UNICODE) . " want=" . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; }
}
foreach ($v['trip']  as [$i, $w]) chk('trip',  make_trip($i), $w, $i);
foreach ($v['clean'] as [$i, $w]) chk('clean', clean_trip_password($i), $w, $i);
foreach ($v['len']   as [$i, $w]) chk('len',   html_chars_len($i), $w, $i);
foreach ($v['ccut']  as [$i, $w]) chk('ccut',  ccut($i, 25), $w, $i);
foreach ($v['esc']   as [$i, $w]) chk('esc',   html_escape($i), $w, $i);
foreach ($v['inv']   as [$i, $w]) chk('inv',   strip_invisible($i), $w, $i);
foreach ($v['hash']  as [$i, $w]) {
    chk('make_hash', make_hash($i, 1000, implode('', array_map('chr', range(0, 15)))), $w, $i);
    chk('verify_hash(py)', verify_hash($i, $w), true, $i);
    chk('verify_hash(wrong)', verify_hash($i . 'x', $w), false, $i);
}
// PHP で作ったハッシュを Python が検証できる形式か (形だけ)
chk('hash format', (bool)preg_match('/^pbkdf2_sha256\$100000\$[0-9a-f]{32}\$[0-9a-f]{64}$/', make_hash('x')), true, 'x');
chk('verify own', verify_hash('x', make_hash('x')), true, 'x');
// ID: 同じ入力は同じ / 板・IP・IPv6の下位64bitで変わる or 変わらない
$a = make_id('1.2.3.4', 'main');
chk('id stable', make_id('1.2.3.4', 'main'), $a, 'same');
chk('id len', strlen($a), ID_LEN, $a);
chk('id board differs', make_id('1.2.3.4', 'zatsu') !== $a, true, 'board');
chk('id ip differs', make_id('1.2.3.5', 'main') !== $a, true, 'ip');
chk('id v6 lower64 ignored', make_id('2001:db8:1:2:aaaa:bbbb:cccc:dddd', 'main'), make_id('2001:db8:1:2:1:2:3:4', 'main'), 'v6');
chk('id v6 upper differs', make_id('2001:db8:1:3::1', 'main') !== make_id('2001:db8:1:2::1', 'main'), true, 'v6');
chk('id != trip key', file_get_contents(TRIP_SECRET_FILE) !== file_get_contents(ID_SECRET_FILE), true, 'keys');
// ページ送りの表示
$txt = static fn(string $h): string => trim(strip_tags(str_replace('&amp;', '&', $h)));
chk('pager 1ページだけなら出さない', pager_html('main', 1, 1), '', 'pages=1');
chk('pager 1/3', $txt(pager_html('main', 1, 3)), '[1] 2 3　次の20件 →', '1/3');
chk('pager 1/3 は 前へ が無い', str_contains(pager_html('main', 1, 3), '前の'), false, '1/3');
chk('pager 2/3', $txt(pager_html('main', 2, 3)), '← 前の20件　1 [2] 3　次の20件 →', '2/3');
chk('pager 3/3 は 次へ が無い', str_contains(pager_html('main', 3, 3), '次の'), false, '3/3');
chk('pager 10ページまでは全部出す', $txt(pager_html('main', 5, 10)), '← 前の20件　1 2 3 4 [5] 6 7 8 9 10　次の20件 →', '5/10');
chk('pager 30ページは前後だけ', $txt(pager_html('main', 15, 30)), '← 前の20件　1 … 13 14 [15] 16 17 … 30　次の20件 →', '15/30');
chk('pager 先頭付近', $txt(pager_html('main', 1, 30)), '[1] 2 3 … 30　次の20件 →', '1/30');
chk('pager リンク先は page=番号', str_contains(pager_html('a b', 1, 3), 'folder=a%20b&amp;page=2'), true, 'href');
chk('pager 1ページ目への戻りは ./', str_contains(pager_html('main', 3, 3), 'href="./"'), true, 'href');
// reverse_lines: 塊 (64KB) の境目をまたぐ行・日本語・末尾の改行あり/なし・空行を含んでも、array_reverse(file()) と同じ
$tmpf = tempnam(sys_get_temp_dir(), 'rl');
foreach ([['多め', 9000, "\n"], ['末尾改行なし', 9000, ''], ['短い', 3, "\n"], ['1行だけ', 1, "\n"], ['空', 0, '']] as [$label, $cnt, $end]) {
    $lines = [];
    for ($i = 0; $i < $cnt; $i++) $lines[] = 'o26' . sprintf('%010d', $i) . '.html<>題名' . str_repeat('あ', $i % 17) . $i;
    file_put_contents($tmpf, $lines ? implode("\n", $lines) . $end : '');
    chk("reverse_lines $label", iterator_to_array(reverse_lines($tmpf), false), array_reverse($lines), $cnt);
}
file_put_contents($tmpf, "a\n\nb\n");
chk('reverse_lines 空行は読み飛ばす', iterator_to_array(reverse_lines($tmpf), false), ['b', 'a'], 'blank');
unlink($tmpf);
// ng_lines: 旧実装 (file + rtrim) と同じ結果 (CRLF、空行、最後の改行なし、日本語)
$cwd = getcwd(); $tdir = sys_get_temp_dir() . '/nglt' . getmypid(); mkdir($tdir); chdir($tdir);
foreach (["bad,c\r\n\r\nspam\n日本語,n\n", "a\nb", "", "\n\n", "x,c\r\ny\r\n"] as $i => $body) {
    file_put_contents('ng.txt', $body);
    $old = array_map(static fn($l) => rtrim($l, "\r"), file('ng.txt', FILE_IGNORE_NEW_LINES) ?: []);
    chk("ng_lines #$i", iterator_to_array(ng_lines(), false), $old, $body);
}
unlink('ng.txt'); chk('ng_lines ファイルなし', iterator_to_array(ng_lines(), false), [], 'none');
chdir($cwd); rmdir($tdir);
echo "$n checks, $fail failed\n";
exit($fail ? 1 : 0);
