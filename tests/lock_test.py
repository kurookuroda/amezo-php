# 鍵つきスレッドの結合テスト (PHP 版だけ)。
# 前提: /tmp/phpt に PHP 版一式、http://127.0.0.1:8123 で router.php を起動、config.php で POST_INTERVAL/GLOBAL_WINDOW=0、
#       管理用パスワード adminpw の delpass.txt、板 adm と main がある
import os, re, sys, time, urllib.request, urllib.parse, urllib.error
from concurrent.futures import ThreadPoolExecutor
PH, URL = '/tmp/phpt', 'http://127.0.0.1:8123'
bad = 0
def ok(label, cond, extra=''):
    global bad
    if not cond: bad += 1
    print(('OK   ' if cond else 'NG   ') + label + (('  ' + extra) if extra else ''))
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
OP = urllib.request.build_opener(NoRedirect)
def req(path, body=None, ip='9.9.9.9'):
    r = urllib.request.Request(URL + path, data=body.encode() if body is not None else None, headers={'CF-Connecting-IP': ip})
    try:
        with OP.open(r) as f: return f.status, f.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e: return e.code, e.read().decode('utf-8', 'replace')
def q(**kw): return urllib.parse.urlencode(kw)
ctr = [0]
def ip():
    ctr[0] += 1; return f'10.{ctr[0] // 250}.{ctr[0] % 250}.7'
def post(folder, res, name, comm, key=None, subj=None):
    d = dict(folder=folder, name=name, comm=comm)
    if res: d['res'] = res
    if subj: d['subj'] = subj
    if key is not None: d['key'] = key
    c, t = req('/amezo.cgi', q(**d), ip())
    m = re.search(r'<h2>(.*?)</h2>', t)
    return c, (m.group(1) if m else '')
def adm(act, folder, res, **kw):
    c, t = req('/admin.cgi', q(act=act, folder=folder, res=res, adminpass='adminpw', **kw), ip())
    return t
def msgs(t): return re.findall(r'<div class="msg[^"]*">(.*?)</div>', t)
def newthread(folder, subj):
    c, e = post(folder, None, 'owner', 'first', subj=subj); time.sleep(1.1)
    ids = sorted(f[:-5] for f in os.listdir(f'{PH}/{folder}') if re.fullmatch(r'\d{12}\.html', f))
    return ids[-1]
def read(folder, res): return open(f'{PH}/{folder}/{res}.html', encoding='utf-8').read()
def issued(t):
    m = re.search(r'<code>([A-Z2-7]{4}(?:-[A-Z2-7]{4}){5})</code>', t); return m.group(1) if m else None

for d in ('adm', 'main'):
    for f in os.listdir(f'{PH}/{d}'): os.unlink(f'{PH}/{d}/{f}')
if os.path.exists(f'{PH}/global.mem'): os.unlink(f'{PH}/global.mem')

T = newthread('adm', 'locked one'); T2 = newthread('adm', 'locked two'); M = newthread('main', 'other board')
ok('最初は鍵なしで書き込める (デフォルト)', post('adm', T, 'a', 'open post 1') == (303, ''))
ok('最初は .lock も keys.txt も無い', not os.path.exists(f'{PH}/adm/{T}.lock') and not os.path.exists(f'{PH}/adm/keys.txt'))
ok('最初のフォームに認証キー欄が無い', 'name="key"' not in read('adm', T))

# --- 鍵をかける前に、キーを発行する ---
t = adm('issue', 'adm', T, label='Alice', scope='thread'); K1 = issued(t)
ok('鍵をかける前でもキーを発行できる (1度だけ表示)', K1 is not None, str(K1))
ok('発行しても、まだ鍵はかからない', post('adm', T, 'a', 'still open') == (303, '') and not os.path.exists(f'{PH}/adm/{T}.lock'))
t2 = adm('keys', 'adm', T)
ok('再表示するとキー本体は出ない (ハッシュだけ保存)', K1 not in t2 and K1.replace('-', '') not in t2)
ok('keys.txt にキー本体が無い', K1.replace('-', '') not in open(f'{PH}/adm/keys.txt', encoding='utf-8').read())

# --- 鍵をかける ---
t = adm('lock', 'adm', T)
ok('鍵をかけた (メッセージ)', any('鍵をかけました' in m for m in msgs(t)), str(msgs(t)))
ok('.lock ができた', os.path.exists(f'{PH}/adm/{T}.lock'))
f = read('adm', T)
ok('スレッドの返信フォームに認証キー欄が出る', f.count('name="key"') == 1 and f.count('<form') == 1 and f.endswith('</form></body></html>\n'))
ok('レス番号は崩れていない', re.findall(r'<dt>(\d+)', f) == ['1', '2', '3'], str(re.findall(r'<dt>(\d+)', f)))
idx = open(f'{PH}/adm/index.html', encoding='utf-8').read()
ok('板の index: 鍵つきスレッドだけに欄が出る', idx.count('name="key"') == 1, str(idx.count('name="key"')))
c, v = req('/amezo.cgi', q(folder='adm', res=T, sub='x', **{'from': 1}))
ok('res_view (レス全部) にも認証キー欄が出る', 'name="key"' in v and 'name="folder"' in v)

# --- 鍵つきスレッドへの書き込み ---
ok('キーなし → 拒否 (再読み込みを促す)', post('adm', T, 'x', 'no key') == (200, 'このスレッドは認証キーが必要です。ページを再読み込みして、認証キーの欄に入力してください。'))
ok('間違ったキー → 拒否', post('adm', T, 'x', 'bad', key='AAAA-AAAA-AAAA-AAAA-AAAA-AAAA')[1] == '認証キーが正しくありません。')
ok('形が違うキー → 同じ拒否 (区別しない)', post('adm', T, 'x', 'bad2', key='abc')[1] == '認証キーが正しくありません。')
n_before = len(re.findall(r'<dt>', read('adm', T)))
ok('正しいキー → 書き込める', post('adm', T, 'alice', 'with key', key=K1) == (303, ''))
ok('小文字・空白区切りのキーでも書き込める', post('adm', T, 'alice', 'with key 2', key=K1.lower().replace('-', ' ')) == (303, ''))
f = read('adm', T)
ok('拒否した投稿は書かれていない / 許可した投稿は書かれた', 'no key' not in f and 'bad2' not in f and 'with key 2' in f and len(re.findall(r'<dt>', f)) == n_before + 2)
ok('書き込み後も、フォームに認証キー欄が残る', f.count('name="key"') == 1 and f.count('<form') == 1)
ok('鍵つきスレッドの書き込みは、鍵のない別スレッドと独立', post('adm', T2, 'a', 'T2 open') == (303, ''))

# --- 範囲 ---
t = adm('lock', 'adm', T2)
ok('スレッド専用キーは、別の鍵つきスレッドでは使えない', post('adm', T2, 'a', 'use K1 on T2', key=K1)[1] == '認証キーが正しくありません。')
t = adm('issue', 'adm', T, label='Board key', scope='board'); K2 = issued(t)
ok('板全体のキーを発行した', K2 is not None)
ok('板全体のキーは、どの鍵つきスレッドでも使える', post('adm', T, 'b', 'K2 on T', key=K2) == (303, '') and post('adm', T2, 'b', 'K2 on T2', key=K2) == (303, ''))
ok('板のキーは、別の板の鍵つきスレッドでは使えない', (adm('lock', 'main', M), post('main', M, 'b', 'K2 on main', key=K2))[1][1] == '認証キーが正しくありません。')
ok('別の板の keys.txt は作られない', not os.path.exists(f'{PH}/main/keys.txt'))

# --- 失効 ---
id1 = K1.replace('-', '')[:4]
t = adm('revoke', 'adm', T, id=id1)
ok('失効した', any('失効しました' in m for m in msgs(t)), str(msgs(t)))
ok('失効したキーは使えない (拒否の文言は同じ)', post('adm', T, 'alice', 'after revoke', key=K1)[1] == '認証キーが正しくありません。')
ok('他のキーは影響を受けない', post('adm', T, 'b', 'K2 still ok', key=K2) == (303, ''))
t = adm('revoke', 'adm', T, id=id1)
ok('もう一度失効はエラー表示', any('失効できませんでした' in m for m in msgs(t)), str(msgs(t)))
t = adm('revoke', 'adm', T, id='zz')
ok('不正な ID はエラー表示', any('失効できませんでした' in m for m in msgs(t)))

# --- 鍵を外す ---
t = adm('unlock', 'adm', T)
ok('鍵を外した', any('鍵を外しました' in m for m in msgs(t)) and not os.path.exists(f'{PH}/adm/{T}.lock'))
f = read('adm', T)
ok('フォームから認証キー欄が消えた', 'name="key"' not in f and f.count('<form') == 1 and f.endswith('</form></body></html>\n'))
ok('鍵を外すと、キーなしでも書き込める', post('adm', T, 'free', 'open again') == (303, ''))
ok('鍵なしのスレッドに間違ったキーを付けても書き込める (無視される)', post('adm', T, 'free', 'ignored key', key='nonsense') == (303, ''))
idx = open(f'{PH}/adm/index.html', encoding='utf-8').read()
ok('index も更新された (T の欄が消え、T2 の欄は残る)', idx.count('name="key"') == 1)

# --- 管理画面の表示 ---
t = adm('threads', 'adm', '1') if False else req('/admin.cgi', q(act='threads', folder='adm', adminpass='adminpw'), ip())[1]
ok('スレッド一覧に 🔒 が出る (鍵つきの T2 だけ)', t.count('🔒') == 1)
ok('管理画面: パスワードなしでは鍵の管理に入れない', 'type="password"' in req('/admin.cgi', q(act='keys', folder='adm', res=T), ip())[1])

# --- 配信されない ---
for path in [f'/adm/{T2}.lock', '/adm/keys.txt', '/adm/keys.lock', '/key_secret.txt', '/trip_secret.txt']:
    ok(f'{path} は 404', req(path)[0] == 404)

# --- 同時: 鍵のかけ外しと投稿が重なっても、フォームと実際の状態がずれない ---
C = newthread('adm', 'race'); Kc = issued(adm('issue', 'adm', C, label='race', scope='thread'))
results = []
def worker(i):
    key = Kc if i % 2 == 0 else None
    c, e = post('adm', C, f'w{i}', f'race post {i}', key=key)
    return i, key is not None, c, e
def toggler():
    for n in range(12):
        adm('lock' if n % 2 == 0 else 'unlock', 'adm', C); time.sleep(0.02)
    adm('lock', 'adm', C)                       # 最後は鍵つき
with ThreadPoolExecutor(max_workers=12) as ex:
    tf = ex.submit(toggler)
    results = list(ex.map(worker, range(60)))
    tf.result()
f = read('adm', C)
nums = [int(x) for x in re.findall(r'<dt>(\d+)', f)]
okposts = [i for i, _, c, _ in results if c == 303]
ok('同時: 返信フォームは1つ・末尾が正しい・番号は連番', f.count('<form') == 1 and f.endswith('</form></body></html>\n') and nums == list(range(1, len(nums) + 1)), f'{len(nums)}レス')
ok('同時: 受け付けた投稿が全部書かれ、拒否した投稿は書かれていない',
   all((f'race post {i} ' in f) == (c == 303) for i, _, c, _ in results), f'受付 {len(okposts)}/60')
ok('同時: キーつきの投稿は、鍵のかけ外しの最中でも全部通る', all(c == 303 for i, hk, c, _ in results if hk))
ok('同時: 最終状態の .lock とフォームの欄が一致', os.path.exists(f'{PH}/adm/{C}.lock') and f.count('name="key"') == 1)
print(f'NG の数: {bad}'); sys.exit(1 if bad else 0)
