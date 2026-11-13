#!/bin/sh
# bmon-update.sh — installs the configuration published for this host in
# Bmon-web (AgentConf → Publish). Run by the "Bmon auto-update" check of Monit:
# every N cycles, and at once with "start" (AgentConf → Update now).
#
#   bmon-update.sh --url https://bmon.example.com --conf-dir /etc/monit/conf.d \
#                  --monitrc /etc/monit/monitrc [--script-dir DIR] [--cacert FILE] [--now]
#
# 1. asks Bmon-web whether a new version is published, signing the request with
#    HMAC-SHA256 keyed with the Monit httpd password ("allow user:password" of
#    the Bmon configuration in --conf-dir, else of monitrc);
# 2. checks the signature and the SHA-256 of the zip it receives;
# 3. backs up --conf-dir and --script-dir, empties them (they belong to Bmon),
#    installs the zip, runs "monit -t" and restores the backup if it fails;
# 4. reports the result to Bmon-web and reloads Monit (restarts it when the
#    "set httpd" settings changed, e.g. a new certificate).
#
# Needs: sh, curl, unzip, openssl, od. The password never appears on a command
# line (ps), only in files of a private temporary directory.
#
# Part of Bmon-web (AGPL-3.0). Exit 0 = installed or already up to date.

PROTO='bmon-update/1'
STATE_DIR=${BMON_UPDATE_STATE:-/var/lib/bmon-update}
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export PATH
umask 077

URL='' CONF='' RC='' SDIR='' CACERT='' MODE='poll'
while [ $# -gt 0 ]; do
    case $1 in
        --url) URL=${2%/}; shift ;;
        --conf-dir) CONF=${2%/}; shift ;;
        --monitrc) RC=$2; shift ;;
        --script-dir) SDIR=${2%/}; shift ;;
        --cacert) CACERT=$2; shift ;;
        --now) MODE='now' ;;
        *) echo "bmon-update: unknown option $1"; exit 2 ;;
    esac
    shift
done
[ -n "$SDIR" ] || SDIR=$(cd "$(dirname "$0")" && pwd)

fail() { echo "bmon-update: $*"; exit 1; }
[ -n "$URL" ] && [ -n "$CONF" ] && [ -n "$RC" ] || fail "usage: --url URL --conf-dir DIR --monitrc FILE"
for c in curl unzip openssl od; do
    command -v $c >/dev/null 2>&1 || fail "$c is required"
done

# --- run from a private copy: the installation replaces this very file ------------
TMP=$(mktemp -d "${TMPDIR:-/tmp}/bmon-update.XXXXXX") || fail "cannot create a temporary directory"
if [ -z "$BMON_UPDATE_COPY" ] || [ ! -f "$BMON_UPDATE_COPY/self.sh" ]; then
    cp "$0" "$TMP/self.sh" || fail "cannot copy myself"
    BMON_UPDATE_COPY=$TMP exec sh "$TMP/self.sh" --url "$URL" --conf-dir "$CONF" --monitrc "$RC" \
        --script-dir "$SDIR" ${CACERT:+--cacert "$CACERT"} $( [ "$MODE" = now ] && echo --now )
fi
rmdir "$TMP" 2>/dev/null; TMP=$BMON_UPDATE_COPY
unset BMON_UPDATE_COPY   # not inherited by Monit when this script restarts it
LOCK="$STATE_DIR/lock"
cleanup() { rm -rf "$TMP"; [ -n "$LOCKED" ] && rmdir "$LOCK" 2>/dev/null; }
trap cleanup EXIT
trap 'exit 1' INT TERM HUP

mkdir -p "$STATE_DIR" && chmod 700 "$STATE_DIR" || fail "cannot create $STATE_DIR"
if ! mkdir "$LOCK" 2>/dev/null; then
    # stale lock (older than 15 minutes)?
    if [ -n "$(find "$LOCK" -maxdepth 0 -mmin +15 2>/dev/null)" ]; then
        rmdir "$LOCK" 2>/dev/null; mkdir "$LOCK" 2>/dev/null || fail "another run is in progress"
    else
        echo "bmon-update: another run is in progress"; exit 0
    fi
fi
LOCKED=1

# --- safety: the two directories are emptied, so they must be Bmon's only ----------
RCDIR=$(cd "$(dirname "$RC")" 2>/dev/null && pwd) || fail "monitrc $RC not found"
[ -f "$RC" ] || fail "monitrc $RC not found"
for d in "$CONF" "$SDIR"; do
    case $d in
        /|/etc|/usr|/usr/local|/usr/bin|/usr/sbin|/usr/local/bin|/usr/local/sbin|/bin|/sbin|/var|/var/lib|/opt|/home|/root|/tmp|/boot|/lib|/srv|"$RCDIR"|'')
            fail "refusing to empty $d: the include and script directories must be used only by Bmon" ;;
        /*) ;;
        *) fail "$d is not an absolute path" ;;
    esac
done

# --- monit, Monit ID, httpd credentials --------------------------------------------
MONIT=$(command -v monit 2>/dev/null)
for m in /usr/local/bin/monit /usr/bin/monit /usr/local/monit/bin/monit /opt/monit/bin/monit; do
    [ -n "$MONIT" ] && break
    [ -x "$m" ] && MONIT=$m
done
[ -n "$MONIT" ] || fail "monit binary not found"

IDFILE=$(sed -n 's/^[[:space:]]*set[[:space:]]\{1,\}idfile[[:space:]]\{1,\}"\{0,1\}\([^"[:space:]]*\).*/\1/p' "$RC" | tail -n 1)
for f in "$IDFILE" /var/lib/monit/id "$HOME/.monit.id" /root/.monit.id; do
    [ -n "$f" ] && [ -s "$f" ] && { MONIT_ID=$(tr -cd 'A-Za-z0-9' < "$f"); break; }
done
[ -n "$MONIT_ID" ] || fail "Monit ID not found (set idfile in monitrc?)"

# "allow user:password" of the file with "set httpd": first the Bmon configuration
creds_from() {
    sed -n 's/^[[:space:]]*allow[[:space:]]\{1,\}\([A-Za-z0-9._-]\{1,\}\):"\([^"]*\)".*/\1 \2/p
            s/^[[:space:]]*allow[[:space:]]\{1,\}\([A-Za-z0-9._-]\{1,\}\):\([^"[:space:]]\{1,\}\).*/\1 \2/p' "$1" | head -n 1
}
CREDS=''
for f in "$CONF"/* "$RC"; do
    [ -f "$f" ] && grep -qi '^[[:space:]]*set[[:space:]]\{1,\}httpd' "$f" 2>/dev/null || continue
    CREDS=$(creds_from "$f")
    [ -n "$CREDS" ] && { CREDS_FILE=$f; break; }
done
[ -n "$CREDS" ] || fail "no \"allow user:password\" in the set httpd of $CONF or $RC"
HUSER=${CREDS%% *}
printf '%s' "${CREDS#* }" > "$TMP/key"
CREDS=''

# --- HMAC-SHA256 with the key in a file (RFC 2104, 64-byte blocks) -----------------
sha256_hex() { openssl dgst -sha256 -r | cut -d' ' -f1; }
pad_key() {    # $1 = xor byte (54 ipad, 92 opad) -> 64 bytes on stdout
    k="$TMP/key"
    if [ "$(wc -c < "$k")" -gt 64 ]; then
        openssl dgst -sha256 -binary < "$TMP/key" > "$TMP/key.h"; k="$TMP/key.h"
    fi
    n=0
    for b in $(od -An -v -tu1 "$k"); do
        printf "\\$(printf '%03o' $((b ^ $1)))"; n=$((n + 1))
    done
    while [ $n -lt 64 ]; do printf "\\$(printf '%03o' "$1")"; n=$((n + 1)); done
}
hmac() {       # data on stdin -> hex
    pad_key 54 > "$TMP/ipad"; pad_key 92 > "$TMP/opad"
    cat "$TMP/ipad" - | openssl dgst -sha256 -binary > "$TMP/inner"
    cat "$TMP/opad" "$TMP/inner" | sha256_hex
}

CURL="curl -sS --max-time 120 --proto =https,http"
[ -n "$CACERT" ] && CURL="$CURL --cacert $CACERT"

report() {     # $1 status, $2 version, message in $TMP/msg
    rts=$(date +%s)
    { printf '%s|result|%s|%s|%s|%s\n' "$PROTO" "$MONIT_ID" "$rts" "$2" "$1"; cat "$TMP/msg"; } | hmac > "$TMP/rsig"
    $CURL -o "$TMP/report.out" -w '%{http_code}' \
        --data-urlencode "id=$MONIT_ID" --data-urlencode "user=$HUSER" --data-urlencode "ts=$rts" \
        --data-urlencode "version=$2" --data-urlencode "status=$1" --data-urlencode "message@$TMP/msg" \
        --data-urlencode "sig=$(cat "$TMP/rsig")" "$URL/agent/v1/result" > "$TMP/report.code" 2>&1
}

# --- ask Bmon-web --------------------------------------------------------------------
HAVE=$(cat "$STATE_DIR/version" 2>/dev/null | tr -cd '0-9a-f')
TS=$(date +%s)
SIG=$(printf '%s|config|%s|%s|%s' "$PROTO" "$MONIT_ID" "$TS" "$HAVE" | hmac)
CODE=$($CURL -o "$TMP/zip" -D "$TMP/headers" -w '%{http_code}' \
    "$URL/agent/v1/config?id=$MONIT_ID&user=$HUSER&ts=$TS&have=$HAVE&sig=$SIG" 2>"$TMP/curl.err")
header() { tr -d '\r' < "$TMP/headers" | awk -v h="$1" 'BEGIN { h = tolower(h) }
    { i = index($0, ":"); if (i && tolower(substr($0, 1, i - 1)) == h) { v = substr($0, i + 1); sub(/^[ \t]+/, "", v) } }
    END { print v }'; }
case $CODE in
    204) echo "up to date (version $(echo "$HAVE" | cut -c1-12))"; exit 0 ;;
    200) ;;
    000) fail "cannot reach $URL: $(cat "$TMP/curl.err")" ;;
    *) fail "Bmon-web answered $CODE: $(head -c 300 "$TMP/zip")" ;;
esac

VERSION=$(header X-Bmon-Version | tr -cd '0-9a-f')
GOT=$(sha256_hex < "$TMP/zip")
[ -n "$VERSION" ] && [ "$GOT" = "$VERSION" ] || fail "the zip does not match its version (got $GOT): download corrupted?"
WANT=$(printf '%s|zip|%s|%s|%s' "$PROTO" "$MONIT_ID" "$TS" "$VERSION" | hmac)
[ "$(header X-Bmon-Signature | tr -cd '0-9a-f')" = "$WANT" ] || fail "wrong signature on the zip: not from Bmon-web, refused"
SHORT=$(echo "$VERSION" | cut -c1-12)
if [ "$MODE" = poll ] && [ "$(cat "$STATE_DIR/rejected" 2>/dev/null)" = "$VERSION" ]; then
    fail "version $SHORT was rejected by monit -t here: publish a fixed one (or use Update now to try again)"
fi

mkdir "$TMP/new" && unzip -q "$TMP/zip" -d "$TMP/new" || fail "cannot extract the zip"
rm -f "$TMP/new/README.txt"
ls "$TMP/new"/*.conf >/dev/null 2>&1 || fail "the zip has no checks: refused"

# --- back up, install, monit -t --------------------------------------------------------
SDIR_INSIDE=no
case $SDIR/ in "$CONF"/*) SDIR_INSIDE=yes ;; esac
rm -rf "$STATE_DIR/backup" && mkdir -p "$STATE_DIR/backup/conf" "$STATE_DIR/backup/scripts" || fail "cannot prepare the backup"
[ -d "$CONF" ] && cp -pR "$CONF"/. "$STATE_DIR/backup/conf/"
[ $SDIR_INSIDE = no ] && [ -d "$SDIR" ] && cp -pR "$SDIR"/. "$STATE_DIR/backup/scripts/"

httpd_conf() {  # "set httpd" settings in a directory + monitrc, to tell a reload from a restart
    for f in "$1"/* "$RC"; do
        [ -f "$f" ] && grep -qi '^[[:space:]]*set[[:space:]]\{1,\}httpd' "$f" && cat "$f"
    done | grep -v '^[[:space:]]*#' | sha256_hex
}
OLD_HTTPD=$(httpd_conf "$CONF")

empty_dir() { mkdir -p "$1" && find "$1" -mindepth 1 -maxdepth 1 -exec rm -rf {} + ; }
restore() {
    empty_dir "$CONF"; cp -pR "$STATE_DIR/backup/conf"/. "$CONF/"
    if [ $SDIR_INSIDE = no ]; then empty_dir "$SDIR"; cp -pR "$STATE_DIR/backup/scripts"/. "$SDIR/"; fi
}

empty_dir "$CONF" || fail "cannot empty $CONF"
[ $SDIR_INSIDE = no ] && { empty_dir "$SDIR" || { restore; fail "cannot empty $SDIR"; }; }
mkdir -p "$SDIR"
for f in "$TMP/new"/*; do
    [ -f "$f" ] || continue
    cp "$f" "$CONF/" && chmod 600 "$CONF/$(basename "$f")"
done
if [ -d "$TMP/new/bmon-scripts" ]; then
    for f in "$TMP/new/bmon-scripts"/* "$TMP/new/bmon-scripts"/.bmon-files; do
        [ -f "$f" ] || continue
        cp "$f" "$SDIR/" && chmod 755 "$SDIR/$(basename "$f")"
    done
    chmod 644 "$SDIR/.bmon-files" 2>/dev/null
fi
NFILES=$(ls "$TMP/new"/*.conf | wc -l | tr -d ' ')

if ! "$MONIT" -c "$RC" -t > "$TMP/check" 2>&1; then
    restore
    if "$MONIT" -c "$RC" -t > "$TMP/check2" 2>&1; then rb='previous configuration restored'; else rb='previous configuration restored, but monit -t still fails'; fi
    { echo "monit -t rejected version $SHORT, $rb:"; cat "$TMP/check"; } > "$TMP/msg"
    echo "$VERSION" > "$STATE_DIR/rejected"
    report rolled_back "$VERSION"
    fail "monit -t rejected version $SHORT: $rb"
fi

echo "$VERSION" > "$STATE_DIR/version"
rm -f "$STATE_DIR/rejected"
if [ "$(httpd_conf "$CONF")" != "$OLD_HTTPD" ]; then ACTION=restart; else ACTION=reload; fi
{ echo "version $SHORT installed ($NFILES checks, run: $MODE), Monit $ACTION."; grep -v 'syntax OK' "$TMP/check"; } > "$TMP/msg"
report installed "$VERSION"
[ "$(cat "$TMP/report.code" 2>/dev/null)" = 200 ] || echo "bmon-update: warning, cannot report to Bmon-web ($(cat "$TMP/report.code" 2>/dev/null))"

if [ $ACTION = reload ]; then
    "$MONIT" -c "$RC" reload >/dev/null 2>&1
else
    # httpd settings changed: a reload keeps the old ones, Monit must restart.
    # Detached, since Monit kills this program when it stops.
    if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet monit 2>/dev/null; then
        if command -v systemd-run >/dev/null 2>&1; then
            systemd-run --quiet --no-block --collect systemctl restart monit
        else
            nohup sh -c 'sleep 2; systemctl restart monit' </dev/null >/dev/null 2>&1 &
        fi
    else
        S=''; command -v setsid >/dev/null 2>&1 && S=setsid
        $S nohup sh -c "sleep 2; '$MONIT' -c '$RC' quit; sleep 5; '$MONIT' -c '$RC'" </dev/null >/dev/null 2>&1 &
    fi
fi
echo "installed version $SHORT ($NFILES checks), Monit $ACTION"
exit 0
