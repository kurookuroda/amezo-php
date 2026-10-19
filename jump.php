<?php
declare(strict_types=1);
//
// jump.php - 外部サイトへの中継ページ (/jump.cgi も、ここに向く)
//
//   投稿された URL へのリンクは、このページを経由する: /jump.cgi?u=<URL (base64url)>&s=<署名>
//   署名が合うもの (= この掲示板に実際に投稿された URL) だけを表示する。署名がなければ、
//   このページは「どんな URL へも飛ばせる踏み台」になってしまい、フィッシングに悪用される。
//   自動では転送しない。飛び先を文字で全部見せて、「進む」を押したときだけ外へ出る。
//
require __DIR__ . '/lib.php';

function jump_headers(string $nonce): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-{$nonce}'; "
         . "frame-src 'self'; img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
}

function jump_page(string $title, string $body, string $nonce, bool $with_back = true): never
{
    jump_headers($nonce);
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<style>:root{color-scheme:light dark}body{font-family:sans-serif;max-width:40em;margin:1.5em auto;padding:0 1em;line-height:1.6}'
        . '.url{word-break:break-all;border:1px solid #888;padding:.6em .8em;margin:.8em 0;font-family:monospace}'
        . '.host{font-size:1.4em;font-weight:bold;word-break:break-all}'
        . '.btns{margin:1.2em 0}.go,.back{display:inline-block;padding:.5em 1.6em;margin-right:.8em;border:1px solid #888;border-radius:6px;'
        . 'font-size:1em;text-decoration:none;cursor:pointer;background:transparent;color:inherit}.go{font-weight:bold}'
        . '.note{font-size:.9em;opacity:.85}.warn{border:1px solid #c60;padding:.4em .7em;margin:.6em 0}'
        . '.ad{margin:1.5em 0}.ad small{display:block;opacity:.7}.ad iframe{width:100%;height:260px;border:1px solid #888}</style>'
        . '</head><body><h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>' . $body;
    if ($with_back) {
        echo '<script nonce="' . $nonce . '">document.getElementById("back").addEventListener("click",function(){'
           . 'if(history.length>1){history.back();}else{window.close();}});</script>';
    }
    echo '</body></html>';
    exit;
}

function jump_main(): void
{
    $nonce = bin2hex(random_bytes(12));
    $u = is_string($_GET['u'] ?? null) ? $_GET['u'] : '';
    $s = is_string($_GET['s'] ?? null) ? $_GET['s'] : '';
    $url = strlen($u) <= 3000 ? b64url_decode($u) : null;

    if ($url === null || !jump_verify($url, $s)) {
        // 署名が合わない: リンクにはしない。URL として読めるなら、文字だけ見せる (自分で確かめてから開けるように)
        $body = '<p>このリンクは、署名が合わないため、自動では開きません。<br>'
              . '掲示板に投稿された URL ではないか、投稿のあとに署名の鍵が変わった可能性があります。</p>';
        if ($url !== null && url_is_valid($url)) {
            $body .= '<p class="note">URL (リンクにはしていません。確かめてから、自分で開いてください):</p>'
                   . '<div class="url">' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $body .= '<div class="btns"><button type="button" class="back" id="back">戻る</button></div>';
        jump_page('このリンクは使えません', $body, $nonce);
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') $host = '(ホスト名を読み取れません)';
    $e = static fn(string $x): string => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');

    $body = '<p>この掲示板に投稿された、外部サイトへのリンクです。移動先を確かめてから、「進む」を押してください。</p>'
          . '<p class="note">移動先のサイト</p><div class="host">' . $e($host) . '</div>'
          . '<p class="note">URL 全体</p><div class="url">' . $e($url) . '</div>';
    if (stripos($host, 'xn--') !== false) {
        $body .= '<div class="warn">ドメイン名に <code>xn--</code> が含まれています。見た目が似た別のサイトのことがあるので、特に注意してください。</div>';
    }
    $body .= '<div class="btns"><a class="go" href="' . $e($url) . '" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer">進む</a>'
           . '<button type="button" class="back" id="back">戻る</button></div>'
           . '<p class="note">このページは自動では転送しません。リンク先の内容について、掲示板は責任を負いません。</p>';
    // 広告枠: ad.html があるときだけ。別オリジン扱い (allow-same-origin なし) の iframe に入れて、掲示板のデータに触れさせない
    if (is_file(__DIR__ . '/ad.html')) {
        $body .= '<div class="ad"><small>広告</small>'
               . '<iframe src="ad.html" sandbox="allow-scripts allow-popups" referrerpolicy="no-referrer" loading="lazy" title="広告"></iframe></div>';
    }
    jump_page('外部サイトへ移動します', $body, $nonce);
}

try {
    jump_main();
} catch (RuntimeException $e) {
    error_log('jump: ' . $e->getMessage());
    http_response_code(500);
    echo 'サーバーの設定に問題があります。';
}
