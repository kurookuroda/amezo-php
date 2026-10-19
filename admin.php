<?php
declare(strict_types=1);
//
// admin.php - あめぞう風掲示板の管理画面 (admin.cgi の PHP 版)
//
//   板 → スレッド → レス の順にたどり、レスの削除・スレッドの非表示/復元を行う。
//   認証は各画面のフォームにパスワードを持たせ、送信のたびに delpass.txt と照合する
//   (セッション/クッキーは使わない)。失敗が続いたら一定時間は照合せずに拒否する。
//
require __DIR__ . '/lib.php';

const MAX_BODY     = 64 * 1024;        // POST の上限
const MAX_FAILS    = 5;                // この回数失敗したら
const LOCK_SEC     = 15 * 60;          // この時間 (秒) 受け付けない
const FAIL_FILE    = 'admin_fail.mem';
const LIST_LIMIT   = 100;              // スレッド一覧に出す最大件数 (新しい順)
const BODY_PREVIEW = 60;               // レス一覧に出す本文の文字数
const DATE_RE      = '/(\d{4})年(\d{2})月(\d{2})日\([^)]+\)(\d{2})時(\d{2})分(\d{2})秒/u';

const ADMIN_STYLE = <<<'CSS'
<style>
body { font-family: sans-serif; margin: 12px; }
table { border-collapse: collapse; }
th, td { border: 1px solid #999; padding: 4px 8px; vertical-align: top; }
td.body { max-width: 32em; word-break: break-all; }
form.inline { display: inline; }
.msg { padding: 6px 10px; border: 1px solid #c33; margin: 8px 0; }
.msg.ok { border-color: #393; }
.keybox { border: 2px solid #c60; padding: 10px 14px; margin: 10px 0; background: #fff8e8; }
.keybox code { font-size: 1.3em; letter-spacing: 0.05em; user-select: all; }
button { padding: 4px 12px; }
</style>
CSS;

try {
    admin_main();
} catch (RuntimeException $e) {
    error_log('admin: ' . $e->getMessage());
    page('エラー', msg_html('サーバーの設定に問題があります (鍵ファイルを読み書きできません)。', false));
}

function E(string|int $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---- 出力 ---------------------------------------------------------------

function page_head(string $title): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo '<!DOCTYPE html>' . "\n" . '<html lang="ja"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . E($title) . '</title>' . ADMIN_STYLE . '</head><body>' . "\n"
        . '<h2>' . E($title) . '</h2>' . "\n";
}

function page_foot(): never
{
    echo "\n</body></html>\n";
    exit;
}

function page(string $title, string $body): never
{
    page_head($title);
    echo $body;
    page_foot();
}

function msg_html(string $message, bool $ok = true): string
{
    if ($message === '') return '';
    return '<div class="' . ($ok ? 'msg ok' : 'msg') . '">' . E($message) . '</div>';
}

function hidden(string $pw, array $fields = []): string
{
    $s = '<input type="hidden" name="adminpass" value="' . E($pw) . '">';
    foreach ($fields as $k => $v) {
        $s .= '<input type="hidden" name="' . E((string)$k) . '" value="' . E((string)$v) . '">';
    }
    return $s;
}

function btn(string $pw, string $label, array $fields = [], ?string $confirm = null): string
{
    $onclick = $confirm !== null ? ' onclick="return confirm(\'' . E($confirm) . '\')"' : '';
    return '<form method="post" action="admin.cgi" class="inline">' . hidden($pw, $fields)
        . '<button type="submit"' . $onclick . '>' . E($label) . '</button></form>';
}

function nav(string $pw, ?string $folder = null): string
{
    $s = btn($pw, '板一覧', ['act' => 'boards']);
    if ($folder !== null) $s .= ' ' . btn($pw, "「{$folder}」のスレッド一覧", ['act' => 'threads', 'folder' => $folder]);
    return "<p>{$s}</p>";
}

function fatal(string $pw, string $message): never
{
    page('エラー', msg_html($message, false) . nav($pw));
}

function login_page(string $message = ''): never
{
    page('管理画面', msg_html($message, false)
        . '<form method="post" action="admin.cgi">'
        . '<input type="hidden" name="act" value="boards">'
        . '管理用パスワード <input type="password" name="adminpass" autocomplete="current-password"> '
        . '<button type="submit">ログイン</button></form>');
}

// ---- 認証 (失敗回数の制限つき) -----------------------------------------------

/**
 * admin_fail.mem を排他ロックして $update(&$fails, $now) を実行し、書き戻す。
 * $fails: [接続元の鍵つきハッシュ => [失敗回数, 最終失敗時刻]]。ファイルを開けなければ null
 */
function fail_store(callable $update): mixed
{
    $fp = @fopen(FAIL_FILE, 'c+');
    if (!$fp) return null;
    @chmod(FAIL_FILE, 0600);
    flock($fp, LOCK_EX);
    $now = time();
    $fails = [];
    while (($line = fgets($fp)) !== false) {
        $p = preg_split('/\s+/', trim($line));
        if (is_array($p) && count($p) === 3 && ctype_digit($p[1]) && ctype_digit($p[2]) && $now - (int)$p[2] < LOCK_SEC) {
            $fails[$p[0]] = [(int)$p[1], (int)$p[2]];
        }
    }
    $result = $update($fails, $now);
    rewind($fp);
    ftruncate($fp, 0);
    foreach ($fails as $k => [$c, $t]) fwrite($fp, "$k $c $t\n");
    fclose($fp);
    return $result;
}

function authenticate(string $pw): bool
{
    $ip = client_ip();
    // IP は保存せず、鍵つきハッシュで数える (IPv6 は上位64bitでまとめる)
    $who = substr(hash_hmac('sha256', 'adm:' . ip_unit($ip !== '' ? $ip : 'unknown'), load_secret(ID_SECRET_FILE)), 0, 24);

    $blocked = fail_store(static fn(array &$fails, int $now): bool => ($fails[$who][0] ?? 0) >= MAX_FAILS);
    if ($blocked === null) page('管理画面', msg_html('状態ファイル (admin_fail.mem) を開けないため、受け付けられません。', false));
    if ($blocked) page('管理画面', msg_html('失敗が続いたため、しばらく受け付けません。時間をおいてやり直してください。', false));

    $delcrypt = load_delcrypt();
    if ($delcrypt === '') page('管理画面', msg_html('管理用パスワード (delpass.txt) が設定されていません。', false));

    $ok = $pw !== '' && verify_hash($pw, $delcrypt);

    fail_store(static function (array &$fails, int $now) use ($ok, $who): void {
        if ($ok) unset($fails[$who]);
        else $fails[$who] = [($fails[$who][0] ?? 0) + 1, $now];
    });
    return $ok;
}

// ---- 板・スレッドの読み取り ------------------------------------------------------

function read_boards(): array
{
    $boards = [];
    $fp = @fopen('./blist.txt', 'r');
    if (!$fp) return $boards;
    while (($line = fgets($fp)) !== false) {
        $p = explode(',', rtrim($line, "\r\n"));
        $name = $p[0];
        if ($name === '' || $name[0] === '.' || str_contains($name, '/') || str_contains($name, '\\')) continue;
        $title = isset($p[2]) ? str_replace('#c#', ',', $p[2]) : '';
        $plain = preg_match('/alt="([^"]+)/', $title, $m) ? $m[1] : preg_replace('/<[^>]*>/', '', $title);
        $boards[] = [$name, (string)$plain];
    }
    fclose($fp);
    return $boards;
}

/** [表示中, 非表示中] のスレッドを更新の新しい順に返す。要素は [mtime, 番号, パス] */
function admin_list_threads(string $folder): array
{
    $active = [];
    $hidden = [];
    $d = @opendir("./$folder");
    if (!$d) return [[], []];
    while (($n = readdir($d)) !== false) {           // 1つずつ読む (退避済みの o〜.html が何万本あっても配列に溜めない)
        $c = $n[0] ?? '';
        if ($c !== 'x' && !($c >= '0' && $c <= '9')) continue;
        if (!preg_match('/^(x?)(\d+)\.html$/', $n, $m)) continue;
        $path = "./$folder/$n";
        $mt = @filemtime($path);
        if ($mt === false) continue;
        if ($m[1] !== '') $hidden[] = [$mt, $m[2], $path];
        else $active[] = [$mt, $m[2], $path];
    }
    closedir($d);
    $cmp = static fn(array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]];
    usort($active, $cmp);
    usort($hidden, $cmp);
    return [$active, $hidden];
}

function decode_text(string $s): string
{
    return trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** 1行 (1レス分) を読み解く。レス行でなければ null。[番号, 名前, 日時, ID, 本文] */
function parse_post_line(string $line): ?array
{
    if (!preg_match('/<dt>(\d+)/', $line, $m)) return null;
    $name = preg_match('/投稿者：<font[^>]*><b>(.*?) <\/b>/u', $line, $nm)
        ? decode_text((string)preg_replace('/<[^>]*>/', '', $nm[1])) : '';
    $date = preg_match('/投稿日：([^<]*)<\/font>/u', $line, $dm) ? trim($dm[1]) : '';
    $id = preg_match('/data-id="([^"]*)"/', $line, $im) ? $im[1] : '';
    $date = trim((string)preg_replace('/ ID:[A-Za-z0-9._]+$/', '', $date));   // ID は別の列に出す
    $body = preg_match('/<dd>(.*?) <\/dl>/u', $line, $bm) ? str_replace('<br>', ' ', $bm[1]) : '';
    $body = decode_text((string)preg_replace('/<[^>]*>/', '', $body));
    return [(int)$m[1], $name, $date, $id, $body];
}

function first_line_title(string $line): string
{
    return preg_match('/<b>([^<]*)<\/b>/', $line, $m) ? decode_text($m[1]) : '';
}

/** [題名, レス数]。スレッド全体は読み込まず、チャンクで数える */
function thread_summary(string $path): array
{
    $fp = @fopen($path, 'r');
    if (!$fp) return ['', 0];
    $title = first_line_title((string)fgets($fp));
    rewind($fp);
    $count = 0;
    $carry = '';
    while (($chunk = fread($fp, 65536)) !== false && $chunk !== '') {
        $buf = $carry . $chunk;
        $count += substr_count($buf, '<dl><dt>');       // 1レスの先頭に必ず1つある (本文中は < がエスケープされる)
        $carry = substr($buf, -7);                       // 境目をまたぐ分は次のチャンクで数える (パターンは8バイトなので二重には数えない)
    }
    fclose($fp);
    return [$title, $count];
}

function fmt_time(int $ts): string
{
    return date('Y-m-d H:i', $ts);
}

// ---- 画面 ------------------------------------------------------------------

function show_boards(string $pw): never
{
    $rows = '';
    foreach (read_boards() as [$name, $plain]) {
        $rows .= '<tr><td>' . E($name) . '</td><td>' . E($plain) . '</td><td>'
            . btn($pw, '開く', ['act' => 'threads', 'folder' => $name]) . '</td></tr>';
    }
    $body = $rows !== ''
        ? '<table><tr><th>板</th><th>名称</th><th></th></tr>' . $rows . '</table>'
        : '<p>板がありません。</p>';
    page('板の一覧', $body);
}

function show_threads(string $pw, string $folder, string $message = '', bool $ok = true): never
{
    [$active, $hidden] = admin_list_threads($folder);

    $rows = '';
    foreach (array_slice($active, 0, LIST_LIMIT) as [$mt, $res, $path]) {
        [$title, $count] = thread_summary($path);
        $rows .= '<tr><td>' . ($title !== '' ? E($title) : '(題名なし)') . "</td><td>{$count}</td><td>" . fmt_time($mt) . '</td><td>'
            . (thread_is_locked($folder, $res) ? '🔒' : '') . '</td><td>'
            . btn($pw, 'レスを見る', ['act' => 'replies', 'folder' => $folder, 'res' => $res]) . ' '
            . btn($pw, '鍵の管理', ['act' => 'keys', 'folder' => $folder, 'res' => $res]) . ' '
            . btn($pw, '非表示', ['act' => 'hide', 'folder' => $folder, 'res' => $res], 'このスレッドを非表示にします。よろしいですか?')
            . '</td></tr>';
    }
    $body = msg_html($message, $ok) . nav($pw);
    if ($rows !== '') {
        $body .= '<table><tr><th>題名</th><th>レス数</th><th>更新</th><th>鍵</th><th></th></tr>' . $rows . '</table>';
        if (count($active) > LIST_LIMIT) {
            $body .= '<p>新しい順に ' . LIST_LIMIT . ' 件だけ表示しています (全 ' . count($active) . ' 件)。</p>';
        }
    } else {
        $body .= '<p>表示中のスレッドはありません。</p>';
    }

    $hrows = '';
    foreach (array_slice($hidden, 0, LIST_LIMIT) as [$mt, $res, $path]) {
        [$title, $count] = thread_summary($path);
        $hrows .= '<tr><td>' . ($title !== '' ? E($title) : '(題名なし)') . "</td><td>{$count}</td><td>" . fmt_time($mt) . '</td><td>'
            . btn($pw, '復元', ['act' => 'restore', 'folder' => $folder, 'res' => $res]) . '</td></tr>';
    }
    if ($hrows !== '') {
        $body .= '<h3>非表示中のスレッド</h3><table><tr><th>題名</th><th>レス数</th><th>更新</th><th></th></tr>' . $hrows . '</table>';
    }
    page("「{$folder}」のスレッド", $body);
}

/** レス一覧。スレッドが大きくても、1行ずつ読んで1行ずつ出す */
function show_replies(string $pw, string $folder, string $res, string $message = '', bool $ok = true): never
{
    $path = "./$folder/$res.html";
    $fp = is_file($path) ? @fopen($path, 'r') : false;
    if (!$fp) fatal($pw, 'スレッドがありません。(非表示中の場合は、スレッド一覧から復元してください)');
    flock($fp, LOCK_SH);
    $first = (string)fgets($fp);
    $title = first_line_title($first);

    page_head(($title !== '' ? $title : '(題名なし)') . " ({$folder}/{$res})");
    echo msg_html($message, $ok) . nav($pw, $folder)
        . '<form method="post" action="admin.cgi">' . hidden($pw, ['act' => 'delete', 'folder' => $folder, 'res' => $res])
        . '<table><tr><th>削除</th><th>No</th><th>名前</th><th>日時</th><th>ID</th><th>本文</th></tr>';
    $line = $first;
    do {
        if (($row = parse_post_line($line)) !== null) {
            [$num, $name, $date, $id, $body] = $row;
            $cb = $num === 1 ? '—' : '<input type="checkbox" name="del" value="' . $num . '">';
            $short = mb_strlen($body) > BODY_PREVIEW ? mb_substr($body, 0, BODY_PREVIEW) . '…' : $body;
            echo '<tr><td>' . $cb . '</td><td>' . $num . '</td><td>' . E($name) . '</td><td>' . E($date)
                . '</td><td>' . E($id) . '</td><td class="body">' . E($short) . '</td></tr>';
        }
    } while (($line = fgets($fp)) !== false);
    fclose($fp);

    echo '</table><p><button type="submit" onclick="return confirm(\'選択したレスを削除します。よろしいですか?\')">'
        . '選択したレスを削除</button></p></form>'
        . '<p>1番は削除できません。スレッドごと消す場合は「非表示」を使ってください。</p>'
        . '<p>' . btn($pw, 'このスレッドを非表示にする', ['act' => 'hide', 'folder' => $folder, 'res' => $res],
                      'このスレッドを非表示にします。よろしいですか?') . '</p>';
    page_foot();
}

/** 鍵の管理: 鍵のかけ外し、キーの発行・失効。発行したキーは、この画面に1度だけ出す */
function show_keys(string $pw, string $folder, string $res, string $message = '', bool $ok = true, ?string $issued = null): never
{
    $path = "./$folder/$res.html";
    if (!is_file($path)) fatal($pw, 'スレッドがありません。');
    $fp = @fopen($path, 'r');
    $title = $fp ? first_line_title((string)fgets($fp)) : '';
    if ($fp) fclose($fp);
    $locked = thread_is_locked($folder, $res);
    $base = ['folder' => $folder, 'res' => $res];

    $body = msg_html($message, $ok) . nav($pw, $folder);
    if ($issued !== null) {
        $body .= '<div class="keybox"><b>キーを発行しました。この画面を閉じると、二度と表示されません。</b>'
            . '<br>いまのうちに控えて、書き込んでよい人に渡してください。<br><br><code>' . E($issued) . '</code></div>';
    }
    $body .= '<p>状態: <b>' . ($locked ? '🔒 鍵つき (認証キーがないと書き込めません)' : '鍵なし (誰でも書き込めます)') . '</b></p><p>'
        . ($locked
            ? btn($pw, '鍵を外す', ['act' => 'unlock'] + $base, 'このスレッドの鍵を外して、誰でも書き込めるようにします。よろしいですか?') . ' '
              . btn($pw, '書き込みフォームを作り直す', ['act' => 'lock'] + $base)
            : btn($pw, '鍵をかける', ['act' => 'lock'] + $base, 'このスレッドに鍵をかけます。キーを持たない人は書き込めなくなります。よろしいですか?'))
        . '</p><p>※ 鍵つきでも、読むのは誰でもできます。</p>';

    $rows = '';
    foreach (keys_load($folder) as $r) {
        if ($r['scope'] !== '*' && $r['scope'] !== $res) continue;      // このスレッドで使えるキーだけ
        $active = $r['status'] === 'active';
        $rows .= '<tr><td>' . E($r['id']) . '</td><td>' . E($r['label']) . '</td><td>'
            . ($r['scope'] === '*' ? 'この板の鍵つきスレッドどれでも' : 'このスレッドだけ') . '</td><td>' . fmt_time($r['created']) . '</td><td>'
            . ($active ? '有効' : '失効') . '</td><td>'
            . ($active ? btn($pw, '失効', ['act' => 'revoke', 'id' => $r['id']] + $base,
                             $r['scope'] === '*' ? 'このキーは、この板の鍵つきスレッド全部で使えなくなります。よろしいですか?' : 'このキーを失効します。よろしいですか?') : '')
            . '</td></tr>';
    }
    $body .= '<h3>キー</h3>' . ($rows !== ''
        ? '<table><tr><th>ID</th><th>ラベル</th><th>範囲</th><th>発行日時</th><th>状態</th><th></th></tr>' . $rows . '</table>'
        : '<p>このスレッドで使えるキーはまだありません。</p>');
    $body .= '<h3>キーを発行する</h3><form method="post" action="admin.cgi">' . hidden($pw, ['act' => 'issue'] + $base)
        . 'ラベル (誰に渡すか。40文字まで) <input type="text" name="label" size="24"><br>'
        . '範囲 <label><input type="radio" name="scope" value="thread" checked> このスレッドだけ</label> '
        . '<label><input type="radio" name="scope" value="board"> この板の鍵つきスレッドどれでも</label><br>'
        . '<button type="submit">発行</button></form>'
        . '<p>鍵をかける前にキーを発行しても、あとから発行してもかまいません。キーは発行したときに1度だけ表示されます (サーバーにはハッシュしか残りません)。</p>';
    page('鍵の管理: ' . ($title !== '' ? $title : '(題名なし)') . " ({$folder}/{$res})", $body);
}

// ---- 操作 ------------------------------------------------------------------

/** @return array{0: bool, 1: string} */
function do_delete(string $folder, string $res, array $nums): array
{
    $path = "./$folder/$res.html";
    $set = [];
    foreach ($nums as $n) if ($n !== 1) $set[$n] = true;
    if (!$set) return [false, '削除するレスが選ばれていません。(1番は削除できません)'];
    if (!file_exists($path)) return [false, 'スレッドがありません。'];

    $W = @fopen($path, 'r+');
    if (!$W) return [false, '削除中にファイルエラーが発生しました。'];
    flock($W, LOCK_EX);                         // 投稿処理と同じファイルを同じ場所で書き換える (ロックが効く)
    $orig = (int)fstat($W)['mtime'];
    $tmp = tmpfile();                           // 残す行は一時ファイルに溜める (スレッド全体をメモリに載せない)
    $removed = 0;
    $stamp = null;
    while (($line = fgets($W)) !== false) {
        if (preg_match('/<dt>(\d+)/', $line, $m) && isset($set[(int)$m[1]])) {
            $removed++;
            continue;
        }
        if (preg_match(DATE_RE, $line, $d)) $stamp = array_map('intval', array_slice($d, 1));
        fwrite($tmp, $line);
    }
    rewind($W);
    ftruncate($W, 0);
    rewind($tmp);
    stream_copy_to_stream($tmp, $W);
    fflush($W);
    fclose($tmp);
    fclose($W);

    // 更新日時を「残った最後のレスの日時」に戻す (一覧の順位が変わらないように)
    $ts = $orig;
    if ($stamp && checkdate($stamp[1], $stamp[2], $stamp[0])) {
        $ts = mktime($stamp[3], $stamp[4], $stamp[5], $stamp[1], $stamp[2], $stamp[0]);
    }
    @touch($path, $ts + 1);
    clearstatcache();
    return [true, "{$removed}件のレスを削除しました。"];
}

/** @return array{0: bool, 1: string} */
function do_hide(string $folder, string $res): array
{
    $src = "./$folder/$res.html";
    $dst = "./$folder/x$res.html";
    if (!file_exists($src)) return [false, 'スレッドがありません。'];
    if (file_exists($dst)) return [false, '同じ番号の非表示スレッドが既にあるため、処理できません。'];
    if (!@rename($src, $dst)) return [false, 'スレッドの非表示化に失敗しました。'];
    @chmod($dst, 0600);
    return [true, "スレッド {$res} を非表示にしました。"];
}

/** @return array{0: bool, 1: string} */
function do_restore(string $folder, string $res): array
{
    $src = "./$folder/x$res.html";
    $dst = "./$folder/$res.html";
    if (!file_exists($src)) return [false, '非表示のスレッドがありません。'];
    if (file_exists($dst)) return [false, '同じ番号の表示中スレッドが既にあるため、復元できません。'];
    if (!@rename($src, $dst)) return [false, 'スレッドの復元に失敗しました。'];
    @chmod($dst, 0644);
    return [true, "スレッド {$res} を復元しました。"];
}

/** 板の index を作り直す。戻り値は補足メッセージ (問題なければ空) */
function regen(string $folder): string
{
    [, $status] = regen_index($folder, 1, false, false);
    return $status === 'done' ? '' : ' (他の処理が再生成中のため、板の一覧の更新は省略されました)';
}

// ---- メイン ----------------------------------------------------------------

/** application/x-www-form-urlencoded を、同じ名前が複数あっても (del=2&del=3) 全部残して読む */
function parse_form(string $body): array
{
    $p = [];
    foreach (explode('&', $body) as $pair) {
        if ($pair === '') continue;
        $kv = explode('=', $pair, 2);
        $p[mb_scrub(urldecode($kv[0]))][] = mb_scrub(urldecode($kv[1] ?? ''));
    }
    return $p;
}

function admin_main(): void
{
    $params = [];
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        $n = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($n < 0 || $n > MAX_BODY) page('エラー', msg_html('送信内容が大きすぎます。', false));
        $params = parse_form((string)file_get_contents('php://input', false, null, 0, MAX_BODY + 1));
    }
    $get = static fn(string $k): string => $params[$k][0] ?? '';

    $pw = $get('adminpass');
    if ($method !== 'POST' || $pw === '') login_page();
    if (!authenticate($pw)) login_page('パスワードが違います。');

    $act = $get('act') ?: 'boards';
    if ($act === 'boards') show_boards($pw);

    $folder = $get('folder');
    if ($folder === '' || str_contains($folder, '/') || str_contains($folder, '\\') || str_contains($folder, '..') || !is_board($folder)) {
        fatal($pw, '板名が不正です。');
    }
    if ($act === 'threads') show_threads($pw, $folder);

    $res = $get('res');
    if (!preg_match('/^\d{1,20}$/', $res)) fatal($pw, 'スレッド番号が不正です。');

    if ($act === 'replies') {
        show_replies($pw, $folder, $res);
    } elseif ($act === 'delete') {
        $nums = [];
        foreach ($params['del'] ?? [] as $x) if (preg_match('/^[0-9]{1,6}$/', $x)) $nums[] = (int)$x;
        [$ok, $message] = do_delete($folder, $res, $nums);
        if ($ok) $message .= regen($folder);
        show_replies($pw, $folder, $res, $message, $ok);
    } elseif ($act === 'keys') {
        show_keys($pw, $folder, $res);
    } elseif ($act === 'lock' || $act === 'unlock') {
        [$ok, $message] = thread_set_lock($folder, $res, $act === 'lock');
        if ($ok) $message .= regen($folder);
        show_keys($pw, $folder, $res, $message, $ok);
    } elseif ($act === 'issue') {
        $scope = $get('scope') === 'board' ? '*' : $res;
        $key = key_issue($folder, $get('label'), $scope);
        if ($key === null) show_keys($pw, $folder, $res, 'キーを保存できませんでした。', false);
        show_keys($pw, $folder, $res, '', true, $key);
    } elseif ($act === 'revoke') {
        $id = $get('id');
        $ok = preg_match('/^[A-Z2-7]{4}$/', $id) && key_revoke($folder, $id);
        show_keys($pw, $folder, $res, $ok ? "キー {$id} を失効しました。" : 'キーを失効できませんでした。', (bool)$ok);
    } elseif ($act === 'hide') {
        [$ok, $message] = do_hide($folder, $res);
        if ($ok) $message .= regen($folder);
        show_threads($pw, $folder, $message, $ok);
    } elseif ($act === 'restore') {
        [$ok, $message] = do_restore($folder, $res);
        if ($ok) $message .= regen($folder);
        show_threads($pw, $folder, $message, $ok);
    } else {
        fatal($pw, '不明な操作です。');
    }
}
