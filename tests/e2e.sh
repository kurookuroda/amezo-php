#!/bin/bash
PY=/tmp/py; PH=/tmp/phpt; URL=http://127.0.0.1:8123/amezo.cgi
n=0
pypost() { # $1=body $2=ip
  printf '%s' "$1" | (cd $PY && REQUEST_METHOD=POST CONTENT_LENGTH=${#1} REMOTE_ADDR=${2:-9.9.9.9} python3 amezo.py)
}
phpost() {
  curl -s -i -d "$1" -H "CF-Connecting-IP: ${2:-9.9.9.9}" $URL
}
errtxt() { grep -o '<h2>[^<]*</h2>' | head -1; }
both() { # label, body(with RES placeholder), ip, uses_res
  local label=$1 body=$2 ip=$3
  n=$((n+1)); local ip2=${ip:-10.0.0.$n}
  local pb=${body//@RES@/$PYRES} hb=${body//@RES@/$PHRES}
  local pe=$(pypost "$pb" $ip2 | errtxt); local he=$(phpost "$hb" $ip2 | errtxt)
  echo "[$label] py='${pe}' php='${he}'"
}
thread_id() { ls $1/main | grep -E '^[0-9]{12}\.html$' | sort | tail -1 | sed 's/.html//'; }

# 1. 新規スレッド (名前#パス、URL、改行、&<>、メール)
both new1 'folder=main&name=neko%23pass&subj=hello+world&comm=line1%0D%0Aline2+%26+%3Cb%3E+http%3A%2F%2Fa.com%2F%3Fx%3D1%26y%3D2+%27q%27&mail=a%40b.c' 1.1.1.1
sleep 1
PYRES=$(thread_id $PY); PHRES=$(thread_id $PH); echo "res py=$PYRES php=$PHRES"
# 2. 返信
both reply1 'folder=main&res=@RES@&name=taro&comm=reply1&mail=' 2.2.2.2
both reply-dup 'folder=main&res=@RES@&name=taro&comm=reply1&mail=' 2.2.2.2
both reply-fullwidth-sharp 'folder=main&res=@RES@&name=%EF%BD%81%EF%BD%82%EF%BD%83%EF%BC%83pw&comm=fw+sharp' 2.2.2.3
both reply-fw-pass 'folder=main&res=@RES@&name=x%23%EF%BD%90%EF%BD%81%EF%BD%93%EF%BD%93&comm=fwpass' 2.2.2.4
both reply-admin 'folder=main&res=@RES@&name=%E3%82%B5%E3%83%9D%E3%83%BC%E3%83%88&comm=im+admin' 2.2.2.5
both reply-cap 'folder=main&res=@RES@&name=Admin%23secret&comm=cap+post' 2.2.2.6
both reply-cap-wrongpw 'folder=main&res=@RES@&name=Admin%23nope&comm=cap+wrong' 2.2.2.7
both reply-mark 'folder=main&res=@RES@&name=%E2%97%86fake%E2%98%85&comm=mark' 2.2.2.8
both reply-quote 'folder=main&res=@RES@&name=q&comm=%3E%3E12+%3E%3E%3E5+hi' 2.2.2.9
both reply-ng-remove 'folder=main&res=@RES@&name=ng1&comm=this+is+badword+ok' 2.2.2.10
both reply-ng-c 'folder=main&res=@RES@&name=ng2&comm=buy+spam+now' 2.2.2.11
both reply-ng-n 'folder=main&res=@RES@&name=banname&comm=x' 2.2.2.12
both reply-sjis 'folder=main&res=@RES@&name=%82%A0&comm=%82%A0%82%A2' 2.2.2.13
both reply-emoji 'folder=main&res=@RES@&name=%F0%9F%90%B1cat&comm=%F0%9F%90%B1%F0%9F%90%B1' 2.2.2.14
L=$(python3 -c "print('%E3%81%82'*401)")
both toolong "folder=main&res=@RES@&name=l&comm=$L" 2.2.2.15
both noname 'folder=main&res=@RES@&name=&comm=x' 2.2.2.16
both nocomm 'folder=main&res=@RES@&name=n&comm=' 2.2.2.17
both badfolder 'folder=nope&res=@RES@&name=n&comm=x' 2.2.2.18
both badres 'folder=main&res=12ab&name=n&comm=x' 2.2.2.19
both missing 'folder=main&res=999999999999&name=n&comm=x' 2.2.2.20
both nosubj 'folder=main&name=n&comm=x' 2.2.2.21
S=$(python3 -c "print('a'*46)")
both subjlong "folder=main&name=n&subj=$S&comm=x" 2.2.2.22
sleep 1
# 2つ目のスレッド (並び順確認用) と別の板
both new2 'folder=main&name=ana&subj=second&comm=body2' 3.3.3.3
both new-zatsu 'folder=zatsu&name=zz%23t&subj=zatsu+thread&comm=zz' 3.3.3.4
both reload-get-like 'folder=main' 3.3.3.5
echo DONE
