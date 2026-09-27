<?php

namespace App\Lib\Pii;

interface PdfRedactor
{
    /**
     * True redaction of every occurrence of each string in a PDF.
     *
     * @param string[] $forms
     * @return array{hits: array<string, int>, pages: int}
     * @throws \RuntimeException when the tool fails
     */
    public function redact(string $inputPath, string $outputPath, array $forms): array;

    /**
     * Convert an office document to PDF; returns the produced path.
     *
     * @throws \RuntimeException
     */
    public function convertToPdf(string $inputPath, string $outputDir): string;
}
