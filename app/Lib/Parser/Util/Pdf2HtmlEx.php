<?php

namespace App\Lib\Parser\Util;

use Spatie\TemporaryDirectory\TemporaryDirectory;

class Pdf2HtmlEx
{
    public function getHtmlDirectory(string $localPdfPath): TemporaryDirectory
    {
        $tempDir = TemporaryDirectory::make(storage_path('temp/pdf2htmlex'));

        // copy pdf to the temp directory using php
        copy($localPdfPath, $tempDir->path('input.pdf'));

        if(!is_readable($tempDir->path('input.pdf'))) {
            throw new \Exception('Could not read input.pdf');
        }

        $dockerCmd = escapeshellcmd(config('services.docker.binary'));
        $dockerContainerName = escapeshellarg("pdf2htmlex/pdf2htmlex:0.18.8.rc2-master-20200820-alpine-3.12.0-x86_64");
        $fileDirectory = $tempDir->path();
        $fileName = 'input.pdf';
        $outFile = 'output_raw.html';
        $volume = escapeshellarg("$fileDirectory:/pdf");
        // run the docker container --heps 5 --font-size-multiplier 8 --stretch-narrow-glyph 0 --decompose-ligature 1 --squeeze-wide-glyph 0
        $command = "$dockerCmd run --rm -v $volume $dockerContainerName --optimize-text 1 --heps 10 --font-size-multiplier 8 --stretch-narrow-glyph 0 --decompose-ligature 1 --squeeze-wide-glyph 0 $fileName $outFile";

        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);

        info($command);
        info($returnVar);
        info("pdf2htmlex output:", $output);

        $html = file_get_contents($tempDir->path('output_raw.html'));
        // insert this into HTML:
        $pageStyle = <<<PAGE
<style>
@page {
    size: 210mm 297mm;
}
</style>
<title>dokumendiregistrid.karlerss.com</title>
PAGE;

        $html = str_replace('<head>', '<head>' . $pageStyle, $html);

        // replace hidden fonts:
        $hiddenFontStr = 'font-family:sans-serif;visibility:hidden;';
        $existingFont = preg_match('/font-family:ff(.*?);/', $html, $matches);

        if ($existingFont) {
            $html = str_replace($hiddenFontStr, $matches[0], $html);
        }

        file_put_contents($tempDir->path('output.html'), $html);

        return $tempDir;
    }
}
