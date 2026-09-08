<?php

namespace App\Lib\Restriction;

/**
 * Decides whether an access-restriction basis string refers to personal data,
 * i.e. AvTS § 35 lg 1 p 12 ("teave, mis sisaldab isikuandmeid, kui sellisele
 * teabele juurdepääsu võimaldamine kahjustaks oluliselt andmesubjekti eraelu
 * puutumatust").
 *
 * The registries are inconsistent: "AvTS § 35 lg 1 p 12", "§ 35 lg 1 p 12
 * teave", "p 12", bare "12" (from comma-splitting "AvTS § 35 lg 1 p 1, 12"),
 * ranges like "p 11-15", lists like "p 12,19", and free text without any
 * paragraph number.
 */
class BasisClassifier
{
    private const PERSONAL_DATA_POINT = 12;

    private const KEYWORDS = [
        'isikuandme',
        'eraelu puutumatus',
        'eraelu kahjustav',
    ];

    /**
     * Acts other than AvTS that also number their provisions; a fragment
     * explicitly citing one of these is never treated as AvTS p 12.
     */
    private const OTHER_ACT_PATTERN = '/\b(?!(?i:AvTS)\b)[A-ZÕÄÖÜ][A-Za-zÕÄÖÜõäöü]{1,12}(?:_?\d{4}(?:_\d{4})?)?(?:-i)?\s*§/u';

    public static function isPersonalData(?string $basis): bool
    {
        if ($basis === null) {
            return false;
        }
        $basis = trim($basis);
        if ($basis === '') {
            return false;
        }

        foreach (self::fragments($basis) as $fragment) {
            if (self::fragmentIsPersonalData($fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $bases
     */
    public static function anyPersonalData(array $bases): bool
    {
        foreach ($bases as $basis) {
            if (self::isPersonalData($basis)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Split on the separators the registries use between multiple bases,
     * keeping a fragment together with the act/section it belongs to.
     *
     * @return string[]
     */
    private static function fragments(string $basis): array
    {
        $parts = preg_split('/\s*[;,]\s*/u', $basis) ?: [$basis];
        $out = [];
        $context = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            // A part that names its own section resets the context; a bare
            // number or "p N" inherits the preceding section.
            if (preg_match('/§/u', $part)) {
                $context = $part;
                $out[] = $part;
            } elseif (preg_match('/^(?:p\s*)?\d/u', $part) && $context !== '') {
                $out[] = $context . ' ' . (str_starts_with(mb_strtolower($part), 'p') ? $part : 'p ' . $part);
            } else {
                $out[] = $part;
            }
        }
        return $out;
    }

    private static function fragmentIsPersonalData(string $fragment): bool
    {
        $lower = mb_strtolower($fragment);

        foreach (self::KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        // Cites another act (LS, KrMS, HkMS, PPVS, ÕKS, RHS, ÜSS2021_2027 …).
        if (preg_match(self::OTHER_ACT_PATTERN, $fragment)) {
            return false;
        }

        // If a section is stated it must be § 35; if a subsection is stated it
        // must be lg 1.
        if (preg_match('/§\s*(\d+)/u', $fragment, $m) && (int)$m[1] !== 35) {
            return false;
        }
        if (preg_match('/\blg\s*(\d+)/iu', $fragment, $m) && (int)$m[1] !== 1) {
            return false;
        }

        return in_array(self::PERSONAL_DATA_POINT, self::points($fragment), true);
    }

    /**
     * Extract the "p N", "p N-M", "p N, M" and bare-number points cited in a
     * fragment.
     *
     * @return int[]
     */
    private static function points(string $fragment): array
    {
        $points = [];

        // "p 12", "p 11-15", "p 12,19", "p. 12", "punkt 12"
        if (preg_match_all('/\b(?:p|punkt|p\.)\s*((?:\d+(?:\s*[-–]\s*\d+)?)(?:\s*,\s*\d+(?:\s*[-–]\s*\d+)?)*)/iu', $fragment, $matches)) {
            foreach ($matches[1] as $list) {
                foreach (preg_split('/\s*,\s*/', $list) as $item) {
                    if (preg_match('/^(\d+)\s*[-–]\s*(\d+)$/u', $item, $r)) {
                        for ($i = (int)$r[1]; $i <= (int)$r[2]; $i++) {
                            $points[] = $i;
                        }
                    } elseif (preg_match('/^(\d+)/u', $item, $r)) {
                        $points[] = (int)$r[1];
                    }
                }
            }
            return $points;
        }

        // Bare number(s) with no "p": "12", "12,19".
        if (preg_match('/^\s*(\d+(?:\s*[-–]\s*\d+)?(?:\s*,\s*\d+)*)\s*$/u', $fragment, $m)) {
            foreach (preg_split('/\s*,\s*/', $m[1]) as $item) {
                if (preg_match('/^(\d+)\s*[-–]\s*(\d+)$/u', $item, $r)) {
                    for ($i = (int)$r[1]; $i <= (int)$r[2]; $i++) {
                        $points[] = $i;
                    }
                } else {
                    $points[] = (int)$item;
                }
            }
        }

        return $points;
    }
}
