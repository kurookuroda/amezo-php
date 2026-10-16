# あめぞう風掲示板 PHP 版 (ファイルベース)

Python 版 (`amezo.py` / `dai.py`) の PHP 移植。スレッドは今までどおり静的 HTML で、投稿のときだけ PHP が動く。PHP 8.1 以上。

## ファイル

| ファイル | 役割 |
|---|---|
| `lib.php` | 共通部品 (エスケープ、文字数、鍵、トリップ、ID、ハッシュ、index 再生成、過去ログ) |
| `amezo.php` | 投稿の受付 (`/amezo.cgi` もここに向く) |
| `dai.php` | 板一覧 (`/dai.cgi` もここに向く) |
| `admin.php` | 管理画面 (`/admin.cgi` もここに向く): レス削除、スレッドの非表示・復元 |
| `router.php` | `php -S` 用。許可リスト方式で静的ファイルを配る |
| `hide.js` | ID / トリップの非表示 (ブラウザの localStorage だけで動く) |
| `setup_expose.py` | Colab 用の起動スクリプト (PHP の導入、板フォルダ作成、`php -S`、Cloudflare Tunnel での公開) |
| `run.sh` | ローカル用 (板フォルダを作って `php -S` を起動。`PORT` `WORKERS` で変更可) |
| `tests/` | Python 版との突き合わせ、結合テスト、メモリ測定 |

## 動かす

### Colab (setup_expose.py)

```python
%cd /content/amezo-php                                  # このフォルダを置いた場所
!python3 setup_expose.py --make-hash 合言葉              # 管理画面のパスワードを delpass.txt に作る (最初の1回)
!python3 setup_expose.py                                 # 起動。表示された https://….trycloudflare.com/ が公開URL
```

起動のたびに、PHP 8.1 以上と mbstring が無ければ apt で入れ、古い php / cloudflared を止め、`blist.txt` の板フォルダを作り、`php -S router.php` と Cloudflare Tunnel を起動する。
起動直後に自己チェックを出す (板一覧は 200、`trip_secret.txt` `delpass.txt` `lib.php` などは 404 になっていること)。
停止はセルの停止 (または Ctrl+C)。

- **置き場所はローカルディスク (`/content` など) にする。** Google Drive のマウント上では `flock` が効かないことがあり、起動時に警告を出す。
  Colab のディスクはセッションが切れると消えるので、`trip_secret.txt` `id_secret.txt` と板フォルダは必要に応じて Drive にコピーしておく。
  鍵が消えると、全員のトリップと ID が変わる。
- 接続元の IP は、Cloudflare が付ける `CF-Connecting-IP` から取る (`127.0.0.1` からの接続のときだけ信用する)。
- `--no-tunnel` でローカルだけ、`--port` `--workers` `--verbose` で調整できる。

### ローカル (run.sh)

```sh
sh run.sh                 # http://127.0.0.1:8000/
```

## 設定

`config.php` (任意。`.gitignore` 済み) に `define('POST_INTERVAL', 3);` のように書くと、`lib.php` 冒頭の既定値を上書きできる。

公開後に変えてはいけない値は `lib.php` の `const` に分けてある: `TRIP_CTX` `ID_CTX` `TRIP_LEN` `ID_LEN` `TRIP_PASS_MAX`。
`TRIP_CTX = ''` のあいだは、同じ `trip_secret.txt` を使えば Python 版と同じトリップが出る。

## 管理画面

```sh
php amezo.php --make-hash 'パスワード' > delpass.txt     # 管理用パスワードを作る (Python 版の delpass.txt もそのまま使える)
```

`http://…/admin.cgi` を開いて、板 → スレッド → レスの順にたどる。認証は Python 版と同じで、各画面のフォームにパスワードを持たせて毎回照合する
(クッキーなし)。5回失敗したら、同じ接続元を15分間拒否する (接続元は鍵つきハッシュで数え、`admin_fail.mem` に IP は残さない)。
パスワードがフォームの hidden 欄に入るので、必ず HTTPS (cloudflared 経由など) で使うこと。

- 1番のレスは削除できない (スレッドごと消すときは「非表示」)。削除したあと、スレッドの更新日時は「残った最後のレスの日時」に戻す。
- 非表示は `x数字.html` にリネーム (権限 0600、配信されない)。復元で戻せる。
- レス一覧に ID の列を足してある (荒らしの見分け用)。

## 鍵

- `trip_secret.txt` と `id_secret.txt` は別ファイル。初回の投稿で自動作成 (`fopen('x')` で排他的に作る)。権限は 0600。
- 鍵ファイルを置く場所や作り方を変えても、鍵のバイト列が同じならトリップ・ID は変わらない。
- ID = HMAC(ID鍵, `id:` + IP + 日付(日本時間) + 板名) の先頭8文字。IPv6 は上位64bitでまとめる。IP は保存しない。
  `global.mem` (新規スレッドの連続制限) も IP ではなく鍵つきハッシュで保存する。

## Python 版との違い

- 日時・スレッド番号は日本時間 (Python 版はサーバーの時刻)。
- 二重カキコの判定が1レス目にも効く。
- NG ワードは HTML エスケープして照合する (`&` `<` を含むワードも効く)。
- 「次のページ」は共有の `index2.html` ではなく `index<番号>.html` に書く (同時に開いても混ざらない)。10分たったものは掃除する。
- `AMEZO-NEXT` のコメントは出さない。
- 投稿行に `data-id` / `data-trip` と `ID:xxxxxxxx` が入る。新しいスレッドと index には `hide.js` を読む `<script>` が入る (既存スレッドには入らない)。
- スレッドの末尾だけを読むので、レス数が増えても投稿・再生成のメモリは増えない。管理画面も1行ずつ読んで出す (5000レスのスレッドでも `memory_limit=3M` で動く)。
- 管理画面は、同じ名前の `del` が1000個を超えて送られても欠けない (PHP の `$_POST` ではなく生の本文を読む)。

## 検証した内容

- `tests/unit.php`: Python 版が出した値 (トリップ、文字数、切り詰め、エスケープ、不可視文字、pbkdf2 ハッシュ) と 98 項目一致。
- `tests/e2e.sh` + `tests/cmp.py`: 同じ投稿 25 通りを Python 版と PHP 版に流し、エラー文言と生成 HTML (スレッド・index・過去ログ) が一致 (日時・ID・番号などは正規化)。
- 同時投稿 60 件 → 61 レスが欠けずに連番、返信フォームは1つ。
- 200 スレッドの板 + 5000 レスのスレッドで index 再生成のピークメモリ 0.71MB。`memory_limit=3M` でも 5.5MB のスレッドへ投稿できる。
- `tests/admin_cmp.py`: 管理画面の操作 (ログイン、一覧、レス削除、非表示・復元、不正入力、失敗の回数制限) を Python 版と PHP 版に流して 31 項目一致。
  管理画面の削除と投稿 30 件を同時に流しても、投稿は欠けず、レス番号は増え続け、返信フォームは1つのまま。
- 配信の許可リスト: 鍵、`cap.txt`、`ng.txt`、`lib.php`、`config.php`、`x〜.html` などは 404。

## まだ無いもの / 未確認

- 既存スレッドに `hide.js` を入れる処理 (新しいスレッドと index にだけ入る)。
- `>>数字` のアンカーと連鎖あぼーん (今は `>>` を `>` に畳む仕様)。
- Colab 上での実行、PHP 8.1、実際の cloudflared 経由のアクセス (テストは PHP 8.3 + `php -S` + `--no-tunnel` だけ)。nginx + PHP-FPM も未確認。
