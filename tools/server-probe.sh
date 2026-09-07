#!/usr/bin/env bash
# ARK 予約システム — サーバー能力プローブ (SSH/シェル版, read-only)
# 何も書き込まない・何も送信しない。結果を docs/PHASE0_REPORT.md へ貼る。
set -u

sec() { printf '\n--- %s ---\n' "$1"; }
kv()  { printf '%-24s : %s\n' "$1" "$2"; }
ver() { command -v "$1" >/dev/null 2>&1 && { printf '%-24s : ' "$1"; "$@" 2>&1 | head -1; } || printf '%-24s : (not found)\n' "$1"; }

echo "=== ARK server probe (shell) ==="
kv generated_at "$(date -Iseconds 2>/dev/null || date)"

sec "OS"
uname -a 2>&1 | sed 's/^/  /'
kv "whoami" "$(whoami 2>&1)"
kv "shell" "${SHELL:-unknown}"

sec "toolchain versions"
ver php --version
ver composer --version
ver git --version
ver node --version
ver npm --version
ver mysql --version
ver mysqldump --version
ver rsync --version
ver unzip -v
ver supervisord -v
ver crontab -l

sec "PHP config (if php present)"
if command -v php >/dev/null 2>&1; then
  php -r 'foreach (["memory_limit","max_execution_time","upload_max_filesize","post_max_size","open_basedir","disable_functions"] as $k) printf("  %-22s = %s\n",$k, ini_get($k) ?: "(empty)");'
  echo "  loaded extensions:"
  php -m 2>/dev/null | paste -sd' ' - | fold -s -w 100 | sed 's/^/    /'
  echo "  required check:"
  php -r '$r=["pdo_mysql","mbstring","openssl","tokenizer","xml","ctype","bcmath","fileinfo","curl","intl","gd","zip"];foreach($r as $e)printf("    %-12s %s\n",$e, extension_loaded($e)?"OK":"MISSING");'
fi

sec "cron"
crontab -l 2>&1 | sed 's/^/  /' || true
echo "  ※ コントロールパネルの cron UI・最小間隔(1分可否)を別途確認"

sec "mod_rewrite / .htaccess"
echo "  実際に RewriteRule を置いたテスト用ディレクトリで 302 が返るか確認する"

sec "MySQL version (接続情報がある場合のみ手動で)"
echo "  mysql -h HOST -u USER -p -e 'SELECT VERSION();'   ← 認証情報がある時だけ手で実行"
echo "  ※ このスクリプトは自動接続しない"

echo
echo "=== 判定 ==="
echo "  A: PHP8.3+必須拡張OK / composer実行可 / cron 1分可 / mysqldump可  → 共有継続を検討可"
echo "  B: composer不可 / SSH不可 / 拡張欠落 / cron5分以上            → VPS移行推奨"
echo "=== end ==="
