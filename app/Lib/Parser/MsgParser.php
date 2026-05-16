<?php

namespace App\Lib\Parser;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class MsgParser extends BaseParser
{

    public function parse(?int $parentId = null): array
    {
        $fs = new Filesystem();
        $tempFile = escapeshellarg($this->path);

        $outDir = storage_path('temp/files_' . now());
        $fs->ensureDirectoryExists($outDir);

        $outArg = escapeshellarg($outDir);
        $extractCmd = "python3 -m extract_msg $tempFile --out=$outArg --use-filename --html --save-header";
        $res = shell_exec($extractCmd);


        if (!is_dir($outDir)) {
            throw new \Exception("Nothing was extracted");
        }
        $dirList = scandir($outDir);
        if (!isset($dirList[2])) {
            throw new \Exception("Nothing in output dir");
        }

        $dir = $dirList[2];

        $origHtml = file_get_contents("$outDir/$dir/message.html");

        $msgHtml = $this->extractBody($origHtml);

        $file = \App\Models\File::query()->create([
            'location' => \App\Models\File::store($this->path),
            'name' => basename($this->path),
            'html' => $msgHtml,
            'contents' => \Soundasleep\Html2Text::convert($origHtml, ['ignore_errors' => true]),
            'parent_id' => $parentId,
            'parsed_with' => self::class,
        ]);


        $result = [
            $file,
            ...(new DirParser("$outDir/$dir"))->parse($file->id),
        ];

        $fs->deleteDirectory($outDir);

        return $result;
    }

}
