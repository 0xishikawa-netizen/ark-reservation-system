<?php
/**
 * ARK 予約システム — お名前.com レンタルサーバー能力プローブ (read-only)
 * -----------------------------------------------------------------------------
 * 使い方:
 *   1. このファイルだけをサーバーの一時ディレクトリ（Web 公開領域の外が望ましい）へ置く
 *   2. `php tools/server-probe.php > probe-output.txt`  もしくは
 *      Web 経由でしか PHP を実行できない場合は一時的に公開領域へ置いてブラウザで開き、
 *      結果を控えたら **必ず削除する**
 *   3. 出力を docs/PHASE0_REPORT.md へ貼る
 *
 * 何も書き込まない・何も送信しない。環境情報を表示するだけ。
 */

function line(string $k, $v): void { printf("%-26s : %s\n", $k, is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v); }
function has(string $cmd): string {
    $out = @shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null');
    return $out ? trim($out) : '(not found / shell_exec disabled)';
}

echo "=== ARK server probe ===\n";
echo 'generated_at              : ' . date('c') . "\n\n";

echo "--- PHP ---\n";
line('php_version', PHP_VERSION);
line('php_sapi', PHP_SAPI);
line('php_binary', PHP_BINARY ?: '(unknown)');
line('memory_limit', ini_get('memory_limit'));
line('max_execution_time', ini_get('max_execution_time'));
line('upload_max_filesize', ini_get('upload_max_filesize'));
line('post_max_size', ini_get('post_max_size'));
line('date.timezone', ini_get('date.timezone') ?: '(unset)');
line('open_basedir', ini_get('open_basedir') ?: '(none)');
line('disable_functions', ini_get('disable_functions') ?: '(none)');
line('allow_url_fopen', (bool) ini_get('allow_url_fopen'));

echo "\n--- Required PHP extensions (Laravel 13) ---\n";
$required = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json',
            'bcmath', 'fileinfo', 'curl', 'intl', 'gd', 'zip', 'filter', 'session'];
foreach ($required as $ext) {
    line("ext:$ext", extension_loaded($ext));
}
line('ext:redis (optional)', extension_loaded('redis'));
line('ext:pcntl (queue optional)', extension_loaded('pcntl'));

echo "\n--- Shell / build toolchain (best effort) ---\n";
$shellExec = function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
line('shell_exec usable', $shellExec);
if ($shellExec) {
    foreach (['bash', 'sh', 'git', 'composer', 'php', 'node', 'npm', 'mysql', 'mysqldump',
              'supervisord', 'supervisorctl', 'crontab', 'rsync', 'unzip', 'gzip'] as $c) {
        line("which:$c", has($c));
    }
    line('composer --version', trim((string) @shell_exec('composer --version 2>&1')) ?: '(n/a)');
    line('node --version', trim((string) @shell_exec('node --version 2>&1')) ?: '(n/a)');
    line('git --version', trim((string) @shell_exec('git --version 2>&1')) ?: '(n/a)');
    line('mysql --version', trim((string) @shell_exec('mysql --version 2>&1')) ?: '(n/a)');
    $uname = trim((string) @shell_exec('uname -a 2>&1'));
    line('uname', $uname ?: '(n/a)');
}

echo "\n--- Web server / rewrite ---\n";
line('SERVER_SOFTWARE', $_SERVER['SERVER_SOFTWARE'] ?? '(cli)');
line('mod_rewrite (apache_get_modules)',
    function_exists('apache_get_modules') ? (in_array('mod_rewrite', apache_get_modules(), true) ? 'yes' : 'no') : '(unknown - check .htaccess test)');
line('.htaccess test hint', 'AllowOverride 有効かは実際に RewriteRule を置いて確認');

echo "\n--- MySQL (接続は行わない。情報のみ) ---\n";
line('pdo_mysql client', extension_loaded('pdo_mysql') ? 'available' : 'MISSING');
echo "  ※ バージョンは phpMyAdmin もしくは `SELECT VERSION();` で別途確認し PHASE0_REPORT.md へ記録\n";

echo "\n--- Cron ---\n";
if ($shellExec) {
    $cron = @shell_exec('crontab -l 2>&1');
    line('crontab -l', $cron === null ? '(disabled)' : (trim($cron) === '' ? '(empty, but usable)' : 'has entries'));
}
echo "  ※ コントロールパネルの cron 設定 UI の有無・最小間隔（1 分可否）を PHASE0_REPORT.md へ記録\n";

echo "\n--- 判定の目安 ---\n";
echo "  A. PHP 8.3 + 必須拡張すべて yes / Composer 実行可 / cron 1 分可 / mysqldump 可\n";
echo "     → 共有ホスティング継続を検討可（ただし queue:work 常駐やビルドは要工夫）\n";
echo "  B. Composer 不可 or SSH 不可 or 必須拡張欠落 or cron 5 分以上\n";
echo "     → VPS 移行を推奨（結論を PHASE0_REPORT.md に根拠付きで記載）\n";
echo "\n=== end ===\n";
