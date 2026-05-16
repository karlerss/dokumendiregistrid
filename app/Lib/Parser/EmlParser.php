<?php

namespace App\Lib\Parser;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use PhpMimeMailParser\Parser;

class EmlParser extends BaseParser
{

    public function parse(?int $parentId = null): array
    {
        $p = new Parser();
        $p->setPath($this->path);

        if (!$p->getMessageBody('html')) {
            $body = nl2br($p->getMessageBody('text'));
        } else {
            $body = $p->getMessageBody('html');
        }

        $file = \App\Models\File::query()->create([
            'location' => \App\Models\File::store($this->path),
            'name' => basename($this->path),
            'contents' => \Soundasleep\Html2Text::convert($body, ['ignore_errors' => true]),
            'html' => $this->extractBody($p->getMessageBody('html'), 'utf-8'),
            'parent_id' => $parentId,
            'parsed_with' => self::class,
        ]);

        $dir = storage_path('temp/attachments_' . Str::random(10));
        (new Filesystem())->ensureDirectoryExists($dir);

        $p->saveAttachments($dir);

        $result = [
            $file,
            ...(new DirParser($dir))->parse($file->id)
        ];

        return $result;
    }
}
