<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Excel;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;

final class WorkbookTemplateRegistry
{
    public function sourcePath(): string
    {
        $filename = config('report_export.template_file');
        if ($filename === null) {
            throw new RuntimeException('2026年10月原本テンプレートは必須です。');
        }
        if (! is_string($filename) || basename($filename) !== $filename || ! str_ends_with(strtolower($filename), '.xlsx')) {
            throw new RuntimeException('帳票テンプレートの指定が不正です。');
        }
        $path = resource_path('report_templates/'.$filename);
        $expectedHash = config('report_export.template_sha256');
        if (! is_file($path) || ! is_string($expectedHash) || ! hash_equals($expectedHash, hash_file('sha256', $path))) {
            throw new RuntimeException('帳票テンプレートのhashまたはファイルが一致しません。');
        }

        return $path;
    }

    public function load(): Spreadsheet
    {
        $path = $this->sourcePath();
        $copy = tempnam(sys_get_temp_dir(), 'ark-report-template-');
        if ($copy === false) {
            throw new RuntimeException('帳票テンプレートの作業用コピーを作成できません。');
        }
        try {
            if (! copy($path, $copy)) {
                throw new RuntimeException('帳票テンプレートの作業用コピーを作成できません。');
            }
            $book = IOFactory::createReader('Xlsx')->load($copy);
        } finally {
            unlink($copy);
        }
        if ($book->getSheetNames() !== LegacySixSheetCellMap::SHEETS) {
            throw new RuntimeException('帳票テンプレートの6シート構成が一致しません。');
        }

        return $book;
    }
}
