#!/usr/bin/env python3
# -*- coding: utf-8 -*-
#
# setup_expose.py - あめぞう風掲示板 PHP 版を Colab などで公開する起動スクリプト
#
#   1. PHP (8.1 以上 + mbstring) が無ければ apt で入れる
#   2. blist.txt の板フォルダを作る (index.html が無ければ転送ページを置く)
#   3. php -S (router.php) を起動する
#        - /amezo.cgi /dai.cgi /admin.cgi は PHP がそのまま実行する (フォームの action="../amezo.cgi" のまま動く)
#        - 静的ファイルは router.php の許可リスト方式で配る (鍵、delpass.txt、x〜.html、ソースは 404)
#   4. Cloudflare Tunnel で外部公開する
#
#   使い方 (Colab):
#     !python3 setup_expose.py --make-hash 合言葉     # 管理画面のパスワードを delpass.txt に作る (最初の1回)
#     !python3 setup_expose.py                         # 起動 (Ctrl+C / セルの停止で止まる)
#   ローカルで試すだけ (トンネルなし):
#     python3 setup_expose.py --no-tunnel
#   メモリを節約したいとき (起動後に、このランチャー自身は終了して、PHP とトンネルだけを残す):
#     !python3 setup_expose.py --detach        # 止めるとき: !pkill -x php; pkill -x cloudflared

import argparse
import fcntl
import hashlib
import os
import re
import secrets
import shutil
import signal
import socket
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request

PORT = 8000
WORKERS = 2                 # PHP_CLI_SERVER_WORKERS (同時に処理するプロセス数。多いほどメモリを使う)
MIN_PHP_ID = 80100          # PHP 8.1
HASH_ITERATIONS = 100000    # lib.php の HASH_ITERATIONS と同じにする
DEFAULT_BOARDS = ["main", "zatsu", "test"]

BASE_DIR = None             # main() で決める


def load_boards():
    """blist.txt に載っている板フォルダ名を、並び順のまま返す"""
    boards = []
    try:
        with open("blist.txt", "r", encoding="utf-8") as f:
            for line in f:
                name = line.strip().split(",")[0]
                if name and not name.startswith(".") and "/" not in name and "\\" not in name:
                    boards.append(name)
    except OSError:
        pass
    return boards


# ---- 管理用パスワード --------------------------------------------------------

def make_hash(password, iterations=HASH_ITERATIONS):
    """lib.php の make_hash() と同じ形式 (pbkdf2_sha256$回数$salt$hash)。PHP 無しで作れる"""
    salt = secrets.token_bytes(16)
    dk = hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), salt, iterations)
    return f"pbkdf2_sha256${iterations}${salt.hex()}${dk.hex()}"


def write_delpass(password):
    if not password:
        print("❌ 合言葉が空です。")
        return False
    fd = os.open("delpass.txt", os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        f.write(make_hash(password) + "\n")
    print("✅ delpass.txt を作りました。管理画面は /admin.cgi です。")
    return True


# ---- PHP -------------------------------------------------------------------

def php_version_id():
    try:
        out = subprocess.run(["php", "-r", "echo PHP_VERSION_ID;"], capture_output=True, text=True, timeout=15).stdout.strip()
        return int(out)
    except (OSError, ValueError, subprocess.SubprocessError):
        return 0


def php_has_mbstring():
    try:
        return subprocess.run(["php", "-r", 'exit(extension_loaded("mbstring") ? 0 : 1);'], timeout=15).returncode == 0
    except (OSError, subprocess.SubprocessError):
        return False


def ensure_php():
    if php_version_id() >= MIN_PHP_ID and php_has_mbstring():
        print(f"✅ PHP {php_version_id() // 10000}.{php_version_id() // 100 % 100} が使えます。")
        return True
    print("📦 PHP (php-cli と php-mbstring) をインストールしています... (初回だけ、1〜2分かかります)")
    sudo = "" if os.geteuid() == 0 else "sudo "
    os.system(f"{sudo}apt-get update -qq")
    os.system(f"{sudo}env DEBIAN_FRONTEND=noninteractive apt-get install -y -qq php-cli php-mbstring")
    if php_version_id() >= MIN_PHP_ID and php_has_mbstring():
        print("✅ PHP をインストールしました。")
        return True
    print("❌ PHP 8.1 以上 (mbstring つき) を用意できませんでした。apt の出力を確認してください。")
    return False


REQUIRED_FUNCS = ["ctype_digit", "ctype_xdigit", "filter_var", "inet_pton", "hash_hmac", "hash_pbkdf2", "hash_equals",
                  "random_bytes", "mb_check_encoding", "mb_convert_encoding", "mb_scrub", "mb_chr", "mb_ord", "mb_substr",
                  "preg_match_all", "flock", "stream_copy_to_stream", "tmpfile"]


def php_runtime_args(trim=True):
    """
    php に渡す起動引数。
    trim=True のときは php.ini を読まず (-n)、必要な拡張 (mbstring, ctype) だけを読み込む。
    余計な拡張 (約20個) を読み込まないぶん、常駐メモリが 5MB ほど減る。
    必要な関数が1つでも欠けるときは、安全のため通常の起動 (空の引数) に戻す。
    """
    limit = ["-d", "memory_limit=32M"]       # 想定外の入力でメモリを使い切らないための上限 (5MBのスレッドでも 3MB で足りる)
    if not trim:
        return limit
    try:
        ext_dir = subprocess.run(["php", "-r", 'echo ini_get("extension_dir");'], capture_output=True, text=True, timeout=15).stdout.strip()
    except (OSError, subprocess.SubprocessError):
        return limit
    args = ["-n"]
    for ext in ("mbstring", "ctype"):
        so = os.path.join(ext_dir, ext + ".so")
        if os.path.exists(so):
            args += ["-d", f"extension={so}"]
    check = "foreach (" + "[" + ",".join(f'"{f}"' for f in REQUIRED_FUNCS) + "] as $f) if (!function_exists($f)) { echo $f; exit(1); }"
    try:
        r = subprocess.run(["php", *args, "-r", check], capture_output=True, text=True, timeout=15)
    except (OSError, subprocess.SubprocessError):
        return limit
    if r.returncode != 0:
        print(f"  ℹ️ 拡張を絞った起動は使えません ({r.stdout.strip() or '確認に失敗'} が無い)。通常の起動にします。")
        return limit
    return args + limit


# ---- 事前チェックと準備 -------------------------------------------------------

def check_flock():
    """このディレクトリで flock が効くか確かめる (Google Drive のマウント上では効かないことがある)"""
    path = os.path.join(BASE_DIR, ".flock_test")
    fd1 = fd2 = None
    try:
        fd1 = os.open(path, os.O_RDWR | os.O_CREAT, 0o600)
        fd2 = os.open(path, os.O_RDWR)
        fcntl.flock(fd1, fcntl.LOCK_EX)
        try:
            fcntl.flock(fd2, fcntl.LOCK_EX | fcntl.LOCK_NB)
            effective = False
        except OSError:
            effective = True
    except OSError as e:
        print(f"  ⚠️ ロックの確認ができませんでした: {e}")
        return
    finally:
        for fd in (fd1, fd2):
            if fd is not None:
                os.close(fd)
        try:
            os.unlink(path)
        except OSError:
            pass
    if not effective:
        print("  ⚠️ このディレクトリでは flock が効いていません (Google Drive 上など)。")
        print("     同時に投稿されるとファイルが壊れるおそれがあります。/content などのローカルディスクに置いてください。")


def setup_bbs_directories():
    print("📂 BBS用のディレクトリを確認しています...")
    for needed in ["router.php", "lib.php", "amezo.php", "dai.php", "admin.php", "hide.js",
                   "index.html", "style.css", "header.html", "blist.txt"]:
        if not os.path.exists(needed):
            print(f"  ⚠️ {needed} が見つかりません")
    if not os.path.exists("delpass.txt"):
        print("  ℹ️ delpass.txt がありません。管理画面 (削除・非表示) を使うなら、次で作成してください:")
        print("     !python3 setup_expose.py --make-hash 合言葉")
    check_flock()

    boards = load_boards() if os.path.exists("blist.txt") else DEFAULT_BOARDS
    for board in boards:
        if not os.path.exists(board):
            os.makedirs(board, exist_ok=True)
            print(f"  - フォルダ作成: {board}/")
        os.chmod(board, 0o777)

        # index.html が無い (または極端に小さい) 場合は、再生成を促す転送ページを置く
        index_path = os.path.join(board, "index.html")
        if not os.path.exists(index_path) or os.path.getsize(index_path) < 100:
            with open(index_path, "w", encoding="utf-8") as f:
                f.write(
                    f'<html><head><meta http-equiv="refresh" content="0;url=../amezo.cgi?folder={board}">'
                    f'</head><body>Redirecting to BBS...</body></html>'
                )
            # 更新日時を古くして、amezo.php の「直近に更新済みなら再生成しない」判定を避ける
            old = time.time() - 3600
            os.utime(index_path, (old, old))
            print(f"  - 初期リダイレクト用ファイルを配置: {index_path}")


def cleanup_old_processes(port):
    print("🧹 古いWebサーバーやトンネルプロセスをクリーンアップしています...")
    os.system("pkill -x cloudflared 2>/dev/null")
    os.system("pkill -x php 2>/dev/null")
    if shutil.which("fuser"):
        os.system(f"fuser -k {port}/tcp 2>/dev/null")
    time.sleep(2)


# ---- Web サーバー (php -S) ------------------------------------------------------

def start_php_server(port, workers, verbose=False, detach=False, trim=True):
    runtime = php_runtime_args(trim)
    note = "拡張を絞って" if "-n" in runtime else "通常の設定で"
    print(f"🚀 PHP Webサーバーを起動しています ({note}、ポート {port}, ワーカー {workers})...")
    env = dict(os.environ, PHP_CLI_SERVER_WORKERS=str(workers))
    cmd = ["php", *runtime, "-S", f"127.0.0.1:{port}", "router.php"]
    if detach:
        # ランチャーが終わってもサーバーが残るように、別セッションで起動してログはファイルへ
        log = open("php.log", "ab")
        return subprocess.Popen(cmd, cwd=BASE_DIR, env=env, stdin=subprocess.DEVNULL, stdout=log, stderr=subprocess.STDOUT,
                                start_new_session=True)
    proc = subprocess.Popen(cmd, cwd=BASE_DIR, env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, bufsize=1)

    def read_output():
        for line in iter(proc.stdout.readline, ""):
            if not verbose and (" Accepted" in line or " Closing" in line):
                continue            # 接続のたびに出る行は省く
            print(f"[PHP] {line.rstrip()}")

    threading.Thread(target=read_output, daemon=True).start()
    return proc


def wait_for_server(port, timeout=15):
    start = time.time()
    while time.time() - start < timeout:
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=1):
                print(f"✅ Webサーバーがポート {port} で応答しました。")
                return True
        except OSError:
            time.sleep(0.5)
    return False


def http_status(port, path):
    try:
        with urllib.request.urlopen(f"http://127.0.0.1:{port}{path}", timeout=10) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code
    except OSError:
        return 0


def self_check(port):
    """起動直後の確認: 板一覧は出る / 鍵やパスワードや PHP のソースは配られない"""
    print("🔎 起動チェック:")
    good = True
    for path, want in [("/dai.cgi", 200), ("/style.css", 200), ("/hide.js", 200),
                       ("/trip_secret.txt", 404), ("/id_secret.txt", 404), ("/delpass.txt", 404),
                       ("/cap.txt", 404), ("/lib.php", 404), ("/blist.txt", 404)]:
        got = http_status(port, path)
        mark = "✅" if got == want else "❌"
        if got != want:
            good = False
        print(f"  {mark} {path} -> {got}" + ("" if got == want else f" (期待: {want})"))
    if not good:
        print("  ⚠️ 期待と違う結果があります。公開する前に router.php を確認してください。")
    return good


# ---- トンネルによる外部公開 -------------------------------------------------------

def ensure_cloudflared():
    if shutil.which("cloudflared"):
        return "cloudflared"
    print("cloudflared をダウンロード中...")
    os.system("curl -sL https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64 -o cloudflared")
    os.system("chmod +x cloudflared")
    return "./cloudflared"


URL_RE = re.compile(r"https://[a-zA-Z0-9-]+\.trycloudflare\.com")


def print_public_url(url):
    print(f"\n🎉 【BBSアクセス先URL】: {url}/")
    print(f"   管理画面: {url}/admin.cgi\n")


def start_tunnel(port, detach=False):
    print("☁️ Cloudflare Tunnel をセットアップしています...")
    cloudflared_bin = ensure_cloudflared()
    cmd = [cloudflared_bin, "tunnel", "--url", f"http://127.0.0.1:{port}"]

    if detach:
        # ログをファイルに書かせ、そこから公開URLを探す。ランチャーが終わってもトンネルは残る
        log_path = os.path.join(BASE_DIR, "cloudflared.log")
        with open(log_path, "wb") as log:
            proc = subprocess.Popen(cmd, stdin=subprocess.DEVNULL, stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        print("⏳ トンネルの公開URLを取得しています...")
        deadline = time.time() + 30
        while time.time() < deadline:
            try:
                with open(log_path, "r", encoding="utf-8", errors="replace") as f:
                    m = URL_RE.search(f.read())
            except OSError:
                m = None
            if m:
                print_public_url(m.group(0))
                return proc
            time.sleep(1)
        print("❌ 公開URLの取得に失敗しました。cloudflared.log を確認してください。")
        return proc

    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, bufsize=1)
    url_event = threading.Event()
    public_url = [""]

    def read_output():
        for line in iter(proc.stdout.readline, ""):
            print(f"[CF_OUT] {line.strip()}")
            m = URL_RE.search(line)
            if m and not public_url[0]:
                public_url[0] = m.group(0)
                url_event.set()

    threading.Thread(target=read_output, daemon=True).start()

    print("⏳ トンネルの公開URLを取得しています...")
    if url_event.wait(timeout=30):
        print_public_url(public_url[0])
    else:
        print("❌ 公開URLの取得に失敗しました。トンネルプロセスの出力を確認してください。")
    return proc


# ---- メイン ------------------------------------------------------------------

def main():
    global BASE_DIR
    ap = argparse.ArgumentParser(description="あめぞう風掲示板 PHP 版の起動スクリプト")
    ap.add_argument("--make-hash", metavar="合言葉", help="管理画面のパスワードを delpass.txt に作って終了する")
    ap.add_argument("--no-tunnel", action="store_true", help="トンネルを張らず、ローカルだけで起動する")
    ap.add_argument("--port", type=int, default=PORT)
    ap.add_argument("--workers", type=int, default=WORKERS)
    ap.add_argument("--verbose", action="store_true", help="PHP サーバーの接続ログも全部出す")
    ap.add_argument("--detach", action="store_true",
                    help="起動できたらランチャーは終了し、PHP とトンネルだけを残す (メモリ節約。ログは php.log / cloudflared.log)")
    ap.add_argument("--no-trim", action="store_true", help="PHP の拡張を絞らず、通常の設定で起動する")
    args = ap.parse_args()

    # __file__ が無い環境 (Jupyter/Colab) では os.getcwd() を使う
    try:
        BASE_DIR = os.path.dirname(os.path.abspath(__file__))
    except NameError:
        BASE_DIR = os.getcwd()
    os.chdir(BASE_DIR)

    if args.make_hash is not None:
        sys.exit(0 if write_delpass(args.make_hash) else 1)

    if not ensure_php():
        sys.exit(1)
    cleanup_old_processes(args.port)
    setup_bbs_directories()

    php_proc = start_php_server(args.port, args.workers, args.verbose, args.detach, not args.no_trim)
    tunnel_proc = None
    keep_running = False

    def stop(*_):
        raise KeyboardInterrupt
    signal.signal(signal.SIGTERM, stop)

    try:
        if not wait_for_server(args.port):
            print("❌ Webサーバーが起動しませんでした。")
            return 1
        self_check(args.port)
        if args.no_tunnel:
            print(f"\n🏠 ローカルで起動しました: http://127.0.0.1:{args.port}/\n")
        else:
            tunnel_proc = start_tunnel(args.port, args.detach)

        if args.detach:
            keep_running = True
            print("✅ ランチャーは終了します。PHP" + ("" if tunnel_proc is None else " とトンネル") + "は動き続けます。")
            print("   止めるとき: !pkill -x php; pkill -x cloudflared    ログ: php.log / cloudflared.log")
            return 0

        print("⏳ サーバー稼働中... 停止するには Ctrl+C (Colab ではセルの停止) を押してください。")
        while True:
            if php_proc.poll() is not None:
                print("⚠️ PHP サーバーが終了しました。")
                break
            if tunnel_proc is not None and tunnel_proc.poll() is not None:
                print("⚠️ トンネルプロセスが終了しました。")
                break
            time.sleep(5)
    except KeyboardInterrupt:
        print("\n🛑 終了シグナルを検知しました。プロセスを停止します...")
    finally:
        if not keep_running:
            for p in (tunnel_proc, php_proc):
                if p is not None and p.poll() is None:
                    p.terminate()
            print("✅ プロセスを停止しました。")
    return 0


if __name__ == "__main__":
    sys.exit(main())
