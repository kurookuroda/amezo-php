# setup_expose.py の PHP 起動オプションの確認: 制限が本当に効くこと、アプリに必要なことはできること、動かないときは安全に戻ること
import json, os, shutil, sys, tempfile
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
import setup_expose as se
bad = 0
def ok(label, cond, extra=''):
    global bad
    if not cond: bad += 1
    print(('OK   ' if cond else 'NG   ') + label + (('  ' + extra) if extra else ''))
root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
app = tempfile.mkdtemp(prefix='hardapp'); shutil.copy(f'{root}/lib.php', f'{app}/lib.php')
se.BASE_DIR = os.path.realpath(app)

PROBE = r'''
$r = [];
foreach (["system", "shell_exec", "exec", "passthru", "proc_open", "popen", "fsockopen", "phpinfo", "mail", "putenv"] as $f) $r[$f] = function_exists($f) ? "CALLABLE" : "disabled";
$r["read /etc/passwd"]   = @file_get_contents("/etc/passwd") === false ? "denied" : "READ";
$r["write /etc/x"]       = @file_put_contents("/etc/amezo_probe", "x") === false ? "denied" : "WROTE";
$r["write /root/x"]      = @file_put_contents("/root/amezo_probe", "x") === false ? "denied" : "WROTE";
$r["list /"]             = @scandir("/") === false ? "denied" : "LISTED";
$r["read http://"]       = @file_get_contents("http://127.0.0.1:9/") === false ? "denied" : "READ";
$inc = @include "http://127.0.0.1:9/x.php"; $r["include http://"] = $inc === false ? "denied" : "INCLUDED";
$r["read ../ (親)"]      = @file_get_contents(__DIR__ . "/../hardprobe") === false ? "denied" : "READ";
$f = __DIR__ . "/data.txt";
$r["app: write+read"]    = (file_put_contents($f, "ok") === 2 && file_get_contents($f) === "ok") ? "ok" : "FAIL";
$r["app: mkdir+rename"]  = (mkdir(__DIR__ . "/board") && rename($f, __DIR__ . "/board/data.txt") && is_file(__DIR__ . "/board/data.txt")) ? "ok" : "FAIL";
$r["app: flock"]         = (($h = fopen(__DIR__ . "/board/data.txt", "r+")) && flock($h, LOCK_EX)) ? "ok" : "FAIL";
$t = tmpfile(); $r["app: tmpfile()"] = ($t !== false && fwrite($t, "x") === 1) ? "ok" : "FAIL";
$r["app: tempnam(tmp)"]  = ($n = tempnam(sys_get_temp_dir(), "hp")) && file_put_contents($n, "x") ? "ok" : "FAIL"; if ($n) @unlink($n);
$r["app: ini_set"]       = @ini_set("display_errors", "0") !== false ? "ok" : "FAIL";
echo json_encode($r, JSON_UNESCAPED_UNICODE);
'''
def probe(args, cwd):
    open(f'{cwd}/probe.php', 'w').write('<?php ' + PROBE)
    rc, out, err = se.run_php([*args, f'{cwd}/probe.php'], cwd=cwd)
    try: return json.loads(out)
    except Exception: return {'ERROR': out or err}

# --- 1. 既定の起動引数 ---
args, info = se.php_runtime_args()
ok('既定で、拡張を絞り、実行系を制限する', info == {'trim': True, 'harden': True}, str(info))
flat = ' '.join(args)
ok('open_basedir にアプリのフォルダと一時フォルダ (末尾 /)', f'open_basedir={se.BASE_DIR}/' in flat and ('/tmp/' in flat.split('open_basedir=')[1].split(' ')[0]))
ok('disable_functions / allow_url_* / expose_php / memory_limit', all(x in flat for x in ['disable_functions=exec,', 'allow_url_fopen=0', 'allow_url_include=0', 'expose_php=0', 'memory_limit=32M']))
ok('必要な関数が、無効にする関数と重なっていない', not (set(se.REQUIRED_FUNCS) & set(se.DISABLED_FUNCS)), str(set(se.REQUIRED_FUNCS) & set(se.DISABLED_FUNCS)))
r = probe(args, se.BASE_DIR)
for k in ["system", "shell_exec", "exec", "passthru", "proc_open", "popen", "fsockopen", "phpinfo", "mail", "putenv"]:
    ok(f'制限あり: {k}() は使えない', r.get(k) == 'disabled', r.get(k, str(r)))
for k in ["read /etc/passwd", "write /etc/x", "write /root/x", "list /", "read http://", "include http://", "read ../ (親)"]:
    ok(f'制限あり: {k} は拒否される', r.get(k) == 'denied', r.get(k, str(r)))
for k in ["app: write+read", "app: mkdir+rename", "app: flock", "app: tmpfile()", "app: tempnam(tmp)", "app: ini_set"]:
    ok(f'制限あり: アプリに必要な操作はできる {k}', r.get(k) == 'ok', r.get(k, str(r)))
# --- 比較: 制限なしなら、同じ操作が通ってしまう ---
shutil.rmtree(f'{se.BASE_DIR}/board', ignore_errors=True)
r0 = probe(['-n', '-d', 'extension=' + os.path.join(se.run_php(['-r', 'echo ini_get("extension_dir");'])[1], 'mbstring.so')], se.BASE_DIR)
ok('比較: 制限なしでは system() が使える / /etc/passwd が読める (制限が効いている証拠)', r0.get('system') == 'CALLABLE' and r0.get('read /etc/passwd') == 'READ', f"{r0.get('system')}, {r0.get('read /etc/passwd')}")
# --- 2. 一時フォルダが TMPDIR で別の場所でも、tmpfile() が使える ---
shutil.rmtree(f'{se.BASE_DIR}/board', ignore_errors=True)
os.makedirs('/var/tmp', exist_ok=True); os.environ['TMPDIR'] = '/var/tmp'
args2, info2 = se.php_runtime_args()
ok('TMPDIR=/var/tmp でも、open_basedir に /var/tmp/ を含む', '/var/tmp/' in ' '.join(args2) and info2['harden'], str(info2))
r2 = probe(args2, se.BASE_DIR)
ok('TMPDIR=/var/tmp でも tmpfile() / tempnam() が動く', r2.get('app: tmpfile()') == 'ok' and r2.get('app: tempnam(tmp)') == 'ok', str((r2.get('app: tmpfile()'), r2.get('app: tempnam(tmp)'))))
del os.environ['TMPDIR']
# --- 3. 動かないときは、安全側に戻る (制限を優先して残す) ---
shutil.rmtree(f'{se.BASE_DIR}/board', ignore_errors=True)
se.DISABLED_FUNCS.append('hash_hmac')       # 必要な関数を無効にしてしまった場合を再現
a3, i3 = se.php_runtime_args()
se.DISABLED_FUNCS.remove('hash_hmac')
ok('必要な関数が無効だと、制限なしの候補に戻る (動かないまま起動しない)', i3 == {'trim': True, 'harden': False} and 'disable_functions' not in ' '.join(a3), str(i3))
os.remove(f'{se.BASE_DIR}/lib.php')
a4, i4 = se.php_runtime_args()
ok('lib.php が読めないとき (何も確認できない) は、最小の引数に戻る', i4 == {'trim': False, 'harden': False} and a4 == ['-d', 'memory_limit=32M'], str(i4))
# --- 4. --no-harden / --no-trim ---
shutil.copy(f'{root}/lib.php', f'{se.BASE_DIR}/lib.php')
a5, i5 = se.php_runtime_args(trim=True, harden=False)
ok('harden=False: 絞るだけ', i5 == {'trim': True, 'harden': False} and 'open_basedir' not in ' '.join(a5), str(i5))
a6, i6 = se.php_runtime_args(trim=False, harden=True)
ok('trim=False: 制限だけ (php.ini は読む)', i6 == {'trim': False, 'harden': True} and '-n' not in a6 and 'open_basedir' in ' '.join(a6), str(i6))
shutil.rmtree(app, ignore_errors=True)
print(f'NG の数: {bad}'); sys.exit(1 if bad else 0)
