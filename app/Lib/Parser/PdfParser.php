<?php

namespace App\Lib\Parser;

use App\Models\File;

class PdfParser extends BaseParser
{

    public function parse(?int $parentId = null): array
    {
        $file = File::query()->create([
            'location' => File::store($this->path),
            'name' => basename($this->path),
            'contents' => $this->getTextWithPdfbox($this->path),
            'html' => $this->extractBody($this->getHtmlWithPdfbox($this->path)),
            'parent_id' => $parentId,
            'parsed_with' => self::class,
        ]);

        return [$file];
    }

    public function getTextWithPdfbox(string $path)
    {
        [$path, $binary, $redirectStdErr] = $this->getCommandParts($path);

        $outFile = storage_path('temp/pdfbox_' . now()->timestamp . '.txt');
        $outFileArg = escapeshellarg($outFile);

        $res = shell_exec("LANG=C.UTF-8 java -jar $binary export:text -i=$path$redirectStdErr -o=$outFileArg");

        $contents = file_get_contents($outFile);

        unlink($outFile);

        return $contents;
    }

    public function getHtmlWithPdfbox(string $path)
    {
        [$path, $binary, $redirectStdErr] = $this->getCommandParts($path);

        $out = shell_exec("LANG=C.UTF-8 java -jar $binary export:text -html -console -i=$path$redirectStdErr");

        return $this->clean($out);
    }

    private function clean(string $text): string
    {
        $text = str_replace('The encoding parameter is ignored when writing html output.', '', $text);
        $text = str_replace('The encoding parameter is ignored when writing to the console.', '', $text);
        return $text;
    }

    /**
     * @param string $path
     * @return array
     */
    private function getCommandParts(string $path): array
    {
        $path = escapeshellarg($path);
        $binary = __DIR__ . '/bin/pdfbox-app-3.0.2.jar';

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $redirectStdErr = '';
        } else {
            $redirectStdErr = ' 2> /dev/null';
        }
        return [$path, $binary, $redirectStdErr];
    }
}
