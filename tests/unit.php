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
echo "$n checks, $fail failed\n";
exit($fail ? 1 : 0);
