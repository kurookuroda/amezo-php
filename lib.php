<?php
declare(strict_types=1);
//
// lib.php - あめぞう風掲示板 PHP 版の共通部品 (PHP 8.1 以上)
//
// amezo.py の関数と同じ名前・同じ結果になるように作っている。
// 検証は tests/ にある (Python 版が出した値との突き合わせ)。
//

date_default_timezone_set('Asia/Tokyo');
mb_internal_encoding('UTF-8');
@chdir(__DIR__);                       // 相対パス (./blist.txt など) の基準を固定
ini_set('display_errors', '0');        // 警告を画面に出さない (ログには残す)
ini_set('log_errors', '1');

// =========================================================================
// 設定
// =========================================================================

// ---- 変えてよい設定 -----------------------------------------------------------
// config.php があれば先に読む。define('POST_INTERVAL', 3); のように書くと、下の既定値を上書きできる
// (config.php は .gitignore に入れてあるので、本番の設定をリポジトリに混ぜずに済む)
if (is_file(__DIR__ . '/config.php')) require __DIR__ . '/config.php';
foreach ([
    'AMEZO'           => '＠あめぞう',
    'URLBASE'         => 'http://ame.x0.com/',
    'ADMIN'           => 'サポート',
    'POST_INTERVAL'   => 5,        // 同じ板での連投制限 (秒)
    'GLOBAL_WINDOW'   => 180,      // 同じ IP の新規スレッド作成の間隔 (秒)
    'MAX_THREADS'     => 200,      // これを超えた古いスレッドは o数字.html に退避 (0 なら退避しない)
    'PAGE_CACHE'      => 10,       // 「次のページ」のファイルを再利用する秒数
    'MAX_POST_BYTES'  => 7500,
    'SUBJ_MAX'        => 45,
    'NAME_MAX'        => 25,
    'MAIL_MAX'        => 50,
    'COMM_MAX'        => 400,
    'COMM_LINES_MAX'  => 20,
    'HASH_ITERATIONS' => 100000,
    'TRUSTED_PROXIES' => ['127.0.0.1', '::1'],
    'JUMP_ENABLED'    => true,     // 投稿の URL を、署名つきの中継ページ (jump.cgi) 経由のリンクにする (false なら直リンク)
] as $k => $v) {
    defined($k) || define($k, $v);
}

// ---- 公開後に変えてはいけない仕様 (変えるとトリップ/IDが全員変わる) ----------
const TRIP_CTX      = '';          // トリップ計算の接頭辞。空 = Python 版と同じトリップ
const ID_CTX        = 'id:';       // ID 計算の接頭辞
const TRIP_LEN      = 10;          // トリップの長さ
const ID_LEN        = 8;           // ID の長さ
const TRIP_PASS_MAX = 100;         // トリップのパスワードの最大文字数
const TRIP_MARK     = '◆';
const CAP_MARK      = '★';
const PAGE_SIZE     = 20;          // 1ページに本文を出すスレッド数 (1ページ目は index.html、k ページ目は index<k>.html)

// ---- ファイル名 -----------------------------------------------------------
const TRIP_SECRET_FILE = 'trip_secret.txt';
const ID_SECRET_FILE   = 'id_secret.txt';     // トリップとは別の鍵
const CAP_FILE         = 'cap.txt';
const DELCRYPTFILE     = 'delpass.txt';
const KAKO_FILE        = 'kako.html';
const KAKO_LIST        = 'kako.txt';
const KAKO_SEP         = '<>';
const HASH_SCHEME      = 'pbkdf2_sha256';

// ---- 認証キー (鍵つきスレッド) ----
const KEY_SECRET_FILE = 'key_secret.txt';   // キーのハッシュ用の秘密鍵 (トリップ/ID とは別)
const KEY_CTX         = 'key:';             // 公開後に変えない
const KEYS_FILE       = 'keys.txt';         // 板ごと: <板>/keys.txt
const KEY_ALPHABET    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

// ---- 中継ページ (jump.php): 投稿の URL への署名つきリンク ----
const JUMP_SECRET_FILE = 'jump_secret.txt'; // 署名用の秘密鍵 (他の鍵とは別)。失うと、投稿済みの中継リンクが全部使えなくなる
const JUMP_CTX         = 'jump:';           // 公開後に変えない
const JUMP_SIG_LEN     = 22;                // 署名の長さ (base64url で22文字 = 132bit)
const URL_MAX_LEN      = 2000;

const SCRIPT_TAG = '<script src="../hide.js" defer></script>';
const COPYRIGHT  = "<!--\n    Script for amezo-like BBS (PHP Version)\n    Original Copyright (C) 1999-2003 amezo_\n    http://www17.big.or.jp/~amezo_/\n    http://ame.x0.com/\n-->\n";
const ENTITY_TOKEN_RE = '/&(?:amp|quot|lt|gt|#39);|./su';
const INVISIBLE_RE = '/[\x{0080}-\x{009F}\x{00AD}\x{034F}\x{061C}\x{115F}\x{1160}\x{17B4}\x{17B5}'
                   . '\x{180B}-\x{180E}\x{200B}\x{200C}\x{200E}\x{200F}\x{2028}-\x{202E}\x{2060}-\x{206F}'
                   . '\x{3164}\x{FEFF}\x{FFA0}\x{FFF9}-\x{FFFB}]/u';

// =========================================================================
// ユーティリティ
// =========================================================================

function bbs_error(string $msg): never
{
    header('Content-Type: text/html; charset=UTF-8');
    echo '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body><h2>'
        . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2></body></html>';
    exit;
}

function to_utf8(string $b): string
{
    if ($b === '') return '';
    if (mb_check_encoding($b, 'UTF-8')) return $b;
    if (mb_check_encoding($b, 'SJIS-win')) return mb_convert_encoding($b, 'UTF-8', 'SJIS-win');
    return (string)preg_replace('/[^\x00-\x7F]/', '', $b);
}

function strip_invisible(string $s): string
{
    return (string)preg_replace(INVISIBLE_RE, '', $s);
}

function html_escape(string $s): string
{
    return strtr($s, ['&' => '&amp;', '"' => '&quot;', '<' => '&lt;', '>' => '&gt;', "'" => '&#39;']);
}

/** 見た目の文字数 (全角も半角も絵文字も1文字、&amp; などのエスケープも1文字) */
function html_chars_len(string $s): int
{
    return (int)preg_match_all(ENTITY_TOKEN_RE, $s);
}

function ccut(string $s, int $max): string
{
    preg_match_all(ENTITY_TOKEN_RE, $s, $m);
    return implode('', array_slice($m[0], 0, $max));
}

function url_decode(string $s): string
{
    return to_utf8(rawurldecode(str_replace('+', ' ', $s)));
}

function ip_unit(string $ip): string
{
    // IPv6 は上位64bitでまとめる (下位は端末が勝手に変えるので)
    if (str_contains($ip, ':') && ($bin = @inet_pton($ip)) !== false && strlen($bin) === 16) {
        return bin2hex(substr($bin, 0, 8));
    }
    return $ip;
}

function client_ip(): string
{
    $addr = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($addr, TRUSTED_PROXIES, true)) return $addr;
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
    $xff = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    $last = end($xff);
    if ($last !== false && $last !== '' && filter_var($last, FILTER_VALIDATE_IP)) return $last;
    return $addr;
}

// ---- パスワードハッシュ (admin.php / cap.txt / delpass.txt。Python 版と互換) ----

function make_hash(string $password, int $iterations = HASH_ITERATIONS, ?string $salt = null): string
{
    $salt ??= random_bytes(16);
    $dk = hash_pbkdf2('sha256', $password, $salt, $iterations, 0, true);
    return HASH_SCHEME . '$' . $iterations . '$' . bin2hex($salt) . '$' . bin2hex($dk);
}

function verify_hash(string $password, string $stored): bool
{
    $p = explode('$', trim($stored));
    if (count($p) !== 4 || $p[0] !== HASH_SCHEME) return false;
    [, $iters, $salt_hex, $hash_hex] = $p;
    if (!ctype_digit($iters)) return false;
    $iters = (int)$iters;
    if ($iters < 1 || $iters > 5000000) return false;
    if ($salt_hex === '' || $hash_hex === '' || strlen($salt_hex) % 2 || strlen($hash_hex) % 2
        || !ctype_xdigit($salt_hex) || !ctype_xdigit($hash_hex)) return false;
    $dk = hash_pbkdf2('sha256', $password, (string)hex2bin($salt_hex), $iters, 0, true);
    return hash_equals((string)hex2bin($hash_hex), $dk);
}

// ---- 秘密鍵 (トリップ用と ID 用で別ファイル) --------------------------------

/**
 * 32バイトの鍵を返す。無ければ作る。
 * fopen('x') で排他的に作るので、同時に来ても最初に作られた鍵が全員に使われる
 * (hard link が使えないファイルシステムでも動く)。読めない・作れないときは例外。
 */
function load_secret(string $file): string
{
    static $cache = [];
    if (isset($cache[$file])) return $cache[$file];
    $read = static function () use ($file): ?string {
        $s = @file_get_contents($file);
        $s = $s === false ? '' : trim($s);
        return preg_match('/^[0-9a-f]{64}$/', $s) ? (string)hex2bin($s) : null;
    };
    if (($k = $read()) !== null) return $cache[$file] = $k;
    $old = umask(0077);
    $fp = @fopen($file, 'x');
    umask($old);
    if ($fp) {
        $key = random_bytes(32);
        fwrite($fp, bin2hex($key) . "\n");
        fclose($fp);
        return $cache[$file] = $key;
    }
    for ($i = 0; $i < 20; $i++) {              // 他のプロセスが書き終わるのを待つ
        if (($k = $read()) !== null) return $cache[$file] = $k;
        usleep(50000);
    }
    throw new RuntimeException("secret unavailable: $file");
}

// ---- トリップ / ID / キャップ -----------------------------------------------

/** 空白を除き、全角の英数字・記号を半角にそろえ、文字数で切る (Python 版と同じ順) */
function clean_trip_password(string $p): string
{
    $p = str_replace([' ', "\u{3000}"], '', $p);
    $p = (string)preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u',
        static fn(array $m): string => mb_chr(mb_ord($m[0], 'UTF-8') - 0xFEE0, 'UTF-8'), $p);
    return mb_substr($p, 0, TRIP_PASS_MAX, 'UTF-8');
}

function make_trip(string $password): string
{
    $pw = clean_trip_password($password);
    if ($pw === '') return '';
    $raw = hash_hmac('sha256', TRIP_CTX . $pw, load_secret(TRIP_SECRET_FILE), true);
    return substr(strtr(base64_encode($raw), '+/', '._'), 0, TRIP_LEN);
}

/** ID = HMAC(ID鍵, IP + 日付(日本時間) + 板名)。IP そのものは保存しない */
function make_id(string $ip, string $board): string
{
    $msg = ID_CTX . ip_unit($ip) . '|' . date('Ymd') . '|' . $board;
    $raw = hash_hmac('sha256', $msg, load_secret(ID_SECRET_FILE), true);
    return substr(strtr(base64_encode($raw), '+/', '._'), 0, ID_LEN);
}

function is_cap(string $name_escaped, string $password): bool
{
    if ($password === '') return false;
    $fp = @fopen(CAP_FILE, 'r');
    if (!$fp) return false;
    $ok = false;
    while (($line = fgets($fp)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '' || $line[0] === '#' || !str_contains($line, ',')) continue;
        $p = strrpos($line, ',');
        if (html_escape(substr($line, 0, $p)) === $name_escaped && verify_hash($password, substr($line, $p + 1))) {
            $ok = true;
            break;
        }
    }
    fclose($fp);
    return $ok;
}

/** ng.txt を1行ずつ返す (全体を配列にしない)。行末の改行は除く */
function ng_lines(): Generator
{
    $fp = @fopen('./ng.txt', 'r');
    if (!$fp) return;
    while (($line = fgets($fp)) !== false) {
        yield rtrim($line, "\r\n");
    }
    fclose($fp);
}

function load_delcrypt(): string
{
    $s = @file_get_contents(DELCRYPTFILE);
    return $s === false ? '' : trim($s);
}

// =========================================================================
// 板・制限
// =========================================================================

/** blist.txt の1行 (フォルダ名,背景色,タイトル) を探す。無ければ null */
function board_line(string $folder): ?array
{
    $fp = @fopen('./blist.txt', 'r');
    if (!$fp) return null;
    $found = null;
    while (($line = fgets($fp)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') continue;
        $parts = explode(',', $line);
        if ($parts[0] === $folder) {
            $found = [$parts[1] ?? '', $parts[2] ?? ''];
            break;
        }
    }
    fclose($fp);
    return $found;
}

function is_board(string $folder): bool
{
    return $folder !== '' && board_line($folder) !== null;
}

function post_interval_left(string $folder): int
{
    if (POST_INTERVAL <= 0) return 0;
    $s = @file_get_contents("./$folder/lasttime.txt");
    $last = ($s !== false && ctype_digit(trim($s))) ? (int)trim($s) : 0;
    $left = POST_INTERVAL - (time() - $last);
    return $left > 0 ? $left : 0;
}

function touch_lasttime(string $folder): void
{
    if (POST_INTERVAL <= 0) return;
    @file_put_contents("./$folder/lasttime.txt", (string)time());
}

/** 新規スレッドは同じ IP から GLOBAL_WINDOW 秒に1回。IP は鍵つきハッシュにして保存する */
function check_global_ip(string $ip): void
{
    $who = substr(hash_hmac('sha256', 'rl:' . ip_unit($ip), load_secret(ID_SECRET_FILE)), 0, 24);
    $fp = @fopen('global.mem', 'c+');
    if (!$fp) return;
    @chmod('global.mem', 0600);
    flock($fp, LOCK_EX);
    $now = time();
    $list = [];
    while (($line = fgets($fp)) !== false) {
        $p = preg_split('/\s+/', trim($line));
        if (is_array($p) && count($p) === 2 && ctype_digit($p[1]) && $now - (int)$p[1] < GLOBAL_WINDOW) {
            $list[$p[0]] = (int)$p[1];
        }
    }
    if (isset($list[$who])) {
        $remain = GLOBAL_WINDOW - ($now - $list[$who]);
        fclose($fp);
        bbs_error("あと {$remain} 秒たってから投稿してください");
    }
    $list[$who] = $now;
    rewind($fp);
    ftruncate($fp, 0);
    foreach ($list as $k => $v) fwrite($fp, "$k $v\n");
    fclose($fp);
}

function thread_missing(string $folder, string $res, string $verb): never
{
    if (preg_match('/^\d+$/', $res) && file_exists("./$folder/o$res.html")) {
        bbs_error("このスレッドは過去ログになったため{$verb}できません.");
    }
    bbs_error('スレッドがありません.');
}

function redirect(string $folder, string $index_name): never
{
    $target = htmlspecialchars("$folder/$index_name", ENT_QUOTES, 'UTF-8');
    http_response_code(303);
    header("Location: ../$target");
    header('Content-Type: text/html; charset=UTF-8');
    echo "<html><head><meta http-equiv=\"refresh\" content=\"0;url=../$target\"></head>"
       . "<body>Redirecting to <a href=\"../$target\">$target</a>...</body></html>";
    exit;
}

function b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_decode(string $s): ?string
{
    if ($s === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $s)) return null;
    $d = base64_decode(strtr($s, '-_', '+/'), true);
    return $d === false ? null : $d;
}

/** 中継ページに渡してよい URL か (http/https、ホスト名は英数字か [ で始まる、使える文字は ASCII の URL 文字だけ) */
function url_is_valid(string $url): bool
{
    if ($url === '' || strlen($url) > URL_MAX_LEN) return false;
    return (bool)preg_match('/^https?:\/\/[A-Za-z0-9\[][A-Za-z0-9\-._~:\/?#\[\]@!$*+,;=%()\'&]*$/D', $url);
}

/** URL の署名: HMAC-SHA256(中継用の秘密鍵, 'jump:' + URL) の先頭132bit */
function jump_sign(string $url): string
{
    return substr(b64url_encode(hash_hmac('sha256', JUMP_CTX . $url, load_secret(JUMP_SECRET_FILE), true)), 0, JUMP_SIG_LEN);
}

function jump_verify(string $url, string $sig): bool
{
    return url_is_valid($url) && hash_equals(jump_sign($url), $sig);
}

/** スレッド HTML に書く中継リンクの href (属性用にエスケープ済み。板のフォルダの中から見た相対パス) */
function jump_href(string $url): string
{
    return '../jump.cgi?u=' . b64url_encode($url) . '&amp;s=' . jump_sign($url);
}

/**
 * エスケープ済みの本文から URL を探して、リンクにする。
 *  - URL に使える ASCII の文字と、エスケープされた &amp; &#39; だけを URL とみなす
 *    (直後の日本語、&gt; &quot; などのエスケープ、<br> は URL に含めない)
 *  - 末尾の . , ; : ! ? ( と、対応のない ) ] は URL に含めない
 *  - http:// か https:// で始まり、ホスト名の最初が英数字 (または [ ) のものだけ
 * $jump (既定は JUMP_ENABLED) が true なら、リンク先は署名つきの中継ページ。表示する文字は元の URL のまま。
 * false なら直リンク。href はエスケープを戻してから属性用に正しくエスケープし直す (生の & を入れない)。
 */
function linkify_urls(string $escaped, ?bool $jump = null): string
{
    $jump ??= JUMP_ENABLED;
    return (string)preg_replace_callback(
        '/https?:\/\/(?:[A-Za-z0-9\-._~:\/?#\[\]@!$*+,;=%()]|&amp;|&#39;)+/',
        static function (array $m) use ($jump): string {
            $dsp = $m[0];
            $tail = '';
            while ($dsp !== '') {
                if (str_ends_with($dsp, '&amp;') || str_ends_with($dsp, '&#39;')) {
                    $cut = 5;
                } else {
                    $c = $dsp[strlen($dsp) - 1];
                    if (str_contains('.,;:!?(', $c)) $cut = 1;
                    elseif ($c === ')' && substr_count($dsp, ')') > substr_count($dsp, '(')) $cut = 1;
                    elseif ($c === ']' && substr_count($dsp, ']') > substr_count($dsp, '[')) $cut = 1;
                    else break;
                }
                $tail = substr($dsp, -$cut) . $tail;
                $dsp = substr($dsp, 0, -$cut);
            }
            $url = str_replace(['&amp;', '&#39;'], ['&', "'"], $dsp);
            if (!url_is_valid($url)) return $m[0];
            $href = $jump ? jump_href($url) : html_escape($url);
            return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer nofollow">' . $dsp . '</a>' . $tail;
        },
        $escaped
    );
}

/** レス用フォーム (1行で返す。スレッドファイルのパーサが行単位のため)。鍵つきスレッドは認証キーの欄が付く */
function reply_form(string $res, string $links = '', bool $locked = false): string
{
    return '<form method=post action="../amezo.cgi" class="post">'
        . '<input type=hidden name="res" value="' . $res . '">'
        . '<div class="row">投稿者 <input type=text name="name" size=20> '
        . 'メール <input type=text name="mail" size=20 maxlength=50></div>'
        . ($locked ? '<div class="row">🔒 認証キー <input type=password name="key" size=32 autocomplete=off required>'
                     . ' このスレッドは認証キーがないと書き込めません</div>' : '')
        . '<textarea name="comm" rows=4 cols=70 maxlength=400></textarea>'
        . '<div class="row"><input type=submit value="↑へのレスカキコ"></div>'
        . $links
        . '</form>';
}

// =========================================================================
// 認証キー (鍵つきスレッド): <板>/<スレ番号>.lock があれば、書き込みにキーが要る
// =========================================================================

function thread_is_locked(string $folder, string $res): bool
{
    return is_file("./$folder/$res.lock");
}

/** スレッドファイルの末尾から [返信フォームの位置, 最後のレス番号, 最後のレス行] を求める (全体は読まない) */
function thread_footer_info($W): array
{
    $size = (int)fstat($W)['size'];
    if ($size === 0) return [0, 0, ''];
    $n = min($size, 65536);
    $off = $size - $n;
    fseek($W, $off);
    $tail = (string)fread($W, $n);
    $p = strrpos($tail, "\n<form");
    if ($p === false) {                       // 返信フォームが無い (壊れている) ときは末尾に足す
        $footer = $size;
        $before = rtrim($tail, "\n");
    } else {
        $footer = $off + $p + 1;
        $before = substr($tail, 0, $p);
    }
    $nl = strrpos($before, "\n");
    $prev = $nl === false ? $before : substr($before, $nl + 1);
    $cnt = preg_match('/<dt>(\d+)/', $prev, $m) ? (int)$m[1] : 0;
    return [$footer, $cnt, $prev];
}

function base32_encode_bytes(string $bin): string
{
    $out = '';
    $bits = 0;
    $buf = 0;
    foreach (str_split($bin) as $ch) {
        $buf = ($buf << 8) | ord($ch);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= KEY_ALPHABET[($buf >> $bits) & 31];
        }
        $buf &= (1 << $bits) - 1;
    }
    if ($bits > 0) $out .= KEY_ALPHABET[($buf << (5 - $bits)) & 31];
    return $out;
}

/** 入力されたキーを正規の形 (大文字24文字) にそろえる。形が違えば null */
function key_normalize(string $s): ?string
{
    $c = strtoupper((string)preg_replace('/[^A-Za-z2-7]/', '', $s));
    return strlen($c) === 24 ? $c : null;
}

function key_display(string $canon): string
{
    return implode('-', str_split($canon, 4));
}

function key_hash(string $canon): string
{
    return hash_hmac('sha256', KEY_CTX . $canon, load_secret(KEY_SECRET_FILE));
}

/** keys.txt の全行 (管理画面用。キーは板ごとに数が少ない前提) */
function keys_load(string $folder): array
{
    $rows = [];
    $fp = @fopen("./$folder/" . KEYS_FILE, 'r');
    if (!$fp) return $rows;
    while (($line = fgets($fp)) !== false) {
        $p = explode("\t", rtrim($line, "\r\n"));
        if (count($p) !== 6) continue;
        $rows[] = ['id' => $p[0], 'label' => $p[1], 'hash' => $p[2], 'scope' => $p[3], 'created' => (int)$p[4], 'status' => $p[5]];
    }
    fclose($fp);
    return $rows;
}

/** 投稿のときの照合用: ID の行だけを、1行ずつ読んで探す */
function key_find_row(string $folder, string $id): ?array
{
    $fp = @fopen("./$folder/" . KEYS_FILE, 'r');
    if (!$fp) return null;
    $found = null;
    while (($line = fgets($fp)) !== false) {
        if (!str_starts_with($line, $id . "\t")) continue;
        $p = explode("\t", rtrim($line, "\r\n"));
        if (count($p) === 6) {
            $found = ['id' => $p[0], 'label' => $p[1], 'hash' => $p[2], 'scope' => $p[3], 'created' => (int)$p[4], 'status' => $p[5]];
            break;
        }
    }
    fclose($fp);
    return $found;
}

/** キーがこのスレッドに書き込めるか。失効・範囲外・存在しない・形が違う、はどれも false (区別しない) */
function key_verify(string $folder, string $res, string $input): bool
{
    $canon = key_normalize($input);
    if ($canon === null) return false;
    $row = key_find_row($folder, substr($canon, 0, 4));
    if ($row === null || $row['status'] !== 'active') return false;
    if (!hash_equals($row['hash'], key_hash($canon))) return false;
    return $row['scope'] === '*' || $row['scope'] === $res;
}

/** keys.txt を排他ロックして、$fn(全行) の結果で書き直す (一時ファイル + rename)。失敗したら false */
function keys_update(string $folder, callable $fn): bool
{
    $lk = @fopen("./$folder/keys.lock", 'c');
    if (!$lk) return false;
    flock($lk, LOCK_EX);
    $rows = $fn(keys_load($folder));
    $path = "./$folder/" . KEYS_FILE;
    $tmp = $path . '.tmp' . getmypid();
    $old = umask(0077);
    $W = @fopen($tmp, 'w');
    umask($old);
    $ok = false;
    if ($W) {
        foreach ($rows as $r) {
            fwrite($W, implode("\t", [$r['id'], $r['label'], $r['hash'], $r['scope'], $r['created'], $r['status']]) . "\n");
        }
        fclose($W);
        $ok = @rename($tmp, $path);
        if (!$ok) @unlink($tmp);
    }
    flock($lk, LOCK_UN);
    fclose($lk);
    return $ok;
}

/** 管理者がキーを発行する。戻り値: 表示用のキー (この1回しか見られない)。保存できなければ null */
function key_issue(string $folder, string $label, string $scope): ?string
{
    $label = trim((string)preg_replace('/[\x00-\x1f\x7f]/u', ' ', $label));
    $label = mb_substr($label, 0, 40, 'UTF-8');
    if ($label === '') $label = '(無題)';
    if ($scope !== '*' && !preg_match('/^\d{1,20}$/', $scope)) return null;

    $display = null;
    $ok = keys_update($folder, static function (array $rows) use ($label, $scope, &$display): array {
        $used = array_column($rows, 'id');
        do {
            $canon = base32_encode_bytes(random_bytes(15));      // 120bit = 24文字。先頭4文字が ID
            $id = substr($canon, 0, 4);
        } while (in_array($id, $used, true));
        $rows[] = ['id' => $id, 'label' => $label, 'hash' => key_hash($canon), 'scope' => $scope,
                   'created' => time(), 'status' => 'active'];
        $display = key_display($canon);
        return $rows;
    });
    return $ok ? $display : null;
}

function key_revoke(string $folder, string $id): bool
{
    $hit = false;
    $ok = keys_update($folder, static function (array $rows) use ($id, &$hit): array {
        foreach ($rows as &$r) {
            if ($r['id'] === $id && $r['status'] === 'active') { $r['status'] = 'revoked'; $hit = true; }
        }
        return $rows;
    });
    return $ok && $hit;
}

/**
 * スレッドに鍵をかける/外す。返信フォームの行を差し替え、印 (.lock) を作る/消す。
 * 投稿側も同じスレッドのロックの中で .lock を確認するので、フォームと実際の状態がずれない。
 * @return array{0: bool, 1: string}
 */
function thread_set_lock(string $folder, string $res, bool $lock): array
{
    $path = "./$folder/$res.html";
    if (!is_file($path)) return [false, 'スレッドがありません。'];
    $W = @fopen($path, 'r+');
    if (!$W) return [false, 'スレッドを開けません。'];
    flock($W, LOCK_EX);
    [$footer] = thread_footer_info($W);
    $new = reply_form($res, '', $lock) . "</body></html>\n";
    fseek($W, $footer);
    fwrite($W, $new);
    fflush($W);
    ftruncate($W, $footer + strlen($new));
    $lockfile = "./$folder/$res.lock";
    $ok = $lock ? (@file_put_contents($lockfile, '') !== false) : (!file_exists($lockfile) || @unlink($lockfile));
    fclose($W);
    if (!$ok) return [false, '鍵の印のファイルを更新できませんでした。'];
    return [true, $lock ? "スレッド {$res} に鍵をかけました。" : "スレッド {$res} の鍵を外しました。"];
}

// =========================================================================
// 表示処理
// =========================================================================

/** レス表示 (from 番号以降だけ出す)。ストリームで出すのでスレッドが大きくても軽い */
function res_view(string $folder, array $form): never
{
    $res = $form['res'] ?? '';
    if (!preg_match('/^\d+$/', $res)) bbs_error('不正なスレッド指定です');
    $from = (int)($form['from'] ?? '1');
    if ($from < 1) $from = 1;

    $path = "./$folder/$res.html";
    $fp = is_file($path) ? @fopen($path, 'r') : false;
    if (!$fp) thread_missing($folder, $res, '表示');

    header('Content-Type: text/html; charset=UTF-8');
    while (($line = fgets($fp)) !== false) {
        echo str_replace(['href="../style.css"', 'src="../hide.js"', 'href="../jump.cgi?'], ['href="style.css"', 'src="hide.js"', 'href="jump.cgi?'], $line);
        if (str_contains($line, '<dl>') || str_contains($line, '<dt>')) break;
    }
    $fol_tag = '<input type="hidden" name="folder" value="' . htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') . '">';
    // 返信フォームの行は、action を板のない位置に直し、folder を足して出す
    $form_line = static fn(string $l): string => str_replace('</form>', $fol_tag . '</form>', str_replace('action="../', 'action="', $l));
    $fixj = static fn(string $l): string => str_contains($l, '../jump.cgi?') ? str_replace('href="../jump.cgi?', 'href="jump.cgi?', $l) : $l;   // / 直下から出力するので、中継リンクの相対パスを直す
    $matched = false;
    $footer = null;
    while (($line = fgets($fp)) !== false) {
        if (str_starts_with($line, '<form')) $footer = $line;
        if (preg_match('/<dt>(\d+)/', $line, $m) && (int)$m[1] >= $from) {
            echo $fixj($line);
            $matched = true;
            break;
        }
    }
    if (!$matched && $footer !== null) {                 // from が大きすぎても、返信フォームは出す
        echo $form_line($footer);
    }
    while (($line = fgets($fp)) !== false) {
        echo str_contains($line, '</form>') ? $form_line($line) : $fixj($line);
    }
    fclose($fp);
    exit;
}

// ---- 過去ログ一覧 ----------------------------------------------------------

function read_thread_title(string $path): string
{
    $fp = @fopen($path, 'r');
    if (!$fp) return '';
    $first = fgets($fp);
    fclose($fp);
    return ($first !== false && preg_match('/<b>([^<]*)<\/b>/', $first, $m)) ? $m[1] : '';
}

function kako_list_size(string $folder): int
{
    $s = @filesize("./$folder/" . KAKO_LIST);
    return $s === false ? -1 : $s;
}

/** 今回退避したスレッド [[ts, 'o数字.html'], ...] の題名を kako.txt に追記する */
function append_kako_list(string $folder, array $items): bool
{
    sort($items);
    $buf = '';
    foreach ($items as [, $name]) $buf .= $name . KAKO_SEP . read_thread_title("./$folder/$name") . "\n";
    return @file_put_contents("./$folder/" . KAKO_LIST, $buf, FILE_APPEND | LOCK_EX) !== false;
}

/** 今ある o数字.html から kako.txt を作り直す (kako.txt が無いときの1回だけ)。更新日時の古い順 */
function rebuild_kako_list(string $folder, array $names): bool
{
    $keys = [];
    foreach ($names as $name) $keys[] = sprintf('%011d', (int)@filemtime("./$folder/$name")) . ' ' . $name;
    sort($keys);                                            // 更新日時の古い順 (同じなら名前順)
    $path = "./$folder/" . KAKO_LIST;
    $tmp = $path . '.tmp' . getmypid();
    $W = @fopen($tmp, 'w');
    if (!$W) return false;
    foreach ($keys as $k) {                                 // 溜めずに1行ずつ書く
        $name = substr($k, 12);
        fwrite($W, $name . KAKO_SEP . read_thread_title("./$folder/$name") . "\n");
    }
    fclose($W);
    @chmod($tmp, 0644);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** ファイルを末尾から1行ずつ返す (新しい順に読みたいが、全体をメモリに載せたくないとき用) */
function reverse_lines(string $path): Generator
{
    $fp = @fopen($path, 'r');
    if (!$fp) return;
    $pos = (int)filesize($path);
    $carry = '';
    while ($pos > 0) {
        $n = min(65536, $pos);
        $pos -= $n;
        fseek($fp, $pos);
        $lines = explode("\n", (string)fread($fp, $n) . $carry);
        $carry = (string)array_shift($lines);               // 欠けている先頭の行は、さらに前の塊とつなげる
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if ($lines[$i] !== '') yield $lines[$i];
        }
    }
    if ($carry !== '') yield $carry;
    fclose($fp);
}

/** 過去ログの一覧 板/kako.html を kako.txt から作る (新しい退避が上)。失敗しても投稿は止めない */
function write_kako(string $folder, string $body_tag, string $title0, string $amezo_name, string $suffix, string $title): void
{
    $list = "./$folder/" . KAKO_LIST;
    if (!is_file($list)) return;
    $kako_path = "./$folder/" . KAKO_FILE;
    $tmp = $kako_path . '.tmp' . getmypid();
    $W = @fopen($tmp, 'w');
    if (!$W) return;
    fwrite($W,
        '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">' . "\n"
        . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
        . '<link rel="stylesheet" href="../style.css">' . "\n"
        . "<title>{$title0}{$amezo_name}{$suffix} 過去ログ</title></head>\n"
        . COPYRIGHT . "\n{$body_tag}\n"
        . "<font size=+2>{$title}{$amezo_name}{$suffix} 過去ログ</font>\n"
        . '<div class="row links"><a href="./">板へ戻る</a></div>' . "\n<font size=2>\n");
    $i = 0;
    foreach (reverse_lines($list) as $line) {                // 新しい退避が上。全部は読み込まない
        $p = explode(KAKO_SEP, $line, 2);
        if (count($p) !== 2 || !preg_match('/^o\d+\.html$/', $p[0])) continue;   // 壊れた行は読み飛ばす
        fwrite($W, ' ' . (++$i) . "<a href=\"{$p[0]}\">{$p[1]}</a>\n");
    }
    fwrite($W, "</font>\n<hr></body></html>\n");
    fclose($W);
    @chmod($tmp, 0644);
    if (!@rename($tmp, $kako_path)) @unlink($tmp);
}

// ---- スレッドの抜粋 (最初の1レス + 最後の7レス) -------------------------------

/**
 * スレッドファイルの先頭行と、末尾の最大7レスだけを読む (全体は読まない)。
 * 戻り値: [先頭行, 末尾のレス行の配列(改行つき), 先頭行を除いたレスの数] / 読めなければ null
 */
function thread_excerpt(string $path): ?array
{
    $fp = @fopen($path, 'r');
    if (!$fp) return null;
    flock($fp, LOCK_SH);
    $first = fgets($fp);
    if ($first === false) {
        fclose($fp);
        return ['', [], 0];
    }
    $size = (int)fstat($fp)['size'];
    $win = 32768;
    while (true) {
        $off = max(0, $size - $win);
        fseek($fp, $off);
        $chunk = (string)stream_get_contents($fp);
        $lines = explode("\n", $chunk);
        if (end($lines) === '') array_pop($lines);      // 最後が改行で終わるときの空要素
        array_shift($lines);                             // 途中から始まる行 (off=0 のときは先頭行) は捨てる
        if (count($lines) >= 9 || $off === 0 || $win >= 4194304) break;
        $win *= 2;
    }
    fclose($fp);
    if ($lines && str_starts_with(end($lines), '<form')) array_pop($lines);   // 返信フォームの行
    $rest = 0;
    if ($lines && preg_match('/<dt>(\d+)/', end($lines), $m)) $rest = max(0, (int)$m[1] - 1);
    else $rest = count($lines);
    $tail = array_map(static fn(string $l): string => $l . "\n", array_slice($lines, -7));
    return [$first, $tail, $rest];
}

// ---- ページ送り ---------------------------------------------------------------

/** 表示中のスレッド数 (ファイル名を1つずつ見て数える。退避済みの大量のファイルがあっても配列に溜めない) */
function count_threads(string $folder): int
{
    $d = @opendir("./$folder");
    if (!$d) return 0;
    $n = 0;
    while (($f = readdir($d)) !== false) {
        if (($f[0] ?? '') >= '0' && ($f[0] ?? '') <= '9' && preg_match('/^\d+\.html$/', $f)) $n++;
    }
    closedir($d);
    return MAX_THREADS > 0 ? min($n, MAX_THREADS) : $n;
}

function page_count(int $threads): int
{
    return max(1, intdiv($threads + PAGE_SIZE - 1, PAGE_SIZE));
}

/** ページ番号を、実在するページ (1〜最後のページ) に直す。範囲外は最後のページ */
function page_clamp(string $folder, int $page): int
{
    if ($page <= 1) return 1;
    return min($page, page_count(count_threads($folder)));
}

/** ページ送りのリンク。1ページしか無いときは何も出さない。11ページ以上のときは現在のページの前後だけ */
function pager_html(string $folder, int $cur, int $pages): string
{
    if ($pages <= 1) return '';
    $href = static fn(int $p): string => $p === 1
        ? './'
        : '../amezo.cgi?folder=' . rawurlencode($folder) . '&amp;page=' . $p;

    $nums = [];
    $last = 0;
    for ($p = 1; $p <= $pages; $p++) {
        if ($pages > 10 && $p !== 1 && $p !== $pages && abs($p - $cur) > 2) continue;
        if ($last && $p - $last > 1) $nums[] = '…';
        $nums[] = $p === $cur ? "<b>[{$p}]</b>" : '<a href="' . $href($p) . '">' . $p . '</a>';
        $last = $p;
    }
    $segments = [];
    if ($cur > 1) $segments[] = '<a href="' . $href($cur - 1) . '">← 前の' . PAGE_SIZE . '件</a>';   // 1ページ目には出さない
    $segments[] = implode(' ', $nums);
    if ($cur < $pages) $segments[] = '<a href="' . $href($cur + 1) . '">次の' . PAGE_SIZE . '件 →</a>'; // 最後のページには出さない
    return '<div class="row links pager">' . implode('　', $segments) . "</div>\n";
}

// ---- インデックス再生成 (amezo.php の投稿処理と admin.php の両方から使う) -------

/**
 * 表示中のスレッド一覧 [[ts, 'N.html'], ...] (更新の新しい順) と、退避済みのファイル名を返す。
 * 退避済みの名前は、過去ログ一覧を作り直すとき ($collect_old = true) だけ集める (何万本あっても普段は溜めない)。
 */
function list_threads(string $folder, bool $collect_old = false): array
{
    $files = [];
    $old = [];
    $d = @opendir("./$folder");
    if (!$d) return [[], []];
    while (($f = readdir($d)) !== false) {
        $c = $f[0] ?? '';
        if ($c === 'o') {
            if ($collect_old && preg_match('/^o\d+\.html$/', $f)) $old[] = $f;
        } elseif ($c >= '0' && $c <= '9' && preg_match('/^\d+\.html$/', $f)) {
            $files[] = [sprintf('%011d', (int)@filemtime("./$folder/$f")), $f];
        }
    }
    closedir($d);
    // 更新日時の新しい順。同じ秒のときはファイル名の新しい順 (並びを毎回同じにするため)
    usort($files, static fn(array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
    return [$files, $old];
}

/**
 * 板の index.html (2ページ目以降は index<ページ番号>.html) を再生成する。
 * 戻り値: [ファイル名, 状態]  状態: 'done' / 'cached' / 'busy'
 */
function regen_index(string $folder, int $page = 1, bool $reload = false, bool $use_cache = true): array
{
    [$color, $title] = board_line($folder) ?? ['', ''];
    $page_name = $page > 1 ? "index{$page}.html" : 'index.html';
    $index_path = "./$folder/$page_name";
    $suffix = $folder === 'main' ? '(メイン)' : '';

    $bg_attr = '';
    $cp = explode('!', $color);
    $color = $cp[0];
    if (count($cp) > 1) $bg_attr = ' background="' . $cp[1] . '"';
    $text_attr = '';
    if (str_ends_with($color, '*')) {
        $text_attr = ' text="#ffffff" link="red" vlink="orange"';
        $color = substr($color, 0, -1);
    }
    $title = str_replace('#c#', ',', $title);
    $title0 = $title;
    $amezo_name = AMEZO;
    if (preg_match('/alt="([^"]+)/', $title0, $m)) {
        $title0 = $m[1];
        $amezo_name = '';
    }

    // キャッシュチェック
    if ($use_cache) {
        clearstatcache(true, $index_path);
        $mt = @filemtime($index_path);
        if ($mt !== false) {
            if ($page === 1 && $reload && POST_INTERVAL > 0 && time() - $mt < POST_INTERVAL) return [$page_name, 'cached'];
            if ($page > 1 && PAGE_CACHE > 0 && time() - $mt < PAGE_CACHE) return [$page_name, 'cached'];
        }
    }

    // 1ページ目だけロックする (退避と過去ログ一覧の更新があるため)。他のページはファイルごとに独立
    $lk = null;
    if ($page === 1) {
        $lk = @fopen("./$folder/regen.lock", 'c');
        if (!$lk) bbs_error('ロックファイルを作成できません.');
        $locked = false;
        if (!$reload) {                                   // 投稿のとき: 最大10秒待つ
            for ($i = 0; $i < 100; $i++) {
                if (flock($lk, LOCK_EX | LOCK_NB)) { $locked = true; break; }
                usleep(100000);
            }
        } else {
            $locked = flock($lk, LOCK_EX | LOCK_NB);
        }
        if (!$locked) {
            fclose($lk);
            return [$page_name, 'busy'];
        }
    }

    // スレッド一覧 (退避は1ページ目の再生成のときだけ)
    $need_old = $page === 1 && kako_list_size($folder) <= 0;       // 過去ログ一覧を作り直すときだけ、退避済みの名前を集める
    [$files, $old_names] = list_threads($folder, $need_old);
    $retired_now = [];
    if (MAX_THREADS > 0 && count($files) > MAX_THREADS) {
        if ($page === 1) {
            foreach (array_slice($files, MAX_THREADS) as [$ts, $f]) {
                if (!file_exists("./$folder/o$f") && @rename("./$folder/$f", "./$folder/o$f")) {
                    $retired_now[] = [$ts, "o$f"];
                    @unlink('./' . $folder . '/' . substr($f, 0, -5) . '.lock');     // 過去ログは書き込めないので、鍵の印は要らない
                }
            }
        }
        $files = array_slice($files, 0, MAX_THREADS);
    }

    $kako_changed = false;
    if ($page === 1) {
        if (kako_list_size($folder) <= 0) {
            if ($old_names || $retired_now) {
                $kako_changed = rebuild_kako_list($folder, array_merge($old_names, array_column($retired_now, 1)));
            }
        } elseif ($retired_now) {
            $kako_changed = append_kako_list($folder, $retired_now);
        }
    }
    $has_kako = kako_list_size($folder) > 0;

    $pager = pager_html($folder, $page, page_count(count($files)));

    $tmp_path = $index_path . '.tmp' . getmypid();
    $W = @fopen($tmp_path, 'w');
    if (!$W) bbs_error('indexを書き込めません.');

    fwrite($W,
        '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">' . "\n"
        . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
        . '<link rel="stylesheet" href="../style.css">' . SCRIPT_TAG . "\n"
        . "<title>{$title0}{$amezo_name}{$suffix}</title></head>\n"
        . COPYRIGHT . "\n"
        . "<body{$bg_attr} bgcolor=\"{$color}\"{$text_attr}>\n"
        . "<font size=+2>{$title}{$amezo_name}{$suffix}</font>\n");
    if (is_file('./header.html')) {
        $h = @file_get_contents('./header.html');
        if ($h !== false) fwrite($W, $h);
    }
    fwrite($W, $pager . "<font size=2>\n<!-- HEAD LINE -->\n");

    $start = ($page - 1) * PAGE_SIZE;
    $excerpts = [];
    for ($i = $start; $i < $start + 100 && $i < count($files); $i++) {
        $f_name = $files[$i][1];
        $ex = thread_excerpt("./$folder/$f_name");
        $ttl = ($ex && preg_match('/<b>([^<]*)<\/b>/', $ex[0], $m)) ? $m[1] : '';
        fwrite($W, ' ' . ($i + 1) . "<a href=\"$f_name\">$ttl</a>\n");
        if ($i < $start + PAGE_SIZE) $excerpts[$i] = $ex;  // 本文を出すのは1ページ分だけ
    }
    fwrite($W, "<!-- HEAD LINE END -->\n</font>\n");
    if ($has_kako) fwrite($W, '<div class="row links"><a href="' . KAKO_FILE . '">過去ログ</a></div>' . "\n");

    // 各スレッドの内容表示
    foreach ($excerpts as $i => $ex) {
        $f_name = $files[$i][1];
        fwrite($W, '<table border=1 cellspacing=1 cellpadding=2 width=100% bgcolor="#efefef"><tr><td><font color="#000000">' . "\n");
        $rest = 0;
        if ($ex) {
            [$first, $tail, $rest] = $ex;
            // 先頭行から html/head/body/meta/title/link/script を削って出力
            $first = (string)preg_replace('~</?(?:html|head|body)[^>]*>~', '', $first);
            $first = (string)preg_replace('~<(?:meta|link)[^>]*>~', '', $first);
            $first = (string)preg_replace('~<title>.*?</title>~', '', $first);
            $first = (string)preg_replace('~<script[^>]*></script>~', '', $first);
            fwrite($W, $first);
            foreach ($tail as $l) fwrite($W, $l);
        }
        $res_id = substr($f_name, 0, -5);
        $sub_tag = '';
        if ($rest > 200) {
            $k_idx = $rest - 98;
            $sub_tag = "<input type=text name=\"from\" value=\"{$k_idx}\" size=\"4\">"
                . '<input type=submit name="sub" value="〜"> ';
        }
        $links = "<div class=\"row links\"><a href=\"{$res_id}.html\">レス全部を見る</a> "
            . "{$sub_tag}<a href=\"#top\">上へ</a> <a href=\"./\">リロード</a></div>";
        fwrite($W, '</font></td></tr></table>' . "\n" . reply_form($res_id, $links, thread_is_locked($folder, $res_id)) . "<hr>\n");
    }

    fwrite($W, $pager . "<hr></body></html>\n");
    fclose($W);

    // 元の index ファイルに置き換え
    $mode = file_exists($index_path) ? (fileperms($index_path) & 0777) : 0644;
    @chmod($tmp_path, $mode);
    if (!@rename($tmp_path, $index_path)) {
        @unlink($tmp_path);
        bbs_error('indexを更新できません.');
    }

    // 過去ログ一覧 (kako.html): 題名の一覧が変わったとき、またはまだ無いときだけ作り直す
    if ($page === 1) {
        if ($has_kako && ($kako_changed || !file_exists("./$folder/" . KAKO_FILE))) {
            write_kako($folder, "<body{$bg_attr} bgcolor=\"{$color}\"{$text_attr}>", $title0, $amezo_name, $suffix, $title);
        }
        // 古い「次のページ」のファイルを掃除する
        foreach (glob("./$folder/index[0-9]*.html") ?: [] as $p) {
            if (time() - (int)@filemtime($p) > 600) @unlink($p);
        }
        flock($lk, LOCK_UN);
        fclose($lk);
    }
    return [$page_name, 'done'];
}
