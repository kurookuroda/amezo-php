import re, sys, glob, os, difflib
def norm(t):
    t = re.sub(r'20\d\d年\d\d月\d\d日\([日月火水木金土]\)\d\d時\d\d分\d\d秒', 'DATE', t)
    t = re.sub(r'<font size=-1 class="meta" data-id="[^"]*"(?: data-trip="[^"]*")?>', '<font size=-1>', t)
    t = re.sub(r'(DATE) ID:[A-Za-z0-9._]{8}', r'\1', t)
    t = t.replace('<script src="../hide.js" defer></script>', '')
    t = re.sub(r'\d{12}', 'TID', t)
    t = re.sub(r'\((?:Python|PHP) Version\)', '(X Version)', t)
    t = re.sub(r'<!-- AMEZO-NEXT \d+ -->\n', '', t)      # PHP 版では出さない (ページごとに別ファイルにしたため)
    # Python 版の「次のページ」入力欄は、PHP 版ではページ送り (pager) に置き換えた
    t = re.sub(r'<form method=post action="../amezo.cgi">\n<input type=submit value="次のページ">\n<input type=text name="next" value="\d+" size="3">〜</form>\n', '', t)
    return t
bad = 0
def cmp(a, b, label):
    global bad
    A = norm(open(a, encoding='utf-8').read()).splitlines()
    B = norm(open(b, encoding='utf-8').read()).splitlines()
    if A == B: print(f'SAME  {label} ({len(A)} lines)'); return
    bad += 1; print(f'DIFF  {label}')
    for l in difflib.unified_diff(A, B, 'python', 'php', lineterm='', n=0):
        print('   ', l[:300])
for board in ['main', 'zatsu']:
    pf = sorted(f for f in os.listdir(f'/tmp/py/{board}') if re.fullmatch(r'\d{12}\.html', f))
    hf = sorted(f for f in os.listdir(f'/tmp/phpt/{board}') if re.fullmatch(r'\d{12}\.html', f))
    print(board, 'threads py', len(pf), 'php', len(hf))
    for i, (p, h) in enumerate(zip(pf, hf)):
        cmp(f'/tmp/py/{board}/{p}', f'/tmp/phpt/{board}/{h}', f'{board} thread#{i}')
    cmp(f'/tmp/py/{board}/index.html', f'/tmp/phpt/{board}/index.html', f'{board}/index.html')
sys.exit(1 if bad else 0)
