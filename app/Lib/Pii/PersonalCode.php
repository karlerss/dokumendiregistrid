<?php

namespace App\Lib\Pii;

/**
 * Estonian personal identification code (isikukood): 11 digits, first digit
 * 1–8 (century + sex), YYMMDD, three serial digits, checksum.
 */
final class PersonalCode
{
    private const WEIGHTS_1 = [1, 2, 3, 4, 5, 6, 7, 8, 9, 1];
    private const WEIGHTS_2 = [3, 4, 5, 6, 7, 8, 9, 1, 2, 3];

    public static function isValid(?string $code): bool
    {
        if ($code === null || !preg_match('/^[1-8]\d{10}$/', $code)) {
            return false;
        }
        $month = (int)substr($code, 3, 2);
        $day = (int)substr($code, 5, 2);
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return false;
        }
        return self::checksum($code) === (int)$code[10];
    }

    public static function checksum(string $code): int
    {
        $digits = array_map('intval', str_split(substr($code, 0, 10)));
        $sum = 0;
        foreach ($digits as $i => $d) {
            $sum += $d * self::WEIGHTS_1[$i];
        }
        $mod = $sum % 11;
        if ($mod === 10) {
            $sum = 0;
            foreach ($digits as $i => $d) {
                $sum += $d * self::WEIGHTS_2[$i];
            }
            $mod = $sum % 11;
            if ($mod === 10) {
                $mod = 0;
            }
        }
        return $mod;
    }

    /**
     * Every valid personal code in a text, de-duplicated.
     *
     * @return string[]
     */
    public static function findAll(string $text): array
    {
        if (!preg_match_all('/(?<!\d)[1-8]\d{10}(?!\d)/', $text, $m)) {
            return [];
        }
        return array_values(array_unique(array_filter($m[0], [self::class, 'isValid'])));
    }

    /** Strip spaces/dashes the model may have kept from the source ("3861025 4217"). */
    public static function normalize(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $code);
        return $digits === '' ? null : $digits;
    }
}
