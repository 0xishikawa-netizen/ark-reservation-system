<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * お試し環境の「HP デザイン案（実装イメージ）」（Task 11-38）。
 * デザイナーのデザインを元に「実際に作るとこうなる」見本ページを案ごとに並べる。
 *
 * 置き場所（config('staging.hp_designs_path')）に置いたものを開発用メニューにボタンとして並べる。
 * - `<案名>.url`：中身の 1 行目の URL（Canva・Figma など）を新しいタブで開く。
 * - `<案名>/`：フォルダの中の index.html、なければ最初の HTML / PDF / 画像を開く。
 * 開発用メニューと同じく local / staging かつ STAGING_DEV_MENU=true のときだけ。
 * ファイルは Laravel 経由で返すので、お試し環境の Basic 認証の内側にある。
 */
final class HpDesignController extends Controller
{
    /** 拡張子ごとの Content-Type（見本ページで使うものだけ）。 */
    private const MIME_TYPES = [
        'html' => 'text/html; charset=UTF-8',
        'htm' => 'text/html; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'json' => 'application/json',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'woff2' => 'font/woff2',
    ];

    /** フォルダの中で最初に開くファイルの拡張子（優先順）。 */
    private const ENTRY_EXTENSIONS = ['html', 'htm', 'pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif'];

    public function __invoke(string $design, ?string $path = null): BinaryFileResponse
    {
        abort_unless(DevMenuController::enabled(), 404);

        $root = realpath(self::basePath());
        $dir = $root === false ? false : realpath($root.DIRECTORY_SEPARATOR.$design);

        abort_if($dir === false || ! is_dir($dir) || ! str_starts_with($dir, $root.DIRECTORY_SEPARATOR), 404);

        $file = $path === null || $path === '' ? self::entryFile($dir) : realpath($dir.DIRECTORY_SEPARATOR.$path);

        // フォルダの外（../ など）は返さない。
        abort_if($file === null || $file === false || ! is_file($file) || ! str_starts_with($file, $dir.DIRECTORY_SEPARATOR), 404);

        $headers = ['X-Robots-Tag' => 'noindex, nofollow'];

        // 中身からの推測だと CSS / JS が text/plain になり、nosniff のせいでブラウザが読み込まないため拡張子で決める。
        $type = self::MIME_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;

        if ($type !== null) {
            $headers['Content-Type'] = $type;
        }

        return response()->file($file, $headers);
    }

    /**
     * 開発用メニューに並べる案の一覧（名前順）。
     *
     * @return list<array{name: string, url: string, external: bool}>
     */
    public static function designs(): array
    {
        $base = self::basePath();

        if (! is_dir($base)) {
            return [];
        }

        $designs = [];

        foreach (File::directories($base) as $dir) {
            $name = basename($dir);

            $entry = str_starts_with($name, '.') ? null : self::entryFile($dir);

            // 入口ファイル名まで含めて開く（HTML 内の相対パスが案のフォルダ基準で解決されるように）。
            if ($entry !== null) {
                $designs[] = [
                    'name' => $name,
                    'url' => '/hp-designs/'.rawurlencode($name).'/'.rawurlencode(basename($entry)),
                    'external' => false,
                ];
            }
        }

        foreach (File::glob($base.DIRECTORY_SEPARATOR.'*.url') as $file) {
            $url = trim((string) Str::of((string) file_get_contents($file))->before("\n"));

            // http(s) 以外（javascript: など）はボタンにしない。
            if (preg_match('#^https?://#i', $url) === 1) {
                $designs[] = ['name' => basename($file, '.url'), 'url' => $url, 'external' => true];
            }
        }

        usort($designs, static fn (array $a, array $b): int => strnatcmp($a['name'], $b['name']));

        return $designs;
    }

    public static function basePath(): string
    {
        return (string) config('staging.hp_designs_path', base_path('hp-designs'));
    }

    private static function entryFile(string $dir): ?string
    {
        $index = $dir.DIRECTORY_SEPARATOR.'index.html';

        if (is_file($index)) {
            return realpath($index) ?: null;
        }

        foreach (self::ENTRY_EXTENSIONS as $extension) {
            $matches = File::glob($dir.DIRECTORY_SEPARATOR.'*.'.$extension);
            sort($matches);

            if ($matches !== []) {
                return realpath($matches[0]) ?: null;
            }
        }

        return null;
    }
}
