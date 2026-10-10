<?php

namespace App\Lib\Pii;

use App\Models\File;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Redacted copies of messages and web pages (.eml, .msg, .html), which have
 * no format of their own we can safely edit in place: MIME encodings, Outlook
 * compound files and arbitrary markup. Instead the readable content (headers
 * and body, the same text the site shows) is rebuilt as sanitised HTML with
 * the plan's strings replaced, verified, and rendered to PDF. Attachments are
 * separate files with their own plan actions and are not part of the copy.
 */
class MessageRenderer
{
    public function __construct(private OriginalText $original)
    {
    }

    public const RENDERABLE = ['eml', 'msg', 'html', 'htm'];

    private const NOTE = 'Redigeeritud koopia. Isikuandmed on eemaldatud; manused on eraldi failidena.';

    // Elements whose content is never rendered (remote loads, code, media).
    private const DROP = ['script', 'style', 'link', 'meta', 'iframe', 'frame', 'object', 'embed', 'form', 'input', 'button', 'svg', 'video', 'audio', 'noscript', 'head', 'title', 'base'];

    public static function renderable(File $file): bool
    {
        return in_array($file->getExtension(), self::RENDERABLE, true);
    }


    /**
     * The redacted copy as a complete HTML document.
     *
     * @param array<int, array{surface: string, replacement: string, kind?: string}> $replacements
     * @throws \RuntimeException when a planned string survives
     */
    public function render(File $file, array $replacements): string
    {
        $headers = [];
        $ext = $file->getExtension();

        if ($ext === 'eml') {
            $headers = $this->original->emlHeaders($this->original->bytes($file));
            $body = $file->html ?: Redactor::htmlFromText($file->contents);
        } elseif ($ext === 'msg') {
            // extract_msg's message.html already carries From/Sent/To/Subject.
            $body = $file->html ?: Redactor::htmlFromText($file->contents);
        } else {
            $body = $file->contents ? Redactor::htmlFromText($file->contents) : $this->original->bytes($file);
        }

        $headerHtml = '';
        foreach ($headers as $label => $value) {
            $value = RedactionPlanner::replaceAll($value, $replacements);
            $headerHtml .= '<tr><th>' . e($label) . '</th><td>' . e($value) . '</td></tr>';
        }

        $bodyHtml = $this->sanitise($body, $replacements);
        if ($this->leftovers(Grounding::stripHtml($bodyHtml) ?? '', $replacements)) {
            // A form split across tags (<b>Mari</b> Maasikas) escapes text-node
            // replacement; fall back to the plain text, replaced as a whole.
            // Start from the sanitised markup so dropped elements stay dropped.
            $bodyHtml = Redactor::htmlFromText(RedactionPlanner::replaceAll(Grounding::stripHtml($bodyHtml) ?? '', $replacements));
        }

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:"DejaVu Sans",sans-serif;font-size:10pt;line-height:1.35}'
            . '.note{color:#555;font-size:8pt;border-bottom:1px solid #ccc;padding-bottom:4pt}'
            . 'table.headers th{text-align:left;padding-right:8pt;vertical-align:top}'
            . 'table{border-collapse:collapse} td,th{vertical-align:top}'
            . '</style></head><body>'
            . '<p class="note">' . e(self::NOTE) . ' (' . e($file->name) . ')</p>'
            . ($headerHtml ? '<table class="headers">' . $headerHtml . '</table><hr>' : '')
            . $bodyHtml
            . '</body></html>';

        $left = $this->leftovers(Grounding::stripHtml($html) ?? '', $replacements);
        if ($left) {
            throw new \RuntimeException('Removed strings still present in the rendered copy: ' . implode(', ', array_map(fn($s) => mb_substr($s, 0, 3) . '…', $left)));
        }

        return $html;
    }

    /** Render an HTML document to PDF, without fetching anything remote. */
    public function toPdf(string $html, string $outputPath): void
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot([sys_get_temp_dir()]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        file_put_contents($outputPath, $dompdf->output());
    }

    // ------------------------------------------------------------- helpers

    /**
     * Keep text and plain structure only: drop active/remote elements and
     * every attribute (hrefs and mailto: carry addresses, src loads remote
     * images), turn images into a marker, then replace inside text nodes.
     */
    private function sanitise(?string $html, array $replacements): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $dom->getElementById('__root');
        if (!$root) {
            return Redactor::htmlFromText(RedactionPlanner::replaceAll(Grounding::stripHtml($html), $replacements));
        }

        $xpath = new \DOMXPath($dom);
        foreach (self::DROP as $tag) {
            foreach (iterator_to_array($xpath->query('.//' . $tag, $root)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        foreach (iterator_to_array($xpath->query('.//img', $root)) as $img) {
            $img->parentNode?->replaceChild($dom->createTextNode('[pilt]'), $img);
        }
        foreach (iterator_to_array($xpath->query('.//*', $root)) as $el) {
            /** @var \DOMElement $el */
            foreach (iterator_to_array($el->attributes) as $attr) {
                if (!in_array($attr->name, ['colspan', 'rowspan'], true)) {
                    $el->removeAttribute($attr->name);
                }
            }
        }
        foreach (iterator_to_array($xpath->query('.//text()', $root)) as $text) {
            $text->nodeValue = RedactionPlanner::replaceAll($text->nodeValue, $replacements);
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }

    /** @return string[] planned surfaces still found in $text */
    private function leftovers(string $text, array $replacements): array
    {
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
