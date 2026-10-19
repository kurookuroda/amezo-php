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
// ---- 認証キー ----
chk('base32 RFC4648 foobar', base32_encode_bytes('foobar'), 'MZXW6YTBOI', 'rfc');
chk('base32 RFC4648 fo', base32_encode_bytes('fo'), 'MZXQ', 'rfc');
chk('base32 ゼロ5バイト', base32_encode_bytes("\0\0\0\0\0"), 'AAAAAAAA', 'zero');
chk('base32 15バイト=24文字', strlen(base32_encode_bytes(random_bytes(15))), 24, '15');
chk('key_normalize 小文字・ハイフン・空白', key_normalize('abcd-efgh ijkl-mnop-qrst-uvwx'), 'ABCDEFGHIJKLMNOPQRSTUVWX', 'norm');
chk('key_normalize 短い', key_normalize('ABCD-EFGH'), null, 'short');
chk('key_normalize 使えない文字 (0,1,8,9) が入ると長さが足りない', key_normalize('ABCD-EFGH-IJKL-MNOP-QRST-UV01'), null, '01');
chk('key_display', key_display('ABCDEFGHIJKLMNOPQRSTUVWX'), 'ABCD-EFGH-IJKL-MNOP-QRST-UVWX', 'disp');

$kd = '_utest_keys_' . getmypid(); mkdir($kd); mkdir($kd . '_b');
$k1 = key_issue($kd, 'Alice', '123');
$k2 = key_issue($kd, "Bob\tとタブ\n改行", '*');
chk('key_issue は表示用の形を返す', (bool)preg_match('/^([A-Z2-7]{4}-){5}[A-Z2-7]{4}$/', (string)$k1), true, $k1);
chk('スレッド専用キーは そのスレッドで有効', key_verify($kd, '123', (string)$k1), true, 'ok');
chk('スレッド専用キーは 別スレッドで無効', key_verify($kd, '456', (string)$k1), false, 'scope');
chk('板全体のキーは どのスレッドでも有効', key_verify($kd, '456', (string)$k2) && key_verify($kd, '123', (string)$k2), true, 'board');
chk('小文字・空白区切りでも有効', key_verify($kd, '123', strtolower(str_replace('-', ' ', (string)$k1))), true, 'fmt');
$bad = (string)$k1; $bad[strlen($bad) - 1] = $bad[strlen($bad) - 1] === 'A' ? 'B' : 'A';
chk('1文字違うと無効', key_verify($kd, '123', $bad), false, 'wrong');
chk('空・形が違うと無効', key_verify($kd, '123', '') || key_verify($kd, '123', 'abc'), false, 'empty');
chk('別の板 (keys.txt が無い) では無効', key_verify($kd . '_b', '123', (string)$k1), false, 'board');
$rows = keys_load($kd);
chk('ラベルの制御文字は空白にされる', $rows[1]['label'], 'Bob とタブ 改行', 'label');
key_issue($kd, str_repeat('あ', 60), '5'); key_issue($kd, '', '5');
$rows = keys_load($kd);
chk('ラベル40文字に切る', mb_strlen($rows[2]['label']), 40, 'len');
chk('ラベルが空なら (無題)', $rows[3]['label'], '(無題)', 'empty label');
chk('範囲が不正なら発行しない', key_issue($kd, 'x', 'abc'), null, 'scope');
chk('keys.txt にキー本体は残らない', str_contains((string)file_get_contents("$kd/keys.txt"), str_replace('-', '', (string)$k1)), false, 'plain');
chk('keys.txt の権限 0600', substr(sprintf('%o', fileperms("$kd/keys.txt")), -4), '0600', 'perm');
$id1 = substr(str_replace('-', '', (string)$k1), 0, 4);
chk('失効できる', key_revoke($kd, $id1), true, 'revoke');
chk('失効したキーは無効', key_verify($kd, '123', (string)$k1), false, 'revoked');
chk('失効済みをもう一度失効は false', key_revoke($kd, $id1), false, 'again');
chk('存在しない ID の失効は false', key_revoke($kd, 'ZZZZ'), false, 'none');
chk('別のキーは失効の影響を受けない', key_verify($kd, '456', (string)$k2), true, 'other');
foreach (glob("$kd/*") as $f) unlink($f); rmdir($kd); rmdir($kd . '_b');
// ---- URL のリンク化 (入力 -> 期待) ----
$A = ' target="_blank" rel="noopener noreferrer nofollow"';
$lk = static fn(string $href, string $dsp = null): string => '<a href="' . $href . '"' . $A . '>' . ($dsp ?? $href) . '</a>';
$urlcases = [
    ['http://example.com見てね',                    $lk('http://example.com') . '見てね'],
    ['http://example.com. 次',                      $lk('http://example.com') . '. 次'],
    ['<http://example.com>',                        '&lt;' . $lk('http://example.com') . '&gt;'],
    ['"http://example.com"',                        '&quot;' . $lk('http://example.com') . '&quot;'],
    ["'https://a.com/x'",                           '&#39;' . $lk('https://a.com/x') . '&#39;'],
    ['https://en.wikipedia.org/wiki/PHP_(language)', $lk('https://en.wikipedia.org/wiki/PHP_(language)')],
    ['https://ja.wikipedia.org/wiki/PHP_(言語)',     $lk('https://ja.wikipedia.org/wiki/PHP_') . '(言語)'],
    ['(https://a.com/x)',                           '(' . $lk('https://a.com/x') . ')'],
    ['https://a.com/x),',                           $lk('https://a.com/x') . '),'],
    ['[https://a.com/x]',                           '[' . $lk('https://a.com/x') . ']'],
    ['https://[::1]/x',                             $lk('https://[::1]/x')],
    ["https://a.com/it's",                          $lk('https://a.com/it&#39;s')],
    ['see https://a.com/?x=1&y=2 ok',               'see ' . $lk('https://a.com/?x=1&amp;y=2') . ' ok'],
    ['https://example.com/%E6%97%A5%E6%9C%AC',      $lk('https://example.com/%E6%97%A5%E6%9C%AC')],
    ['a http://x.com b https://y.com/z!',           'a ' . $lk('http://x.com') . ' b ' . $lk('https://y.com/z') . '!'],
    ['http:evil',                                   'http:evil'],
    ['javascript:alert(1)',                         'javascript:alert(1)'],
    ['https://',                                    'https://'],
    ['http://',                                     'http://'],
    ['https://-bad.com',                            'https://-bad.com'],
    ['ftp://a.com/x',                               'ftp://a.com/x'],
    ['http://a.com&gt;x',                           $lk('http://a.com&amp;gt;x')],
];
foreach ($urlcases as [$in, $want]) chk('URL: ' . $in, linkify_urls(html_escape($in), false), $want, $in);
chk('URL: <br> で止まる', linkify_urls('http://x.com<br>next', false), $lk('http://x.com') . '<br>next', 'br');
$evil = linkify_urls(html_escape('https://a.com/x," onmouseover="alert(1)'), false);
chk('URL: 属性を抜け出せない', (bool)preg_match('/<[^>]*onmouseover/', $evil), false, $evil);
chk('URL: 長い URL (400文字) も最後まで', linkify_urls(html_escape('https://a.com/' . str_repeat('a', 386)), false), $lk('https://a.com/' . str_repeat('a', 386)), 'long');
$t0 = microtime(true); linkify_urls(html_escape(str_repeat('http://a.com/((((((((', 40)));
chk('URL: 悪意のある入力でも遅くならない (50ms以内)', microtime(true) - $t0 < 0.05, true, 'redos');
// ---- 中継ページ (jump) ----
foreach (['https://a.com/x?y=1&z=2', "https://a.com/it's", 'https://[::1]/x', 'http://a.com'] as $u) chk("url_is_valid ok: $u", url_is_valid($u), true, $u);
foreach (['', 'javascript:alert(1)', 'http://', 'https://-a.com', "https://a.com/ x", 'https://a.com/"', 'https://a.com/<b>', "https://a.com/\n", 'ftp://a.com',
          'https://a.com/' . str_repeat('a', 2100), "https://a.com/\x00", 'https://a.com/日本'] as $u) chk('url_is_valid 不可: ' . substr(json_encode($u), 0, 30), url_is_valid($u), false, $u);
chk('b64url 往復', b64url_decode(b64url_encode("https://a.com/?x=1&y=日")), "https://a.com/?x=1&y=日", 'rt');
foreach (['', 'a b', 'ab=', '+/', 'ab!', "ab\n"] as $bad) chk('b64url_decode 拒否: ' . json_encode($bad), b64url_decode($bad), null, $bad);
$u0 = 'https://a.com/?x=1&y=2';
$sig = jump_sign($u0);
chk('署名は22文字 (base64url)', (bool)preg_match('/^[A-Za-z0-9_-]{22}$/', $sig), true, $sig);
chk('署名は同じ URL なら同じ', jump_sign($u0), $sig, 'det');
chk('署名は URL が違えば違う', jump_sign($u0 . 'x') !== $sig, true, 'diff');
chk('署名の検証', jump_verify($u0, $sig), true, 'verify');
chk('URL を変えると通らない', jump_verify($u0 . 'x', $sig), false, 'tamper url');
$sig2 = $sig; $sig2[21] = $sig2[21] === 'A' ? 'B' : 'A';
chk('署名を1文字変えると通らない', jump_verify($u0, $sig2), false, 'tamper sig');
chk('署名が空・短いと通らない', jump_verify($u0, '') || jump_verify($u0, substr($sig, 0, 10)), false, 'short');
chk('署名が合っても、URL として不正なら通らない', jump_verify('javascript:alert(1)', jump_sign('javascript:alert(1)')), false, 'js');
chk('中継の秘密鍵は、トリップ・ID・キーとは別のファイル', count(array_unique([file_get_contents(JUMP_SECRET_FILE), file_get_contents(TRIP_SECRET_FILE), file_get_contents(ID_SECRET_FILE)])), 3, 'keys');
$out = linkify_urls(html_escape('see https://a.com/?x=1&y=2 ok'), true);
preg_match('/href="([^"]+)"/', $out, $hm);
parse_str((string)parse_url(html_entity_decode($hm[1]), PHP_URL_QUERY), $q);
chk('中継リンク: href は ../jump.cgi', str_starts_with($hm[1], '../jump.cgi?u='), true, $hm[1]);
chk('中継リンク: 直リンクが href に入っていない', str_contains($hm[1], 'a.com'), false, $hm[1]);
chk('中継リンク: u を戻すと元の URL', b64url_decode($q['u'] ?? ''), 'https://a.com/?x=1&y=2', 'u');
chk('中継リンク: 署名が合う', jump_verify((string)b64url_decode($q['u'] ?? ''), $q['s'] ?? ''), true, 's');
chk('中継リンク: 表示する文字は元の URL', str_contains($out, '>https://a.com/?x=1&amp;y=2</a>'), true, $out);
chk('中継リンク: target/rel が付く', str_contains($out, 'target="_blank" rel="noopener noreferrer nofollow"'), true, 'rel');
chk('中継リンク: 同じ本文は同じ出力 (二重投稿の判定が効く)', linkify_urls(html_escape('x https://a.com/b'), true), linkify_urls(html_escape('x https://a.com/b'), true), 'same');
chk('中継リンク: 直リンク指定 (false) は元の形', str_contains(linkify_urls('https://a.com/x', false), 'href="https://a.com/x"'), true, 'direct');
$evil2 = linkify_urls(html_escape('https://a.com/x," onmouseover="alert(1)'), true);
chk('中継リンク: 属性を抜け出せない', (bool)preg_match('/<[^>]*onmouseover/', $evil2), false, $evil2);
echo "$n checks, $fail failed\n";
exit($fail ? 1 : 0);
