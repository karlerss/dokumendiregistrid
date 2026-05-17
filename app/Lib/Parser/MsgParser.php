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

        $outDir = storage_path('temp/files_' . now());
        $fs->ensureDirectoryExists($outDir);

        $this->runExtractor($this->path, $outDir);

        if (!is_dir($outDir)) {
            throw new \Exception("Nothing was extracted");
        }
        $dirList = scandir($outDir);
        if (!isset($dirList[2])) {
            throw new \Exception("Nothing in output dir");
        }

        $dir = $dirList[2];

        $messageHtmlPath = "$outDir/$dir/message.html";
        if (file_exists($messageHtmlPath)) {
            $origHtml = file_get_contents($messageHtmlPath);
            $msgHtml = $this->extractBody($origHtml);
            $contents = \Soundasleep\Html2Text::convert($origHtml, ['ignore_errors' => true]);
        } else {
            $msgHtml = '';
            $contents = null;
        }

        $file = \App\Models\File::query()->create([
            'location' => \App\Models\File::store($this->path),
            'name' => basename($this->path),
            'html' => $msgHtml,
            'contents' => $contents,
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

    protected function runExtractor(string $msgPath, string $outDir): void
    {
        $tempFile = escapeshellarg($msgPath);
        $outArg = escapeshellarg($outDir);
        $extractCmd = "python3 -m extract_msg $tempFile --out=$outArg --use-filename --html --save-header";
        shell_exec($extractCmd);
    }

}
