<?php

namespace App\Lib\Parser;

use App\Models\File;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class AsiceParser extends BaseParser
{

    public function parse(?int $parentId = null): array
    {
        $zip = new \ZipArchive();

        $outDir = storage_path('temp/asice_' . Str::random(10));

        if ($zip->open($this->path) === true) {
            $zip->extractTo($outDir);
        } else {
            throw new \Exception("Could not parse ASICE");
        }

        $asiceFile = File::query()->create([
            'location' => File::store($this->path),
            'name' => basename($this->path),
            'contents' => null,
            'parent_id' => $parentId,
            'parsed_with' => self::class,
        ]);
        $result = [$asiceFile];

        $result = [
            ...$result,
            ...(new DirParser($outDir))->parse($asiceFile->id)
        ];

        (new Filesystem())->deleteDirectory($outDir);

        return $result;
    }
}
