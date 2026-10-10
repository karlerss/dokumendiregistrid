<?php

namespace App\Lib\Pii;

use App\Models\File;
use Illuminate\Support\Facades\Storage;
use PhpMimeMailParser\Parser as MailParser;

/**
 * Text in a file's original that the stored fields do not carry, read so the
 * redaction planner (and decision checks) can tell a file is touched:
 *
 *  - .eml: the headers (EmlParser stores only the body);
 *  - .html/.htm: the page text (stored without text);
 *  - Office Open XML (.docx/.xlsx/.pptx) and ODF (.odt/.ods/.odp): every text
 *    part, including headers, footers, foot/endnotes, comments, text boxes,
 *    shared strings, slides and document properties (the parser's
 *    `soffice --cat` gives the body only; spreadsheets get no text at all).
 */
class OriginalText
{
    public const OFFICE = ['docx', 'docm', 'xlsx', 'xlsm', 'pptx', 'odt', 'ods', 'odp'];

    // Closing tags that end a paragraph, cell or shared string: become a
    // newline. Every other tag disappears without a space, so a name split
    // across formatting runs (<w:t>Mari</w:t></w:r><w:r><w:t> Maasikas) reads
    // back whole.
    private const BREAKS = '#</(?:w:p|a:p|si|c|text:p|text:h|table:table-cell|dc:[a-z]+|cp:[a-z]+|meta:[a-z-]+)>|<(?:w:tab|w:br|w:cr|a:br|text:tab|text:line-break|text:s)\b[^>]*/>#u';

    public static function applies(File $file): bool
    {
        return in_array($file->getExtension(), ['eml', 'html', 'htm', ...self::OFFICE], true);
    }

    /** Null when there is nothing extra or the original cannot be read. */
    public function extra(File $file): ?string
    {
        try {
            return match (true) {
                $file->getExtension() === 'eml' => implode("\n", array_filter($this->emlHeaders($this->bytes($file)))) ?: null,
                in_array($file->getExtension(), ['html', 'htm'], true) => $file->contents ? null : Grounding::stripHtml($this->bytes($file)),
                in_array($file->getExtension(), self::OFFICE, true) => $this->officeTextFromBytes($this->bytes($file)),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, string> label => decoded header value */
    public function emlHeaders(string $raw): array
    {
        $parser = new MailParser();
        $parser->setText($raw);
        $headers = [];
        foreach (['Saatja' => 'from', 'Saaja' => 'to', 'Koopia' => 'cc', 'Kuupäev' => 'date', 'Teema' => 'subject'] as $label => $name) {
            $value = $parser->getHeader($name);
            if (is_string($value) && trim($value) !== '') {
                $headers[$label] = trim($value);
            }
        }
        return $headers;
    }

    /** Original bytes, wherever they are now (public, or private after withholding). */
    public function bytes(File $file): string
    {
        return $file->original_private_location
            ? (string)Storage::disk(config('pii.private_disk'))->get($file->original_private_location)
            : (string)Storage::disk(config('pii.public_disk'))->get($file->location);
    }

    public function officeTextFromBytes(string $bytes): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'office');
        file_put_contents($tmp, $bytes);
        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                return null;
            }
            $parts = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($this->isTextPart($name)) {
                    $xml = $zip->getFromIndex($i);
                    if (is_string($xml) && $xml !== '') {
                        $parts[] = $this->xmlText($xml);
                    }
                }
            }
            $zip->close();
            $text = trim(implode("\n", array_filter($parts)));
            return $text === '' ? null : $text;
        } finally {
            @unlink($tmp);
        }
    }

    private function isTextPart(string $name): bool
    {
        return (bool)preg_match('#^(?:'
            . 'word/(?:document|header\d*|footer\d*|footnotes|endnotes|comments\w*)\.xml'
            . '|xl/(?:sharedStrings|comments\d*|worksheets/sheet\d+)\.xml|xl/threadedComments/[^/]+\.xml'
            . '|ppt/(?:slides/slide\d+|notesSlides/notesSlide\d+|comments/[^/]+)\.xml'
            . '|docProps/(?:core|app|custom)\.xml'
            . '|content\.xml|styles\.xml|meta\.xml'
            . ')$#', $name);
    }

    private function xmlText(string $xml): string
    {
        $text = preg_replace(self::BREAKS, "\n", $xml);
        $text = preg_replace('/<[^>]*>/u', '', $text);
        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
