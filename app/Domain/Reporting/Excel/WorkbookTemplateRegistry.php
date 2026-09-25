<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Excel;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;

final class WorkbookTemplateRegistry
{
    public function load(): Spreadsheet
    {
        $filename = config('report_export.template_file');
        if ($filename === null) {
            $book = new Spreadsheet;
            $book->getActiveSheet()->setTitle(ArkSixSheetCellMap::SHEETS[0]);
            foreach (array_slice(ArkSixSheetCellMap::SHEETS, 1) as $name) {
                $book->createSheet()->setTitle($name);
            }

            return $book;
        }
        if (! is_string($filename) || basename($filename) !== $filename || ! str_ends_with(strtolower($filename), '.xlsx')) {
            throw new RuntimeException('帳票テンプレートの指定が不正です。');
        }
        $path = resource_path('report_templates/'.$filename);
        $expectedHash = config('report_export.template_sha256');
        if (! is_file($path) || ! is_string($expectedHash) || ! hash_equals($expectedHash, hash_file('sha256', $path))) {
            throw new RuntimeException('帳票テンプレートのhashまたはファイルが一致しません。');
        }
        $book = IOFactory::load($path);
        if ($book->getSheetNames() !== LegacySixSheetCellMap::SHEETS) {
            throw new RuntimeException('帳票テンプレートの6シート構成が一致しません。');
        }

        return $book;
    }
}
