<?php

namespace App\Lib\Pii;

use App\Models\Document;
use App\Models\File;

/**
 * Verifies that strings the model returned actually occur in the document.
 * Every surface the site can show is searched: file text, stripped HTML,
 * file names, title, addressee, responsible, signatures. Matching is
 * case-sensitive with whitespace collapsed on both sides.
 */
class Grounding
{
    public const SURFACE_BODY = 'body';
    public const SURFACE_HTML = 'html';
    public const SURFACE_FILENAME = 'filename';
    public const SURFACE_TITLE = 'title';
    public const SURFACE_TO = 'to';
    public const SURFACE_RESPONSIBLE = 'responsible';
    public const SURFACE_SIGNATURE = 'signature';
    public const SURFACE_AI = 'ai';

    /** @var array<string, string> surface key => normalised text */
    private array $surfaces = [];

    /** @param array<string, string|null> $surfaces */
    public function __construct(array $surfaces)
    {
        foreach ($surfaces as $key => $text) {
            if ($text !== null && $text !== '') {
                $this->surfaces[$key] = self::normalize($text);
            }
        }
    }

    public static function forDocument(Document $document): self
    {
        $document->loadMissing(['files.signatures']);
        $surfaces = [
            self::SURFACE_TITLE => $document->title,
            self::SURFACE_TO => $document->to,
            self::SURFACE_RESPONSIBLE => $document->responsible,
            self::SURFACE_AI => trim(($document->ai_title ?? '') . ' ' . strip_tags($document->ai_summary ?? '')),
        ];
        foreach ($document->files as $file) {
            /** @var File $file */
            $surfaces[self::SURFACE_BODY . ':' . $file->id] = $file->contents;
            $surfaces[self::SURFACE_HTML . ':' . $file->id] = self::stripHtml($file->html);
            $surfaces[self::SURFACE_FILENAME . ':' . $file->id] = $file->name;
            foreach ($file->signatures as $sig) {
                $surfaces[self::SURFACE_SIGNATURE . ':' . $sig->id] = trim($sig->name . ' ' . $sig->pno);
            }
        }
        return new self($surfaces);
    }

    /** Tags become spaces (so adjacent blocks do not fuse), entities are decoded. */
    public static function stripHtml(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }
        $text = preg_replace('/<[^>]*>/u', ' ', $html);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function normalize(string $text): string
    {
        // NBSP and friends become plain spaces; runs collapse to one.
        $text = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{FEFF}]/u', ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Surface keys where the needle occurs.
     *
     * @return string[]
     */
    public function find(string $needle): array
    {
        $needle = self::normalize($needle);
        if ($needle === '' || mb_strlen($needle) < 2) {
            return [];
        }
        $hits = [];
        foreach ($this->surfaces as $key => $text) {
            if (mb_strpos($text, $needle) !== false) {
                $hits[] = $key;
            }
        }
        return $hits;
    }

    public function occurs(string $needle): bool
    {
        return $this->find($needle) !== [];
    }

    /**
     * Split verified from unverified forms.
     *
     * @param string[] $forms
     * @return array{0: array<int, array{text: string, in: string[]}>, 1: string[]}
     */
    public function verifyAll(array $forms): array
    {
        $verified = [];
        $unverified = [];
        $seen = [];
        foreach ($forms as $form) {
            $form = is_string($form) ? trim($form) : '';
            if ($form === '' || isset($seen[$form])) {
                continue;
            }
            $seen[$form] = true;
            $in = $this->find($form);
            if ($in) {
                $verified[] = ['text' => $form, 'in' => $in];
            } else {
                $unverified[] = $form;
            }
        }
        return [$verified, $unverified];
    }

    /** All normalised text joined, for regex sweeps. */
    public function allText(): string
    {
        return implode("\n", $this->surfaces);
    }
}
