<?php

namespace App\Lib\Pii;

use App\Models\Document;
use App\Models\File;

/**
 * Assembles the extraction input for one document: a metadata block, the
 * file list, signatures, then each file's plain text. Splits into chunks per
 * file when the whole thing is too large for one call.
 */
class PromptBuilder
{
    public const MIN_FILE_CHARS = 50;

    private array $metadata;
    /** @var array<int, array{file: File, text: string, duplicate_of: int|null}> */
    private array $bodies = [];
    private bool $truncated = false;

    public function __construct(private Document $document)
    {
        $document->loadMissing(['organisation', 'files.signatures']);
        $this->metadata = [
            'Dokumendiregister' => $document->organisation?->name,
            'Liik' => $document->type,
            'Funktsioon' => $document->function,
            'Sari' => $document->series,
            'Toimik' => $document->dossier,
            'Pealkiri' => $document->title,
            'Adressaat (registri väli)' => $document->to,
            'Vastutaja (registri väli)' => $document->responsible,
            'Registreeritud' => optional($document->registration_date)->format('d.m.Y'),
            'Viit' => $document->reference,
        ];

        $maxFile = (int)config('pii.max_file_chars');
        $seen = [];
        foreach ($document->files as $file) {
            $text = trim((string)$file->contents);
            if (mb_strlen($text) < self::MIN_FILE_CHARS) {
                continue;
            }
            if (mb_strlen($text) > $maxFile) {
                $text = mb_substr($text, 0, $maxFile);
                $this->truncated = true;
            }
            $hash = md5($text);
            $this->bodies[] = ['file' => $file, 'text' => $text, 'duplicate_of' => $seen[$hash] ?? null];
            $seen[$hash] ??= $file->id;
        }
    }

    public static function systemPrompt(): string
    {
        return file_get_contents(resource_path('prompts/pii_extract_v' . Extractor::PROMPT_VERSION . '.md'));
    }

    public function metadataBlock(): string
    {
        $lines = ['## Dokumendi metaandmed'];
        foreach ($this->metadata as $k => $v) {
            if ($v !== null && $v !== '') {
                $lines[] = "$k: $v";
            }
        }
        $lines[] = '';
        $lines[] = '## Failid';
        foreach ($this->document->files as $file) {
            $lines[] = sprintf('- [fail %d] %s%s', $file->id, $file->name, $file->parent_id ? " (sisaldub failis {$file->parent_id})" : '');
        }
        $signatures = [];
        foreach ($this->document->files as $file) {
            foreach ($file->signatures as $sig) {
                $signatures[] = sprintf('- %s, isikukood %s, allkirjastas %s (fail %d)', $sig->name, $sig->pno, $sig->signing_time, $file->id);
            }
        }
        if ($signatures) {
            $lines[] = '';
            $lines[] = '## Digiallkirjad';
            array_push($lines, ...$signatures);
        }
        return implode("\n", $lines);
    }

    /**
     * One or more user prompts. Each repeats the metadata block.
     *
     * @return string[]
     */
    public function chunks(): array
    {
        $head = $this->metadataBlock() . "\n\n## Failide sisu\n";
        $max = (int)config('pii.max_input_chars');

        $sections = [];
        foreach ($this->bodies as $b) {
            if ($b['duplicate_of'] !== null) {
                $sections[] = sprintf("\n### [fail %d] %s\n(sisu identne failiga %d)\n", $b['file']->id, $b['file']->name, $b['duplicate_of']);
                continue;
            }
            $sections[] = sprintf("\n### [fail %d] %s\n%s\n", $b['file']->id, $b['file']->name, $b['text']);
        }
        if (!$sections) {
            return [$head . "\n(failidel puudub tekstisisu)\n"];
        }

        $chunks = [];
        $current = '';
        foreach ($sections as $section) {
            if ($current !== '' && mb_strlen($head) + mb_strlen($current) + mb_strlen($section) > $max) {
                $chunks[] = $head . $current;
                $current = '';
            }
            $current .= $section;
        }
        $chunks[] = $head . $current;
        if (count($chunks) > 1) {
            foreach ($chunks as $i => $c) {
                $chunks[$i] = sprintf("(osa %d/%d samast dokumendist)\n\n", $i + 1, count($chunks)) . $c;
            }
        }
        return $chunks;
    }

    public function inputChars(): int
    {
        return array_sum(array_map('mb_strlen', $this->chunks()));
    }

    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    public function hasTextContent(): bool
    {
        return $this->bodies !== [];
    }

    /** No file has text, but there are PDFs or images: the content is scanned. */
    public function needsOcr(): bool
    {
        if ($this->hasTextContent() || $this->document->files->isEmpty()) {
            return false;
        }
        return $this->document->files->contains(fn(File $f) => in_array($f->getExtension(), ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff'], true));
    }
}
