<?php
//
// router.php - php -S 用のルーター。CGI の代わりに PHP を直接実行し、
// 静的ファイルは許可リスト方式で配信する (delpass.txt、鍵、global.mem、x〜.html、ソースは 404)。
//
//   PHP_CLI_SERVER_WORKERS=2 php -S 127.0.0.1:8000 router.php
//
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (str_contains($path, "\0") || str_contains($path, '..') || str_contains($path, '\\')) {
    http_response_code(400);
    return true;
}

// ---- CGI 相当 (amezo.cgi / amezo.php など) ----------------------------------
if (preg_match('~^/(amezo|dai|admin)\.(?:cgi|php)$~', $path, $m)) {
    $f = __DIR__ . "/{$m[1]}.php";
    if (is_file($f)) {
        require $f;
        return true;
    }
    http_response_code(404);
    echo 'Not Found';
    return true;
}

// ---- 静的ファイル (許可リスト) -------------------------------------------------
if (in_array($path, ['/', '/index.html', '/style.css', '/hide.js'], true)) {
    return false;
}

if (preg_match('~^/([^/]+)(/?)((?:index\d*|kako|o?\d+)\.html)?$~', $path, $m)) {
    $boards = [];
    foreach (@file(__DIR__ . '/blist.txt', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $b = explode(',', $line)[0];
        if ($b !== '' && $b[0] !== '.' && !str_contains($b, '/')) $boards[$b] = true;
    }
    if (isset($boards[$m[1]])) {
        if ($m[2] === '' && ($m[3] ?? '') === '') {            // /main -> /main/
            header('Location: ' . $path . '/', true, 301);
            return true;
        }
        if ($m[2] === '/') return false;
    }
}

http_response_code(404);
echo 'Not Found';
return true;
