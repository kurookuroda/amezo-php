# webshell 対策の回帰テスト: 「実行できる形の PHP を置かれた」と仮定して、配信の入口 (router.php) が実行しないことを確かめる。
# 前提: /tmp/phpt に PHP 版一式、http://127.0.0.1:8123 で router.php を起動 (板 adm がある)
import http.client, os, sys
PH, HOST, PORT = '/tmp/phpt', '127.0.0.1', 8123
bad = 0
def ok(label, cond, extra=''):
    global bad
    if not cond: bad += 1
    print(('OK   ' if cond else 'NG   ') + label + (('  ' + extra) if extra else ''))
def raw(target, method='GET', body=None):
    c = http.client.HTTPConnection(HOST, PORT, timeout=10)
    try:
        c.putrequest(method, target, skip_host=False, skip_accept_encoding=True)
        c.putheader('CF-Connecting-IP', '9.8.7.6')
        c.endheaders()
        r = c.getresponse(); return r.status, r.read().decode('utf-8', 'replace')
    except Exception as e:
        return 0, str(e)
    finally:
        c.close()
CODE = '<?php echo "EXEC-RESULT:" . (1+1); ?>\n'      # 実行されると "EXEC-RESULT:2" が出る。文字のまま返れば '<?php' が見える
plant = [f'{PH}/probe_root.php', f'{PH}/probe_root.phtml', f'{PH}/adm/shell.php', f'{PH}/adm/shell.phtml', f'{PH}/adm/shell.php5',
         f'{PH}/adm/shell.phar', f'{PH}/adm/.htaccess', f'{PH}/adm/999999999999.html', f'{PH}/adm/index99.html', f'{PH}/adm/o111.html']
for p in plant: open(p, 'w').write(CODE)
SECRET = f'{PH}/probe_secret.txt'; MARK = 'SECRET-MARKER-9'
open(SECRET, 'w').write(MARK + '\n')      # アプリのフォルダ直下の秘密ファイル (delpass.txt や *_secret.txt の代わり)
try:
    # --- 1. 実行されてはいけない経路 (404 か 400 で、実行結果が出ない) ---
    deny = ['/probe_root.php', '//probe_root.php', '///probe_root.php', '//./probe_root.php', '//probe_root.php?x=1', '//probe_root.phtml',
            '/%70robe_root.php', '/probe_root.php%00.html', '/adm/%2e%2e/probe_root.php', '/adm/..%2fprobe_root.php', '/adm/../probe_root.php',
            '/adm/shell.php', '/adm/shell.phtml', '/adm/shell.php5', '/adm/shell.phar', '/adm/.htaccess', '/adm//shell.php', '//adm/shell.php',
            '/amezo.cgi/probe_root.php', '/amezo.php/x', '/adm/shell.php/amezo.cgi', '/./probe_root.php', '\\probe_root.php',
            '//lib.php', '//router.php', '//config.php', '//admin.php/x', '/lib.php', '/config.php', '/tests/unit.php', '/jump_secret.txt', '/adm/keys.txt',
            'http://127.0.0.1:8123/probe_root.php', 'http://127.0.0.1:8123//probe_root.php',
            '/probe_secret.txt', '//probe_secret.txt', '///probe_secret.txt', '//probe_secret.txt?x=1', 'http://127.0.0.1:8123//probe_secret.txt',
            '//delpass.txt', '//trip_secret.txt', '//id_secret.txt', '//key_secret.txt', '//jump_secret.txt', '//cap.txt', '//ng.txt', '//blist.txt']
    for t in deny:
        c, b = raw(t)
        allowed = (400, 404) + ((0,) if t.startswith('\\') else ())     # '\\' で始まるのは HTTP として不正。サーバーが接続を切ってもよい (実行されなければよい)
        ok(f'実行・配信されない: {t}', 'EXEC-RESULT:2' not in b and MARK not in b and c in allowed, f'HTTP {c}')
    # --- 2. 許可リストの静的ファイルは、中身が PHP でも文字のまま返る (実行されない) ---
    for t in ['/adm/999999999999.html', '/adm/index99.html', '/adm/o111.html']:
        c, b = raw(t)
        ok(f'静的 .html は文字のまま: {t}', c == 200 and b.startswith('<?php') and 'EXEC-RESULT:2' not in b, f'HTTP {c}')
    # --- 3. 本来の経路は、これまでどおり動く ---
    for t, want in [('/', 200), ('/style.css', 200), ('/hide.js', 200), ('/dai.cgi', 200), ('/jump.cgi', 200), ('/jump.php', 200),
                    ('/amezo.cgi?folder=adm', 303), ('/amezo.cgi?folder=adm&page=2', 303), ('/admin.cgi', 200), ('/adm/', 200), ('/adm', 301),
                    ('/jump.cgi?u=aHR0cHM6Ly9hLmNvbS8&s=AAAAAAAAAAAAAAAAAAAAAA', 200), ('/adm/index.html', 200)]:
        c, b = raw(t)
        ok(f'通常の経路: {t}', c == want, f'HTTP {c}')
finally:
    for p in plant + [SECRET]:
        try: os.unlink(p)
        except OSError: pass
print(f'NG の数: {bad}'); sys.exit(1 if bad else 0)
