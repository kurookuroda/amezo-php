# Python 版 (amezo.py) が出す値を JSON に書き出す。PHP 版 (tests/unit.php) がこれと一致するか確かめる。
# 使い方: cd <amezo-python のディレクトリ> && python3 <このファイル> <出力先 vectors.json>
import json, sys
sys.path.insert(0, '.')
import amezo

pws = ["pass", "ｐａｓｓ", "pa ss", "pa　ss", "パスワード", "🐱pass", "あ" * 150, "ＡＢＣ＂＇＼～", "~'\"\\", "ｱｲｳ", "ｐ" * 120, "", " ", "　"]
strs = ["abc", "あいう", "&amp;&lt;", "a&b", "🐱🐱🐱", "x" * 30, "&quot;" * 30, "日本語と&amp;と😀の混在テキスト12345678901234567890",
        "<b>", "it's", 'say "hi"', "a\u200bb\u00adc\u3164d\ufeffe", "ZWJ👨\u200d👩", "\u202eabc", "ab\u0085cd"]
inv = ["a\u200bb", "a\u200db", "\u00ad\u034f\u061c", "\u3164\uffa0", "\u2060x\u206f", "plain", "日本\u200e語"]
out = {
  "trip": [[p, amezo.make_trip(p)] for p in pws],
  "clean": [[p, amezo.clean_trip_password(p)] for p in pws],
  "len": [[s, amezo.html_chars_len(s)] for s in strs],
  "ccut": [[s, amezo.ccut(s, 25)] for s in strs],
  "esc": [[s, amezo.html_escape(s)] for s in strs],
  "inv": [[s, amezo.strip_invisible(s)] for s in inv],
  "hash": [[pw, amezo.make_hash(pw, 1000, bytes(range(16)))] for pw in ["pw", "日本語パス", ""]],
}
json.dump(out, open(sys.argv[1], 'w'), ensure_ascii=False)
print({k: len(v) for k, v in out.items()})
