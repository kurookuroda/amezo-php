<?php
declare(strict_types=1);
//
// dai.php - 板一覧 + 各板の HEAD LINE 表示 (dai.cgi の PHP 版)
//
require __DIR__ . '/lib.php';

const HEADLINE_MAX = 20;

/** ./<folder>/index.html の HEAD LINE 部分 (最大20行)。1234.html -> folder/1234.html にして返す */
function read_headline(string $folder, string $folder_e): array
{
    $out = [];
    $fp = @fopen("./$folder/index.html", 'r');
    if (!$fp) return $out;                        // index がまだ無い板でも落ちない
    $found = false;
    while (($line = fgets($fp)) !== false) {
        if (str_contains($line, '<!-- HEAD LINE -->')) { $found = true; break; }
    }
    if ($found) {
        while (($line = fgets($fp)) !== false) {
            if (count($out) >= HEADLINE_MAX || str_contains($line, '<!-- HEAD LINE END -->')) break;
            // 最初の1箇所だけ置き換える
            $out[] = (string)preg_replace_callback('/(\d+\.html)/',
                static fn(array $m): string => $folder_e . '/' . $m[1], $line, 1);
        }
    }
    fclose($fp);
    return $out;
}

header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html>' . "\n"
   . '<html lang="ja"><head>' . "\n"
   . '<meta charset="UTF-8">' . "\n"
   . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
   . '<base target="naka">' . "\n"
   . '<link rel="stylesheet" href="style.css">' . "\n"
   . '<title>板一覧</title>' . "\n"
   . '</head><body>' . "\n";

$bf = @fopen('./blist.txt', 'r');
if ($bf) {
    while (($line = fgets($bf)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') continue;
        $parts = explode(',', $line);
        $folder = $parts[0];
        $color = $parts[1] ?? '';
        $title = $parts[2] ?? '';
        if ($folder === '' || $folder[0] === '.' || str_contains($folder, '/') || str_contains($folder, '..')) continue;

        $color = (string)preg_replace('/!.*/', '', $color);    // 背景画像の指定は使わない
        $color = (string)preg_replace('/\*$/', '', $color);    // 白文字指定の * は色ではない
        $title = str_replace('#c#', ',', $title);              // #c# をカンマに戻す

        // title は blist.txt (管理者のみ編集) の値をそのまま出す。color と folder は属性値なのでエスケープする
        $folder_e = htmlspecialchars($folder, ENT_QUOTES, 'UTF-8');
        $color_e = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
        echo "<table border=0 cellspacing=1 cellpadding=2 width=100% bgcolor=\"{$color_e}\"><tr><td>\n"
           . "<a href=\"{$folder_e}/\"><b><font size=\"3\" color=\"#F92500\">{$title}</font></b></a>\n";
        foreach (read_headline($folder, $folder_e) as $hl) echo $hl;
        echo "</td></tr></table>\n";
    }
    fclose($bf);
}
echo "</body></html>\n";
