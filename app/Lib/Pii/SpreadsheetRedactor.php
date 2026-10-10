<?php

namespace App\Lib\Pii;

/**
 * Replaces planned strings inside a spreadsheet's cells before it is
 * converted to PDF. Redacting only the PDF is not enough for spreadsheets:
 * a cell narrower than its text is clipped when printed ("Mari Maasikas"
 * shows as "Mari Maas"), and numbers such as personal codes may print as
 * 3,94E+10, so a search in the PDF misses what is still readable.
 *
 * Works on the XML of .xlsx/.xlsm (shared strings, inline and formula
 * strings, numeric cells, comments) and .ods (cell paragraphs and value
 * attributes). A cell whose text changes is rewritten as plain text; its
 * rich formatting inside the cell is dropped, formulas become values.
 */
class SpreadsheetRedactor
{
    public const FORMATS = ['xlsx', 'xlsm', 'ods'];

    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    private const TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    private const TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

    /**
     * Rewrite the file in place.
     *
     * @return int number of cells/strings changed
     * @throws \RuntimeException when the file cannot be read, or a planned
     *         string survives
     */
    public function redact(string $path, string $format, array $replacements): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException("cannot open $format as a zip");
        }

        $changed = 0;
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $parts[] = $zip->getNameIndex($i);
        }
        foreach ($parts as $name) {
            $handler = $this->handlerFor($format, $name);
            if (!$handler) {
                continue;
            }
            $xml = $zip->getFromName($name);
            $dom = new \DOMDocument();
            if (!is_string($xml) || !@$dom->loadXML($xml, LIBXML_NONET)) {
                continue;
            }
            $dom->encoding = 'UTF-8'; // keep letters as letters, not &#xFC; references
            $n = $this->$handler($dom, $replacements);
            if ($n > 0) {
                $zip->addFromString($name, $dom->saveXML());
                $changed += $n;
            }
        }
        $zip->close();

        $left = $this->leftovers($path, $replacements);
        if ($left) {
            throw new \RuntimeException('Planned strings still present in the spreadsheet: ' . implode(', ', array_map(fn($s) => mb_substr($s, 0, 3) . '…', $left)));
        }
        return $changed;
    }

    private function handlerFor(string $format, string $name): ?string
    {
        if (in_array($name, ['docProps/core.xml', 'docProps/app.xml', 'docProps/custom.xml', 'meta.xml'], true)) {
            return 'textNodes'; // author, last modified by, custom properties
        }
        if ($format === 'ods') {
            return in_array($name, ['content.xml', 'styles.xml'], true) ? 'odsPart' : null;
        }
        return match (true) {
            $name === 'xl/sharedStrings.xml' => 'xlsxSharedStrings',
            (bool)preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name) => 'xlsxSheet',
            (bool)preg_match('#^xl/comments\d*\.xml$#', $name) => 'xlsxComments',
            (bool)preg_match('#^xl/threadedComments/[^/]+\.xml$#', $name) => 'xlsxThreaded',
            default => null,
        };
    }

    // ------------------------------------------------------------- xlsx

    private function xlsxSharedStrings(\DOMDocument $dom, array $r): int
    {
        $n = 0;
        foreach (iterator_to_array($dom->getElementsByTagNameNS(self::MAIN, 'si')) as $si) {
            $n += $this->rewriteRich($dom, $si, $r);
        }
        return $n;
    }

    private function xlsxComments(\DOMDocument $dom, array $r): int
    {
        $n = 0;
        foreach (iterator_to_array($dom->getElementsByTagNameNS(self::MAIN, 'text')) as $text) {
            $n += $this->rewriteRich($dom, $text, $r);
        }
        return $n;
    }

    private function xlsxThreaded(\DOMDocument $dom, array $r): int
    {
        $n = 0;
        foreach (iterator_to_array($dom->getElementsByTagName('text')) as $text) {
            $new = RedactionPlanner::replaceAll($text->textContent, $r);
            if ($new !== $text->textContent) {
                $text->textContent = $new;
                $n++;
            }
        }
        return $n;
    }

    private function xlsxSheet(\DOMDocument $dom, array $r): int
    {
        $n = 0;
        foreach (iterator_to_array($dom->getElementsByTagNameNS(self::MAIN, 'c')) as $c) {
            /** @var \DOMElement $c */
            $type = $c->getAttribute('t');
            if ($type === 'inlineStr') {
                foreach (iterator_to_array($c->getElementsByTagNameNS(self::MAIN, 'is')) as $is) {
                    $n += $this->rewriteRich($dom, $is, $r);
                }
                continue;
            }
            if ($type === 's') {
                continue; // shared string: handled in sharedStrings.xml
            }
            // Numbers and formula results: compare the stored value; a
            // personal code may be stored as "39412110212" or "39412110212.0".
            $v = $c->getElementsByTagNameNS(self::MAIN, 'v')->item(0);
            if (!$v) {
                continue;
            }
            $plain = preg_replace('/\.0+$/', '', $v->textContent);
            $new = RedactionPlanner::replaceAll($plain, $r);
            if ($new !== $plain) {
                $this->makeInlineString($dom, $c, $new);
                $n++;
            }
        }
        // Print headers and footers (&C, &L codes around plain text).
        foreach (['oddHeader', 'oddFooter', 'evenHeader', 'evenFooter', 'firstHeader', 'firstFooter'] as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagNameNS(self::MAIN, $tag)) as $hf) {
                $new = RedactionPlanner::replaceAll($hf->textContent, $r);
                if ($new !== $hf->textContent) {
                    $hf->textContent = $new;
                    $n++;
                }
            }
        }
        return $n;
    }

    private function textNodes(\DOMDocument $dom, array $r): int
    {
        $n = 0;
        foreach (iterator_to_array((new \DOMXPath($dom))->query('//text()')) as $text) {
            $new = RedactionPlanner::replaceAll($text->nodeValue, $r);
            if ($new !== $text->nodeValue) {
                $text->nodeValue = $new;
                $n++;
            }
        }
        return $n;
    }

    /**
     * Rewrite an <si>/<is>/<text> whose joined text (rich-text runs read back
     * to back) changes, as one plain <t>.
     */
    private function rewriteRich(\DOMDocument $dom, \DOMElement $el, array $r): int
    {
        $old = '';
        foreach ($el->getElementsByTagNameNS(self::MAIN, 't') as $t) {
            // Skip phonetic runs (<rPh>), which are not displayed.
            if ($t->parentNode?->localName !== 'rPh') {
                $old .= $t->textContent;
            }
        }
        $new = RedactionPlanner::replaceAll($old, $r);
        if ($new === $old) {
            return 0;
        }
        while ($el->firstChild) {
            $el->removeChild($el->firstChild);
        }
        $t = $dom->createElementNS(self::MAIN, 't');
        $t->setAttribute('xml:space', 'preserve');
        $t->appendChild($dom->createTextNode($new));
        $el->appendChild($t);
        return 1;
    }

    private function makeInlineString(\DOMDocument $dom, \DOMElement $c, string $text): void
    {
        while ($c->firstChild) {
            $c->removeChild($c->firstChild); // <f> formula and <v> value
        }
        $c->setAttribute('t', 'inlineStr');
        $is = $dom->createElementNS(self::MAIN, 'is');
        $t = $dom->createElementNS(self::MAIN, 't');
        $t->setAttribute('xml:space', 'preserve');
        $t->appendChild($dom->createTextNode($text));
        $is->appendChild($t);
        $c->appendChild($is);
    }

    // -------------------------------------------------------------- ods

    private function odsPart(\DOMDocument $dom, array $r): int
    {
        $n = 0;
        foreach (['p', 'h'] as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagNameNS(self::TEXT, $tag)) as $p) {
                $old = $p->textContent;
                $new = RedactionPlanner::replaceAll($old, $r);
                if ($new !== $old) {
                    while ($p->firstChild) {
                        $p->removeChild($p->firstChild);
                    }
                    $p->appendChild($dom->createTextNode($new));
                    $n++;
                }
            }
        }
        // Stored values behind the displayed text (a personal code as a number).
        foreach (iterator_to_array($dom->getElementsByTagNameNS(self::TABLE, 'table-cell')) as $cell) {
            /** @var \DOMElement $cell */
            foreach (['value', 'string-value'] as $attr) {
                if (!$cell->hasAttributeNS(self::OFFICE, $attr)) {
                    continue;
                }
                $value = $cell->getAttributeNS(self::OFFICE, $attr);
                $plain = preg_replace('/\.0+$/', '', $value);
                if (RedactionPlanner::replaceAll($plain, $r) !== $plain) {
                    $cell->removeAttributeNS(self::OFFICE, $attr);
                    $cell->setAttributeNS(self::OFFICE, 'office:value-type', 'string');
                    $n++;
                }
            }
        }
        return $n;
    }

    // ----------------------------------------------------------- verify

    /** @return string[] */
    private function leftovers(string $path, array $replacements): array
    {
        $text = app(OriginalText::class)->officeTextFromBytes((string)file_get_contents($path)) ?? '';
        $g = new Grounding(['t' => $text]);
        $labels = array_column($replacements, 'replacement');
        $left = [];
        foreach ($replacements as $r) {
            if (!in_array($r['surface'], $labels, true) && $g->occurs($r['surface'])) {
                $left[] = $r['surface'];
            }
        }
        return $left;
    }
}
