<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Excel;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use ZipArchive;

/** 原本ZIPの他partを再生成せず、既存セルの値ノードだけを更新する。 */
final class CanonicalTemplateXlsxWriter
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function __construct(private readonly WorkbookTemplateRegistry $templates) {}

    /** @param array<string,array<string,array{value:string|int|float|null,kind:string}>> $cells */
    public function save(array $cells, string $destination): void
    {
        $source = $this->templates->sourcePath();
        $sourceStat = stat($source);
        $destinationStat = is_file($destination) ? stat($destination) : false;
        if (is_link($destination) || realpath($destination) === realpath($source)
            || ($destinationStat !== false && $sourceStat !== false
                && $destinationStat['dev'] === $sourceStat['dev'] && $destinationStat['ino'] === $sourceStat['ino'])) {
            throw new RuntimeException('原本テンプレートを上書きできません。');
        }
        if (! copy($source, $destination)) {
            throw new RuntimeException('帳票の作業用コピーを作成できません。');
        }

        $zip = new ZipArchive;
        if ($zip->open($destination) !== true) {
            throw new RuntimeException('帳票の作業用コピーを開けません。');
        }
        $reference = null;
        try {
            foreach (LegacySixSheetCellMap::SHEETS as $index => $name) {
                if (($cells[$name] ?? []) === []) {
                    continue;
                }
                $part = 'xl/worksheets/sheet'.($index + 1).'.xml';
                $xml = $zip->getFromName($part);
                if ($xml === false) {
                    throw new RuntimeException('原本のworksheetが見つかりません: '.$name);
                }
                $dom = $this->parse($xml);
                $xpath = new DOMXPath($dom);
                $xpath->registerNamespace('s', self::MAIN_NS);
                $this->expandOrphanedSharedFormulas($xpath, $cells[$name], $name, $reference);
                $existing = [];
                $nodes = $xpath->query('//s:sheetData/s:row/s:c');
                if ($nodes === false) {
                    throw new RuntimeException('原本のセル一覧を解析できません: '.$name);
                }
                foreach ($nodes as $node) {
                    if ($node instanceof DOMElement) {
                        $existing[$node->getAttribute('r')] = $node;
                    }
                }
                foreach ($cells[$name] ?? [] as $coordinate => $entry) {
                    if (! isset($existing[$coordinate])) {
                        throw new RuntimeException('原本に書込対象セルがありません: '.$name.'!'.$coordinate);
                    }
                    $this->setValue($dom, $existing[$coordinate], $entry['value'], $entry['kind']);
                }
                if (! $zip->addFromString($part, $dom->saveXML())) {
                    throw new RuntimeException('帳票セルを保存できません: '.$name);
                }
            }
            $this->requireRecalculation($zip);
        } finally {
            $zip->close();
            $reference?->disconnectWorksheets();
        }
    }

    /** @param array<string,array{value:string|int|float|null,kind:string}> $mapped */
    private function expandOrphanedSharedFormulas(DOMXPath $xpath, array $mapped, string $sheetName, ?Spreadsheet &$reference): void
    {
        $orphaned = [];
        $formulaCells = $xpath->query('//s:sheetData/s:row/s:c[s:f[@t="shared"]]');
        if ($formulaCells === false) {
            throw new RuntimeException('原本の共有数式を解析できません。');
        }
        foreach ($formulaCells as $cell) {
            if (! $cell instanceof DOMElement || ! isset($mapped[$cell->getAttribute('r')])) {
                continue;
            }
            $formula = $cell->getElementsByTagNameNS(self::MAIN_NS, 'f')->item(0);
            if ($formula instanceof DOMElement && trim($formula->textContent) !== '') {
                $orphaned[$formula->getAttribute('si')] = true;
            }
        }
        if ($orphaned === []) {
            return;
        }
        $reference ??= $this->templates->load();
        foreach ($formulaCells as $cell) {
            if (! $cell instanceof DOMElement || isset($mapped[$cell->getAttribute('r')])) {
                continue;
            }
            $formula = $cell->getElementsByTagNameNS(self::MAIN_NS, 'f')->item(0);
            if (! $formula instanceof DOMElement || ! isset($orphaned[$formula->getAttribute('si')])) {
                continue;
            }
            $coordinate = $cell->getAttribute('r');
            $expanded = $reference->getSheetByName($sheetName)?->getCell($coordinate)->getValue();
            if (! is_string($expanded) || ! str_starts_with($expanded, '=')) {
                throw new RuntimeException('共有数式を維持できません: '.$sheetName.'!'.$coordinate);
            }
            while ($formula->attributes->length > 0) {
                $formula->removeAttributeNode($formula->attributes->item(0));
            }
            $formula->textContent = substr($expanded, 1);
        }
    }

    private function parse(string $xml): DOMDocument
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        if (! $dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('原本のXMLを解析できません。');
        }

        return $dom;
    }

    private function setValue(DOMDocument $dom, DOMElement $cell, string|int|float|null $value, string $kind): void
    {
        foreach (iterator_to_array($cell->childNodes) as $child) {
            if ($child->namespaceURI === self::MAIN_NS && in_array($child->localName, ['f', 'v', 'is'], true)) {
                $cell->removeChild($child);
            }
        }
        $cell->removeAttribute('t');
        if ($value === null) {
            return;
        }
        if ($kind === 'text') {
            $cell->setAttribute('t', 'inlineStr');
            $inline = $dom->createElementNS(self::MAIN_NS, 'is');
            $text = $dom->createElementNS(self::MAIN_NS, 't');
            $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $text->appendChild($dom->createTextNode((string) $value));
            $inline->appendChild($text);
            $cell->appendChild($inline);

            return;
        }
        $cell->setAttribute('t', 'n');
        $number = $dom->createElementNS(self::MAIN_NS, 'v');
        $number->appendChild($dom->createTextNode((string) $value));
        $cell->appendChild($number);
    }

    private function requireRecalculation(ZipArchive $zip): void
    {
        $xml = $zip->getFromName('xl/workbook.xml');
        if ($xml === false) {
            throw new RuntimeException('原本のworkbook.xmlが見つかりません。');
        }
        $dom = $this->parse($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('s', self::MAIN_NS);
        $calc = $xpath->query('/s:workbook/s:calcPr')->item(0);
        if (! $calc instanceof DOMElement) {
            throw new RuntimeException('原本に再計算設定がありません。');
        }
        $calc->setAttribute('calcMode', 'auto');
        $calc->setAttribute('fullCalcOnLoad', '1');
        $calc->setAttribute('forceFullCalc', '1');
        if (! $zip->addFromString('xl/workbook.xml', $dom->saveXML())) {
            throw new RuntimeException('帳票の再計算設定を保存できません。');
        }
    }
}
