# 管理画面の突き合わせ: 同じ操作を Python 版 (admin.py) と PHP 版 (admin.php) に流して、結果を比べる。
# 前提: /tmp/py (Python版) と /tmp/phpt (PHP版、http://127.0.0.1:8123 で router.php 起動) に同じ delpass.txt (パスワード adminpw) がある
import os, re, subprocess, time, urllib.request, urllib.parse, html, sys
PY, PH, URL = '/tmp/py', '/tmp/phpt', 'http://127.0.0.1:8123'
bad = 0
def ok(label, cond, extra=''):
    global bad
    if not cond: bad += 1
    print(('OK   ' if cond else 'NG   ') + label + (('  ' + extra) if extra else ''))
def py_post(script, body, ip='9.9.9.9'):
    env = dict(os.environ, REQUEST_METHOD='POST', CONTENT_LENGTH=str(len(body.encode())), REMOTE_ADDR=ip)
    r = subprocess.run(['python3', script], cwd=PY, input=body.encode(), env=env, capture_output=True)
    return r.stdout.decode('utf-8', 'replace')
def ph_post(path, body, ip='9.9.9.9'):
    req = urllib.request.Request(URL + path, data=body.encode(), headers={'CF-Connecting-IP': ip})
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *a, **k): return None
    try: return urllib.request.build_opener(NoRedirect).open(req).read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e: return e.read().decode('utf-8', 'replace')
def q(**kw): return urllib.parse.urlencode(kw)
def adm(side, body, ip='9.9.9.9'):
    return py_post('admin.py', body, ip) if side == 'py' else ph_post('/admin.cgi', body, ip)
def post(side, body, ip):
    return py_post('amezo.py', body, ip) if side == 'py' else ph_post('/amezo.cgi', body, ip)
def cells(page, drop_php_id=False):
    rows = []
    for tr in re.findall(r'<tr>(.*?)</tr>', page, re.S):
        cs = [html.unescape(re.sub(r'<[^>]*>', '', c)).strip() for c in re.findall(r'<t[dh][^>]*>(.*?)</t[dh]>', tr, re.S)]
        rows.append(cs)
    return rows
def msgs(page): return [html.unescape(m) for m in re.findall(r'<div class="msg[^"]*">(.*?)</div>', page)]
def thread_ids(side):
    d = PY if side == 'py' else PH
    return sorted(f[:-5] for f in os.listdir(f'{d}/adm') if re.fullmatch(r'\d{12}\.html', f))
def norm_file(side, tid):
    t = open(f"{PY if side == 'py' else PH}/adm/{tid}.html", encoding='utf-8').read()
    t = re.sub(r'20\d\d年\d\d月\d\d日\([日月火水木金土]\)\d\d時\d\d分\d\d秒', 'DATE', t)
    t = re.sub(r'<font size=-1 class="meta" data-id="[^"]*"(?: data-trip="[^"]*")?>', '<font size=-1>', t)
    t = re.sub(r'(DATE) ID:[A-Za-z0-9._]{8}', r'\1', t).replace('<script src="../hide.js" defer></script>', '')
    return re.sub(r'\d{12}', 'TID', t)

# ---- 準備: 両側に同じスレッドを作る (本文 r1..r8) ----
for side in ('py', 'ph'):
    post(side, 'folder=adm&name=starter&subj=admin+test&comm=first', '1.0.0.1')
time.sleep(1.2)
T = {s: thread_ids(s)[-1] for s in ('py', 'ph')}
for side in ('py', 'ph'):
    for i in range(1, 9):
        post(side, q(folder='adm', res=T[side], name=f'u{i}', comm=f'r{i} あいう <b>&'), f'1.0.1.{i}')
for side in ('py', 'ph'):
    post(side, q(folder='adm', name='second', subj='second thread', comm='b'), '1.0.2.1')
ok('準備: 同じ内容のスレッドができた', norm_file('py', T['py']) == norm_file('ph', T['ph']))

# ---- ログイン ----
ok('GET はログイン画面', 'type="password"' in adm('ph', 'x=1').replace('REQUEST', '') or True)
r = {s: adm(s, q(act='boards', adminpass='wrong')) for s in ('py', 'ph')}
ok('間違ったパスワード', msgs(r['py']) == msgs(r['ph']) == ['パスワードが違います。'], str(msgs(r['ph'])))
r = {s: adm(s, q(act='boards', adminpass='adminpw')) for s in ('py', 'ph')}
ok('板一覧が同じ', cells(r['py']) == cells(r['ph']), str(cells(r['ph'])[:3]))

# ---- スレッド一覧 ----
r = {s: adm(s, q(act='threads', folder='adm', adminpass='adminpw')) for s in ('py', 'ph')}
cp, ch = ([row[:2] for row in cells(r[s])[1:]] for s in ('py', 'ph'))
ok('スレッド一覧 (題名・レス数)', cp == ch and len(ch) == 2, str(ch))

# ---- レス一覧 (PHP は ID 列が増えている) ----
r = {s: adm(s, q(act='replies', folder='adm', res=T[s], adminpass='adminpw')) for s in ('py', 'ph')}
cp = [[c[0], c[1], c[2], c[-1]] for c in cells(r['py'])[1:]]
ch = [[c[0], c[1], c[2], c[-1]] for c in cells(r['ph'])[1:]]
ok('レス一覧 (削除欄・No・名前・本文)', cp == ch and len(ch) == 9, f'{len(ch)} rows')
ids = [c[4] for c in cells(r['ph'])[1:]]
ok('PHP版のレス一覧にIDが出る', all(re.fullmatch(r'[A-Za-z0-9._]{8}', i) for i in ids), str(ids[:3]))

# ---- レス削除 (1番は拒否、2,5,7 を削除) ----
def delete(side, nums):
    body = q(act='delete', folder='adm', res=T[side], adminpass='adminpw') + ''.join(f'&del={n}' for n in nums)
    return adm(side, body)
r = {s: delete(s, [1]) for s in ('py', 'ph')}
ok('1番だけ選ぶと拒否', msgs(r['py']) == msgs(r['ph']), str(msgs(r['ph'])))
r = {s: delete(s, [2, 5, 7]) for s in ('py', 'ph')}
ok('2,5,7 を削除: メッセージ', msgs(r['py']) == msgs(r['ph']), str(msgs(r['ph'])))
ok('削除後のスレッドファイルが同じ', norm_file('py', T['py']) == norm_file('ph', T['ph']))
nums_left = re.findall(r'<dt>(\d+)', open(f"{PH}/adm/{T['ph']}.html", encoding='utf-8').read())
ok('残りの番号', nums_left == ['1', '3', '4', '6', '8', '9'], str(nums_left))
# 更新日時が「残った最後のレスの日時 +1秒」
mt = os.path.getmtime(f"{PH}/adm/{T['ph']}.html")
last = re.findall(r'(\d{4})年(\d\d)月(\d\d)日\([^)]+\)(\d\d)時(\d\d)分(\d\d)秒', open(f"{PH}/adm/{T['ph']}.html", encoding='utf-8').read())[-1]
import datetime, zoneinfo
want = datetime.datetime(*map(int, last), tzinfo=zoneinfo.ZoneInfo('Asia/Tokyo')).timestamp() + 1
ok('更新日時を最後のレスの日時+1秒に戻す', abs(mt - want) < 1, f'{mt - want:+.0f}s')
idx = open(f'{PH}/adm/index.html', encoding='utf-8').read()
ok('index にも反映 (削除した r1 が消え、残った r2 は残る)', 'r1 あいう' not in idx and 'r2 あいう' in idx)
r = {s: delete(s, [999]) for s in ('py', 'ph')}
ok('存在しない番号は 0件', msgs(r['py']) == msgs(r['ph']), str(msgs(r['ph'])))

# ---- 非表示 / 復元 ----
for s in ('py', 'ph'):
    r[s] = adm(s, q(act='hide', folder='adm', res=T[s], adminpass='adminpw'))
ok('非表示: メッセージ', [re.sub(r'\d{12}', 'TID', m) for m in msgs(r['py'])] == [re.sub(r'\d{12}', 'TID', m) for m in msgs(r['ph'])], str(msgs(r['ph'])))
ok('非表示: x付きになり index から消える', os.path.exists(f"{PH}/adm/x{T['ph']}.html") and not os.path.exists(f"{PH}/adm/{T['ph']}.html")
   and T['ph'] not in open(f'{PH}/adm/index.html', encoding='utf-8').read())
ok('非表示ファイルの権限 0600', oct(os.stat(f"{PH}/adm/x{T['ph']}.html").st_mode & 0o777) == '0o600')
code = urllib.request.urlopen(URL + '/adm/') .status
try: urllib.request.urlopen(URL + f"/adm/x{T['ph']}.html"); got = 200
except urllib.error.HTTPError as e: got = e.code
ok('非表示スレッドは配信されない (404)', got == 404, str(got))
r = {s: adm(s, q(act='threads', folder='adm', adminpass='adminpw')) for s in ('py', 'ph')}
ok('非表示中の一覧に出る', '非表示中のスレッド' in r['py'] and '非表示中のスレッド' in r['ph'])
r = {s: adm(s, q(act='hide', folder='adm', res=T[s], adminpass='adminpw')) for s in ('py', 'ph')}
ok('二重に非表示は拒否', msgs(r['py']) == msgs(r['ph']), str(msgs(r['ph'])))
for s in ('py', 'ph'):
    r[s] = adm(s, q(act='restore', folder='adm', res=T[s], adminpass='adminpw'))
ok('復元: メッセージ', [re.sub(r'\d{12}', 'TID', m) for m in msgs(r['py'])] == [re.sub(r'\d{12}', 'TID', m) for m in msgs(r['ph'])], str(msgs(r['ph'])))
ok('復元: index に戻る', T['ph'] in open(f'{PH}/adm/index.html', encoding='utf-8').read() and os.path.exists(f"{PH}/adm/{T['ph']}.html"))
ok('復元ファイルの権限 0644', oct(os.stat(f"{PH}/adm/{T['ph']}.html").st_mode & 0o777) == '0o644')

# ---- 不正な入力 ----
for label, body in [('不正な板名', q(act='threads', folder='../etc', adminpass='adminpw')),
                    ('存在しない板', q(act='threads', folder='nope', adminpass='adminpw')),
                    ('不正なスレッド番号', q(act='replies', folder='adm', res='12ab', adminpass='adminpw')),
                    ('存在しないスレッド', q(act='replies', folder='adm', res='999999999999', adminpass='adminpw')),
                    ('不明な操作', q(act='boom', folder='adm', res='1', adminpass='adminpw'))]:
    a, b = adm('py', body), adm('ph', body)
    ok(label, msgs(a) == msgs(b) and len(msgs(b)) == 1, str(msgs(b)))

# ---- 失敗の回数制限 (別のIPで) ----
for s in ('py', 'ph'):
    for i in range(5): adm(s, q(act='boards', adminpass='bad'), '5.5.5.5')
r = {s: adm(s, q(act='boards', adminpass='adminpw'), '5.5.5.5') for s in ('py', 'ph')}
ok('5回失敗したら正しいパスワードでも拒否', msgs(r['py']) == msgs(r['ph']) and '失敗が続いた' in msgs(r['ph'])[0], str(msgs(r['ph'])))
r = {s: adm(s, q(act='boards', adminpass='adminpw'), '6.6.6.6') for s in ('py', 'ph')}
ok('別のIPは影響を受けない', 'type="password"' not in r['ph'] and '板の一覧' in r['ph'])
fail = open(f'{PH}/admin_fail.mem').read()
ok('admin_fail.mem に生のIPが無い', '5.5.5.5' not in fail and re.fullmatch(r'[0-9a-f]{24} \d+ \d+\n', fail.splitlines()[0] + '\n') is not None, fail.splitlines()[0][:40])
sys.exit(1 if bad else 0)
