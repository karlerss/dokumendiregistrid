<?php

namespace App\Lib\Pii;

use Illuminate\Support\Facades\Process;

/**
 * PDF redaction through PyMuPDF (bin/redact_pdf.py) and office-to-PDF
 * conversion through LibreOffice.
 */
class PyMuPdfRedactor implements PdfRedactor
{
    public function redact(string $inputPath, string $outputPath, array $forms): array
    {
        $formsFile = $outputPath . '.forms.json';
        file_put_contents($formsFile, json_encode(array_values($forms), JSON_UNESCAPED_UNICODE));
        try {
            $result = Process::timeout(300)->run([
                config('pii.python'), __DIR__ . '/bin/redact_pdf.py', $inputPath, $outputPath, $formsFile,
            ]);
        } finally {
            @unlink($formsFile);
        }
        if (!$result->successful()) {
            throw new \RuntimeException('redact_pdf.py failed: ' . trim($result->errorOutput() ?: $result->output()));
        }
        $out = json_decode($result->output(), true);
        if (!is_array($out) || !isset($out['hits'])) {
            throw new \RuntimeException('redact_pdf.py returned unexpected output: ' . mb_substr($result->output(), 0, 300));
        }
        return ['hits' => $out['hits'], 'pages' => (int)($out['pages'] ?? 0)];
    }

    public function convertToPdf(string $inputPath, string $outputDir): string
    {
        $result = Process::timeout(300)->env(['HOME' => sys_get_temp_dir()])->run([
            config('pii.soffice'), '--headless', '--norestore', '--convert-to', 'pdf', '--outdir', $outputDir, $inputPath,
        ]);
        $expected = $outputDir . '/' . pathinfo($inputPath, PATHINFO_FILENAME) . '.pdf';
        if (!$result->successful() || !file_exists($expected)) {
            throw new \RuntimeException('soffice conversion failed: ' . trim($result->errorOutput() ?: $result->output()));
        }
        return $expected;
    }
}
