<?php

namespace App\Lib\Parser;

use App\Models\File;
use RtfHtmlPhp\Document;
use RtfHtmlPhp\Html\HtmlFormatter;
use Soundasleep\Html2Text;

class RtfParser extends BaseParser
{

    public function parse(?int $parentId = null): array
    {
        $rtf = file_get_contents($this->path);
        $document = new Document($rtf);
        $formatter = new HtmlFormatter();
        $html = $formatter->Format($document);

        $file = File::query()->create([
            'location' => File::store($this->path),
            'name' => basename($this->path),
            'contents' => Html2Text::convert($html, ['ignore_errors' => true]),
            'html' => $html,
            'parent_id' => $parentId,
            'parsed_with' => self::class,
        ]);
        return [$file];
    }
}
