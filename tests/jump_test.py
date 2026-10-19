# 中継ページ (jump.php) の結合テスト (PHP 版だけ)。前提は lock_test.py と同じ
import base64, os, random, re, shutil, sys, time, html, urllib.request, urllib.parse, urllib.error
PH, URL = '/tmp/phpt', 'http://127.0.0.1:8123'
bad = 0
def ok(label, cond, extra=''):
    global bad
    if not cond: bad += 1
    print(('OK   ' if cond else 'NG   ') + label + (('  ' + extra) if extra else ''))
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
OP = urllib.request.build_opener(NoRedirect)
def get(path, body=None, ip='9.9.9.9'):
    r = urllib.request.Request(URL + path, data=body.encode() if body is not None else None, headers={'CF-Connecting-IP': ip})
    try:
        with OP.open(r) as f: return f.status, f.headers, f.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e: return e.code, e.headers, e.read().decode('utf-8', 'replace')
def q(**kw): return urllib.parse.urlencode(kw)
ctr = [0]
def ip(): ctr[0] += 1; return f'11.{ctr[0] // 250}.{ctr[0] % 250}.9'
def post(res, name, comm, subj=None):
    d = dict(folder='adm', name=name, comm=comm)
    if res: d['res'] = res
    if subj: d['subj'] = subj
    c, h, t = get('/amezo.cgi', q(**d), ip())
    m = re.search(r'<h2>(.*?)</h2>', t); return c, (m.group(1) if m else '')
def b64u(s): return base64.urlsafe_b64encode(s.encode()).decode().rstrip('=')
def jump(u, s): return get('/jump.cgi?' + q(u=u, s=s))

for f in os.listdir(f'{PH}/adm'): os.unlink(f'{PH}/adm/{f}')
if os.path.exists(f'{PH}/global.mem'): os.unlink(f'{PH}/global.mem')
if os.path.exists(f'{PH}/ad.html'): os.unlink(f'{PH}/ad.html')

U1 = 'https://example.com/a?x=1&y=2'
post(None, 'owner', f'見て {U1} だよ。https://xn--n3h.example/ もある', subj='jump test'); time.sleep(1.1)
T = sorted(f[:-5] for f in os.listdir(f'{PH}/adm') if re.fullmatch(r'\d{12}\.html', f))[-1]
f = open(f'{PH}/adm/{T}.html', encoding='utf-8').read()
links = re.findall(r'<a href="(\.\./jump\.cgi\?u=([A-Za-z0-9_-]+)&amp;s=([A-Za-z0-9_-]{22}))" target="_blank" rel="noopener noreferrer nofollow">([^<]*)</a>', f)
ok('スレッドの URL が中継リンクになった (2件)', len(links) == 2, str(len(links)))
ok('リンクの表示文字は元の URL (&amp; はエスケープ)', links[0][3] == 'https://example.com/a?x=1&amp;y=2', links[0][3])
ok('href に直接の飛び先 URL が入っていない', 'href="https://' not in f and 'example.com' not in links[0][0])
ok('URL の直後の日本語は含まない', links[0][3].endswith('y=2') and '</a> だよ。' in f)
u1, s1 = links[0][1], links[0][2]

# --- 中継ページ ---
c, h, t = jump(u1, s1)
ok('署名が合えば中継ページが出る (200)', c == 200 and '外部サイトへ移動します' in t)
ok('移動先のホスト名を強調表示', '<div class="host">example.com</div>' in t)
ok('URL 全体を文字で表示', f'<div class="url">{html.escape(U1, quote=True)}</div>' in t)
ok('「進む」は元の URL へのリンク (rel/referrer 付き)', f'<a class="go" href="{html.escape(U1, quote=True)}" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer">進む</a>' in t)
ok('自動転送しない (Location も meta refresh も無い)', 'Location' not in h and 'http-equiv="refresh"' not in t.lower())
ok('noindex / no-referrer / no-store', 'noindex' in h.get('X-Robots-Tag', '') and h.get('Referrer-Policy') == 'no-referrer' and 'no-store' in h.get('Cache-Control', ''))
nonce = re.search(r"script-src 'nonce-([0-9a-f]+)'", h.get('Content-Security-Policy', ''))
ok('CSP: nonce つきスクリプトだけ許可、他のサイトに埋め込めない', bool(nonce) and f'nonce="{nonce.group(1)}"' in t and "frame-ancestors 'none'" in h['Content-Security-Policy'] and "default-src 'none'" in h['Content-Security-Policy'])
ok('xn-- のホストには注意を出す', 'xn--' in jump(links[1][1], links[1][2])[2] and 'warn' in jump(links[1][1], links[1][2])[2])
ok('広告枠は ad.html が無ければ出ない', '<iframe' not in t)

# --- 改ざん・不正な入力は、どれも「進む」を出さない ---
def refused(label, c_t):
    c, h, t = c_t
    ok(label, 'class="go"' not in t and 'このリンクは使えません' in t, f'http {c}')
s_bad = s1[:-1] + ('A' if s1[-1] != 'A' else 'B')
refused('署名を1文字変える', jump(u1, s_bad))
refused('URL を別のものに変えて、元の署名を使う', jump(b64u('https://evil.example/'), s1))
refused('署名なし', get('/jump.cgi?' + q(u=u1)))
refused('パラメータなし', get('/jump.cgi'))
refused('署名が短い', jump(u1, s1[:10]))
refused('u が壊れた base64', jump('!!!!', s1))
refused('u が長すぎる', jump('A' * 3500, s1))
refused('配列で渡す (u[]=)', get('/jump.cgi?u[]=' + u1 + '&s[]=' + s1))
refused('javascript: (署名なし)', jump(b64u('javascript:alert(1)'), 'A' * 22))
t = jump(b64u('https://a.com/"><script>alert(1)</script>'), 'A' * 22)[2]
ok('壊れた URL は画面に出さない (XSS にならない)', '<script>alert(1)' not in t and 'このリンクは使えません' in t)
random.seed(1); fz = 0
for i in range(150):
    uu = b64u(random.choice(['https://a.com/', 'http://b.example/x?y=1', 'https://c.example/' + str(i)]))
    ss = ''.join(random.choice('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-') for _ in range(22))
    if 'class="go"' in jump(uu, ss)[2]: fz += 1
ok('ランダムな署名150件は、どれも通らない (踏み台にならない)', fz == 0, f'{fz}件が通った')

# --- 署名の鍵を失った場合: 古いリンクは開かず、URL を文字で見せるだけ ---
sec = f'{PH}/jump_secret.txt'; shutil.copy(sec, sec + '.bak'); os.unlink(sec)
c, h, t = jump(u1, s1)
ok('鍵を作り直されると古いリンクは「進む」を出さない', 'class="go"' not in t and '署名が合わない' in t)
ok('その場合も URL は文字で見せる (クリックできるリンクにはしない)', html.escape(U1, quote=True) in t and f'href="{html.escape(U1, quote=True)}"' not in t)
os.unlink(sec); shutil.move(sec + '.bak', sec)
ok('鍵を戻せば、また開ける', 'class="go"' in jump(u1, s1)[2])

# --- 広告枠 (ad.html があるときだけ。同じオリジンとして扱わない iframe) ---
open(f'{PH}/ad.html', 'w', encoding='utf-8').write('<html><body>ad placeholder</body></html>')
t = jump(u1, s1)[2]
m = re.search(r'<iframe [^>]*>', t)
ok('ad.html があれば iframe が出る (sandbox、allow-same-origin なし、広告ラベル)', bool(m) and 'sandbox="allow-scripts allow-popups"' in m.group(0) and 'allow-same-origin' not in m.group(0) and '<small>広告</small>' in t)
ok('ad.html は配信される', get('/ad.html')[0] == 200)
os.unlink(f'{PH}/ad.html')
ok('ad.html を消せば、iframe は出ない・404', '<iframe' not in jump(u1, s1)[2] and get('/ad.html')[0] == 404)

# --- 周辺: res_view / index / 配信 / 二重投稿 ---
c, h, t = get('/amezo.cgi', q(folder='adm', res=T, sub='x', **{'from': 1}))
ok('レス表示 (res_view) では、中継リンクの相対パスが jump.cgi になる', 'href="jump.cgi?u=' in t and '../jump.cgi' not in t)
r = re.search(r'href="(jump\.cgi\?[^"]+)"', t).group(1)
ok('レス表示のリンクから、中継ページが開く', 'class="go"' in get('/' + html.unescape(r))[2])
idx = open(f'{PH}/adm/index.html', encoding='utf-8').read()
ok('板の index でも中継リンク (../jump.cgi)', '../jump.cgi?u=' in idx)
for path, want in [('/jump_secret.txt', 404), ('/jump.php', 200), ('/jump.cgi', 200)]:
    ok(f'{path} -> {want}', get(path)[0] == want)
c1 = post(T, 'dup', f'同じ {U1}'); c2 = post(T, 'dup', f'同じ {U1}')
ok('同じ URL を含む本文の二重投稿を、これまでどおり検知する', c1 == (303, '') and c2 == (200, '二重カキコです.'), str(c2))
print(f'NG の数: {bad}'); sys.exit(1 if bad else 0)
