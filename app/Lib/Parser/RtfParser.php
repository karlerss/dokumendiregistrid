<?php

namespace App\Lib\Parser;

use App\Models\File;
use Illuminate\Support\Facades\Log;
use RtfHtmlPhp\Document;
use RtfHtmlPhp\Html\HtmlFormatter;
use Soundasleep\Html2Text;

class RtfParser extends BaseParser
{
    /** Font charsets rtf-to-html can map; any other code makes it raise an error. */
    private const KNOWN_CHARSETS = [0, 1, 2, 3, 77, 128, 129, 130, 134, 136, 161, 162, 163, 177, 178, 179, 180, 181, 186, 204, 222, 238, 254, 255];

    public function parse(?int $parentId = null): array
    {
        $html = null;
        try {
            $html = self::toHtml(file_get_contents($this->path));
        } catch (\Throwable $e) {
            // One unreadable attachment must not stop a whole import: keep
            // the file, without extracted text.
            Log::warning('rtf: could not convert ' . basename($this->path) . ': ' . $e->getMessage());
        }

        $file = File::query()->create([
            'location' => File::store($this->path),
            'name' => basename($this->path),
            'contents' => $html !== null ? Html2Text::convert($html, ['ignore_errors' => true]) : '',
            'html' => $html,
            'parent_id' => $parentId,
            'parsed_with' => self::class,
        ]);
        return [$file];
    }

    public static function toHtml(string $rtf): string
    {
        // Unknown font charsets (e.g. \fcharset88 from some editors) fall back to the default.
        $rtf = preg_replace_callback('/\\\\fcharset(\d+)/', fn($m) => in_array((int)$m[1], self::KNOWN_CHARSETS, true) ? $m[0] : '\\fcharset0', $rtf);
        return (new HtmlFormatter())->Format(new Document($rtf));
    }
}
