<?php

namespace App\Lib\Parser;

use App\Models\File;

class FileParser extends BaseParser
{

    public function parse(?int $parentId = null): array
    {
        $file = File::query()->create([
            'location' => File::store($this->path),
            'name' => basename($this->path),
            'contents' => null,
            'parent_id' => $parentId,
            'parsed_with' => self::class,
        ]);
        return [$file];
    }
}
