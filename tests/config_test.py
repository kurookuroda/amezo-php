# config.ini / config.php の扱いの確認: lib.php を読み込む別プロセスで、定数がどうなるかを見る
import json, os, shutil, subprocess, sys, tempfile
SRC = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib.php')
bad = 0
def ok(label, cond, extra=''):
    global bad
    if not cond: bad += 1
    print(('OK   ' if cond else 'NG   ') + label + (('  ' + extra) if extra else ''))
def run(files, pre=''):
    d = tempfile.mkdtemp(prefix='cfgtest')
    shutil.copy(SRC, d + '/lib.php')
    for name, body in files.items(): open(f'{d}/{name}', 'w', encoding='utf-8').write(body)
    code = f'{pre} require "lib.php"; echo json_encode([POST_INTERVAL, MAX_THREADS, JUMP_ENABLED, AMEZO, ADMIN, TRUSTED_PROXIES], JSON_UNESCAPED_UNICODE);'
    r = subprocess.run(['php', '-d', 'log_errors=1', '-d', 'error_log=', '-r', code], cwd=d, capture_output=True, text=True)
    made = sorted(set(os.listdir(d)) - set(files) - {'lib.php'})
    shutil.rmtree(d)
    try: vals = json.loads(r.stdout)
    except Exception: vals = None
    return vals, r.stderr, made
D = [5, 200, True, '＠あめぞう', 'サポート', ['127.0.0.1', '::1']]       # 既定値
v, err, _ = run({})
ok('設定ファイルが無ければ既定値', v == D and 'amezo:' not in err, str(v))
v, err, _ = run({'config.ini': 'POST_INTERVAL = 0\nMAX_THREADS = 3\nJUMP_ENABLED = false\nAMEZO = ＠テスト\nADMIN = 管理人\nTRUSTED_PROXIES[] = 10.0.0.1\n'})
ok('config.ini の値が使われる', v == [0, 3, False, '＠テスト', '管理人', ['10.0.0.1']] and 'amezo:' not in err, str(v))
v, err, _ = run({'config.ini': 'POST_INTERVAL = 99999\nADMIN = "${HOME}"\nAMEZO = <b>x\nMAX_THREADS = 3\nUNKNOWN = 1\n'})
ok('外れた値・不明なキーは無視して既定値 (有効な値だけ使う)', v == [5, 3, True, '＠あめぞう', 'サポート', ['127.0.0.1', '::1']], str(v))
ok('無視した理由が error_log に出る (4件)', err.count('amezo: config.ini の') == 4, err.count('amezo: config.ini の').__str__())
# --- config.php は実行しない ---
php_evil = "<?php define('POST_INTERVAL', 99); file_put_contents('pwned.txt', 'executed');\n"
v, err, made = run({'config.php': php_evil})
ok('config.php は実行されない (定数も、ファイルの作成も起きない)', v == D and 'pwned.txt' not in made, f'{v} {made}')
ok('config.php があれば、読み込まないことをログに出す', 'config.php は読み込みません' in err)
v, err, made = run({'config.php': php_evil, 'config.ini': 'POST_INTERVAL = 2\n'})
ok('config.ini と config.php が両方あっても、config.ini だけが効く', v[0] == 2 and 'pwned.txt' not in made, str(v))
# --- 先に define() された値 (テストの仕組み) は、設定ファイルより優先 ---
v, err, _ = run({'config.ini': 'POST_INTERVAL = 7\n'}, pre="define('POST_INTERVAL', 1);")
ok('先に define() された値が優先される (テスト用)', v[0] == 1, str(v))
# --- 見本は、そのまま使える ---
d = tempfile.mkdtemp(prefix='cfgtest'); shutil.copy(SRC, d + '/lib.php')
shutil.copy(os.path.join(os.path.dirname(SRC), 'config.sample.ini'), d + '/config.ini')
r = subprocess.run(['php', '-r', 'require "lib.php"; echo POST_INTERVAL;'], cwd=d, capture_output=True, text=True); shutil.rmtree(d)
ok('config.sample.ini をそのまま config.ini にしても、エラーなし・既定値', r.stdout == '5' and 'amezo:' not in r.stderr, r.stderr[:80])
print(f'NG の数: {bad}'); sys.exit(1 if bad else 0)
