<?php

namespace App\Lib\Parser\Util;

use Spatie\TemporaryDirectory\TemporaryDirectory;

class Poppler
{
    public function __construct()
    {

    }

    public function getHtmlDirectory(string $localPdfPath): TemporaryDirectory
    {
        $tempDir = TemporaryDirectory::make(storage_path('temp/poppler'));


        // copy pdf to the temp directory using php
        copy($localPdfPath, $tempDir->path('input.pdf'));

        $mountDir = $tempDir->path().':/data';

        $path = $tempDir->path();

        $popplerCmd = "docker run --rm -v $mountDir elswork/poppler-utils pdftohtml input.pdf -c -noframes output.html";

        $out = shell_exec($popplerCmd);

        info($out);

        $html = file_get_contents($tempDir->path('output.html'));
        // replace bgcolor attribute on body
        $html = preg_replace('/<body(.*?)>/', '<body>', $html);


        $styles = <<<STYLES
        body{
            font-size: 12px;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
        }
        @page {
            size: 210mm 297mm;
        }
        STYLES;
        // add styles to end of head
        $html = preg_replace('/<\/head>/', "<style>$styles</style></head>", $html);

        file_put_contents($tempDir->path('output.html'), $html);

        return $tempDir;
    }


}
