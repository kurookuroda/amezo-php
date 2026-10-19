<?php
declare(strict_types=1);
//
// amezo.php - あめぞう風掲示板 PHP 版: 投稿の受付、スレッドの更新、index の再生成
//   (フォームの action="../amezo.cgi" のまま動く。router.php が amezo.cgi をこのファイルに向ける)
//
require __DIR__ . '/lib.php';

if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') === '--make-hash' && isset($argv[2])) {
        echo make_hash($argv[2]), "\n";
        exit(0);
    }
    fwrite(STDERR, "Usage: php amezo.php --make-hash PASSWORD\n");
    exit(1);
}

ignore_user_abort(true);     // 書き込み途中で切断されてもファイルを壊さない

try {
    amezo_main();
} catch (RuntimeException $e) {
    error_log('amezo: ' . $e->getMessage());
    bbs_error('サーバーの設定に問題があります (鍵ファイルを読み書きできません).');
}

function amezo_main(): void
{
    $content_len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($content_len < 0) bbs_error('投稿内容が不正です');
    if ($content_len > MAX_POST_BYTES) bbs_error('投稿内容が大きすぎます');

    // ---- フォームの取り込み -------------------------------------------------
    $form = [];
    $trip_pw = '';          // 名前欄の「#」より後ろ (エスケープ前のまま。保存も表示もしない)
    foreach ($_POST as $k => $v) {
        if (!is_string($v)) continue;
        $key = to_utf8((string)$k);
        $value = strip_invisible(to_utf8($v));
        if ($key === 'name') {
            // 最初の半角 # で、名前とパスワードに分ける (全角の ＃ は区切りにしない)
            $pos = strpos($value, '#');
            if ($pos !== false) {
                $trip_pw = (string)preg_replace('/[\x00-\x1f]/', '', substr($value, $pos + 1));
                $value = substr($value, 0, $pos);
            }
        }
        $value = html_escape($value);
        $value = str_replace(["\r\n", "\n"], "\r", $value);
        if ($key === 'comm') $value = str_replace("\r", '<br>', $value);
        $form[$key] = (string)preg_replace('/[\x00-\x1f]/', '', $value);
    }

    $q_folder = isset($_GET['folder']) && is_string($_GET['folder']) ? to_utf8($_GET['folder']) : '';
    $folder = $form['folder'] ?? $q_folder;
    if ($folder === '') {
        $referer = url_decode($_SERVER['HTTP_REFERER'] ?? '');
        $referer = (string)preg_replace('~^(https?://)www\.~', '$1', $referer);
        if (preg_match('~https?://[^/]+/([^/]+)/~', $referer, $m)) $folder = $m[1];
    }
    if ($folder === '') bbs_error('板名が判定できません。');
    if (str_contains($folder, '/') || str_contains($folder, '..') || !is_board($folder)) bbs_error('板名が不正です');

    $name = $form['name'] ?? '';
    $mail = $form['mail'] ?? '';
    $comm = $form['comm'] ?? '';
    $subj = $form['subj'] ?? '';
    $res  = $form['res'] ?? '';

    if ($res !== '' && !preg_match('/^\d*$/', $res)) bbs_error('不正なスレッド指定です');
    if (isset($form['sub']) && $form['sub'] !== '') res_view($folder, $form);

    // ページ送り (?page=3)。POST でも GET でも受ける。実在するページにだけ飛ぶ (範囲外は最後のページ)。
    // 古い index に残っている「次のページ」の欄 (next=N番目から) も、そのページに読み替えて受ける
    $page_raw = $form['page'] ?? (is_string($_GET['page'] ?? null) ? $_GET['page'] : '');
    $next_raw = $form['next'] ?? (is_string($_GET['next'] ?? null) ? $_GET['next'] : '');
    if (preg_match('/^\d{1,6}$/', $page_raw)) {
        $page = (int)$page_raw;
    } elseif (preg_match('/^\d{1,6}$/', $next_raw)) {
        $page = intdiv(max(1, (int)$next_raw) - 1, PAGE_SIZE) + 1;
    } else {
        $page = 1;
    }
    $page = page_clamp($folder, $page);

    $reload = false;
    if ($name === $subj && $name === $comm) $reload = true;
    if ($name === '' && $subj === '' && $comm === '') $reload = true;

    // ---- 入力の検査 ---------------------------------------------------------
    if (substr_count($comm, '<br>') > COMM_LINES_MAX) bbs_error('改行が多すぎます');
    // 本文の改行 (<br>) は1文字として数える
    if (html_chars_len(str_replace('<br>', "\n", $comm)) > COMM_MAX) bbs_error('コメントが長すぎます');
    if (html_chars_len($mail) > MAIL_MAX) bbs_error('メールが長すぎます');

    $subj = str_replace([' ', "\u{3000}", '＿'], '', $subj);
    if (html_chars_len($subj) > SUBJ_MAX) bbs_error('題名が長すぎます');

    $name = str_replace([TRIP_MARK, CAP_MARK], ['◇', '☆'], $name);          // トリップ・キャップの偽造防止
    $name = str_replace([' ', "\u{3000}", "\u{200D}"], '', $name);
    $name = ccut($name, NAME_MAX);
    $comm = (string)preg_replace('/&gt;(&gt;)+/', '&gt;', $comm);

    // ---- NG ワード (name/comm/mail/subj はエスケープ済みなので、ワードもエスケープして照合する) ----
    if (is_file('./ng.txt')) {
        foreach (ng_lines() as $line) {
            if ($line === '') continue;
            $parts = explode(',', $line);
            $ng = html_escape($parts[0]);
            $opt = $parts[1] ?? '';
            if ($parts[0] === '') continue;
            if ($opt === 'n') {
                if (str_contains($name, $ng)) $reload = true;
            } elseif ($opt === 'c') {
                if (str_contains($comm, $ng)) $reload = true;
            } else {
                $name = str_replace($ng, '', $name);
                $comm = str_replace($ng, '', $comm);
                $mail = str_replace($ng, '', $mail);
                $subj = str_replace($ng, '', $subj);
            }
        }
    }

    // ---- 投稿 ---------------------------------------------------------------
    if ($page === 1 && !$reload) {
        // キャップ (cap.txt の名前とパスワードに一致したとき) は、管理者名の置き換えをしない
        $cap_ok = is_cap($name, $trip_pw);
        if (!$cap_ok) $name = str_replace(ADMIN, '＃' . ADMIN, $name);     // 管理者名偽装防止

        if ($res !== '') $subj = '';

        if ($name === '') bbs_error('投稿者を記入してください');
        if ($comm === '') bbs_error('コメントが入力されていません');
        if ($res === '' && $subj === '') bbs_error('題名が入力されていません');

        $left = post_interval_left($folder);
        if ($left > 0) bbs_error("連投を検知しました。あと {$left} 秒ほど待ってから投稿してください");

        $ip = client_ip();
        if ($subj !== '') check_global_ip($ip);

        $comm = linkify_urls($comm);

        $trip = '';
        if ($cap_ok) {
            $name .= CAP_MARK;
        } else {
            $trip = make_trip($trip_pw);
            if ($trip !== '') $name .= TRIP_MARK . $trip;
        }
        $id = make_id($ip, $folder);
        if ($mail !== '') $name = "<a href=\"mailto:{$mail} \">{$name} </a>";

        $wday = ['日', '月', '火', '水', '木', '金', '土'][(int)date('w')];
        $date_str = '20' . date('y年m月d日') . "({$wday})" . date('H時i分s秒');

        if ($res === '') $res = date('ymdHis');

        if ($subj !== '') {
            $W = false;
            for ($try = 0; $try <= 30; $try++) {
                if (!file_exists("./$folder/x$res.html") && !file_exists("./$folder/o$res.html")) {
                    $W = @fopen("./$folder/$res.html", 'x+');       // 排他的に作る
                    if ($W) break;
                }
                if ($try >= 30) bbs_error('スレッドを作成できません.');
                $res = (string)((int)$res + 1);
            }
        } else {
            $W = @fopen("./$folder/$res.html", 'r+');
            if (!$W) thread_missing($folder, $res, '返信');
        }

        flock($W, LOCK_EX);
        [$footer, $cnt, $prev] = thread_footer_info($W);
        $cnt++;

        // 鍵つきスレッド: 状態の確認からフォームの書き込みまで、同じロックの中でやる (管理者の鍵のかけ外しとずれない)
        $locked = $subj === '' && thread_is_locked($folder, $res);
        if ($locked) {
            $given = $form['key'] ?? '';
            if ($given === '') {
                fclose($W);
                bbs_error('このスレッドは認証キーが必要です。ページを再読み込みして、認証キーの欄に入力してください。');
            }
            if (!key_verify($folder, $res, $given)) {
                fclose($W);
                bbs_error('認証キーが正しくありません。');
            }
        }

        // 二重カキコ: 直前のレスと名前・本文が同じ (1レス目は題名の後ろに名前があるので、名前の位置で拾う)
        if (preg_match('/投稿者：<font[^>]*><b>(.+?) <\/b>.+<dd>(.+?) <\/dl>/u', $prev, $pm)
            && $name === $pm[1] && $comm === $pm[2]) {
            fclose($W);
            bbs_error('二重カキコです.');
        }

        $subj1 = $subj2 = '';
        if ($subj !== '') {
            $subj1 = '<html><head><meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . "<title>{$subj} </title>"
                . '<link rel="stylesheet" href="../style.css">' . SCRIPT_TAG
                . '</head><body>';
            $subj2 = "<font size=+2 color=red><b>{$subj} </b></font>";
        }
        $meta = '<font size=-1 class="meta" data-id="' . $id . '"' . ($trip !== '' ? ' data-trip="' . $trip . '"' : '') . '>';

        $data = "{$subj1}<dl><dt>{$cnt}{$subj2}投稿者：<font color=forestgreen><b>{$name} </b></font>"
            . "{$meta}　投稿日：{$date_str} ID:{$id}</font><br><dd>{$comm} </dl>\n"
            . reply_form($res, '', $locked) . "</body></html>\n";

        fseek($W, $footer);
        fwrite($W, $data);
        fflush($W);
        ftruncate($W, $footer + strlen($data));
        fclose($W);
        touch_lasttime($folder);
    }

    [$index_name] = regen_index($folder, $page, $reload);
    redirect($folder, $index_name);
}
