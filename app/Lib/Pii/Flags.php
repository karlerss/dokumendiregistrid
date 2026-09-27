<?php

namespace App\Lib\Pii;

/**
 * Document- and subject-level flags. A flag is either a re-identification
 * flag (the person stays recognisable after names and identifiers are
 * removed), a sensitivity flag (what is said about them), a source flag, or
 * a technical flag. Rules treats the kinds differently.
 */
final class Flags
{
    public const KIND_REID = 'reid';
    public const KIND_SENSITIVITY = 'sensitivity';
    public const KIND_SOURCE = 'source';
    public const KIND_TECHNICAL = 'technical';

    public const IDENTIFIABLE_BY_CONTEXT = 'IDENTIFIABLE_BY_CONTEXT';
    public const HOME_LOCATION_DETAIL = 'HOME_LOCATION_DETAIL';
    public const PROPERTY_OWNERSHIP = 'PROPERTY_OWNERSHIP';
    public const RESTRICTION_STAMP_PRESENT = 'RESTRICTION_STAMP_PRESENT';
    public const FAMILY_CIRCUMSTANCES = 'FAMILY_CIRCUMSTANCES';
    public const HEALTH = 'HEALTH';
    public const POLITICAL_OR_RELIGIOUS_OPINION = 'POLITICAL_OR_RELIGIOUS_OPINION';
    public const ETHNICITY_OR_LANGUAGE = 'ETHNICITY_OR_LANGUAGE';
    public const CRIMINAL_MATTER = 'CRIMINAL_MATTER';
    public const SOCIAL_BENEFITS = 'SOCIAL_BENEFITS';
    public const FINANCIAL_CIRCUMSTANCES = 'FINANCIAL_CIRCUMSTANCES';
    public const EMPLOYMENT_RECORD = 'EMPLOYMENT_RECORD';
    public const CONFLICT_OR_DISPUTE = 'CONFLICT_OR_DISPUTE';
    public const MINOR_INVOLVED = 'MINOR_INVOLVED';
    public const VULNERABLE_PERSON = 'VULNERABLE_PERSON';
    public const SCANNED_OR_IMAGE_CONTENT = 'SCANNED_OR_IMAGE_CONTENT';
    public const MULTIPLE_PRIVATE_PERSONS = 'MULTIPLE_PRIVATE_PERSONS';

    public const KINDS = [
        self::IDENTIFIABLE_BY_CONTEXT => self::KIND_REID,
        self::HOME_LOCATION_DETAIL => self::KIND_REID,
        self::PROPERTY_OWNERSHIP => self::KIND_REID,
        self::RESTRICTION_STAMP_PRESENT => self::KIND_SOURCE,
        self::FAMILY_CIRCUMSTANCES => self::KIND_SENSITIVITY,
        self::HEALTH => self::KIND_SENSITIVITY,
        self::POLITICAL_OR_RELIGIOUS_OPINION => self::KIND_SENSITIVITY,
        self::ETHNICITY_OR_LANGUAGE => self::KIND_SENSITIVITY,
        self::CRIMINAL_MATTER => self::KIND_SENSITIVITY,
        self::SOCIAL_BENEFITS => self::KIND_SENSITIVITY,
        self::FINANCIAL_CIRCUMSTANCES => self::KIND_SENSITIVITY,
        self::EMPLOYMENT_RECORD => self::KIND_SENSITIVITY,
        self::CONFLICT_OR_DISPUTE => self::KIND_SENSITIVITY,
        self::MINOR_INVOLVED => self::KIND_SENSITIVITY,
        self::VULNERABLE_PERSON => self::KIND_SENSITIVITY,
        self::SCANNED_OR_IMAGE_CONTENT => self::KIND_TECHNICAL,
        self::MULTIPLE_PRIVATE_PERSONS => self::KIND_TECHNICAL,
    ];

    /** Flags the model may return; MULTIPLE_PRIVATE_PERSONS is computed. */
    public const MODEL_FLAGS = [
        self::IDENTIFIABLE_BY_CONTEXT,
        self::HOME_LOCATION_DETAIL,
        self::PROPERTY_OWNERSHIP,
        self::RESTRICTION_STAMP_PRESENT,
        self::FAMILY_CIRCUMSTANCES,
        self::HEALTH,
        self::POLITICAL_OR_RELIGIOUS_OPINION,
        self::ETHNICITY_OR_LANGUAGE,
        self::CRIMINAL_MATTER,
        self::SOCIAL_BENEFITS,
        self::FINANCIAL_CIRCUMSTANCES,
        self::EMPLOYMENT_RECORD,
        self::CONFLICT_OR_DISPUTE,
        self::MINOR_INVOLVED,
        self::VULNERABLE_PERSON,
        self::SCANNED_OR_IMAGE_CONTENT,
    ];

    /** Subject-level subset (what applies to one person specifically). */
    public const SUBJECT_FLAGS = [
        self::IDENTIFIABLE_BY_CONTEXT,
        self::HOME_LOCATION_DETAIL,
        self::PROPERTY_OWNERSHIP,
        self::FAMILY_CIRCUMSTANCES,
        self::HEALTH,
        self::POLITICAL_OR_RELIGIOUS_OPINION,
        self::ETHNICITY_OR_LANGUAGE,
        self::CRIMINAL_MATTER,
        self::SOCIAL_BENEFITS,
        self::FINANCIAL_CIRCUMSTANCES,
        self::EMPLOYMENT_RECORD,
        self::CONFLICT_OR_DISPUTE,
        self::MINOR_INVOLVED,
        self::VULNERABLE_PERSON,
    ];

    public const LABELS = [
        self::IDENTIFIABLE_BY_CONTEXT => 'Tuvastatav konteksti järgi',
        self::HOME_LOCATION_DETAIL => 'Elukoha täpsed andmed',
        self::PROPERTY_OWNERSHIP => 'Kinnisvara omand',
        self::RESTRICTION_STAMP_PRESENT => 'Allika AK-märge tekstis',
        self::FAMILY_CIRCUMSTANCES => 'Pereolud',
        self::HEALTH => 'Tervis',
        self::POLITICAL_OR_RELIGIOUS_OPINION => 'Poliitiline või usuline veendumus',
        self::ETHNICITY_OR_LANGUAGE => 'Rahvus või keel',
        self::CRIMINAL_MATTER => 'Süütegu',
        self::SOCIAL_BENEFITS => 'Sotsiaaltoetused',
        self::FINANCIAL_CIRCUMSTANCES => 'Majanduslik olukord',
        self::EMPLOYMENT_RECORD => 'Teenistuskäik',
        self::CONFLICT_OR_DISPUTE => 'Vaidlus või konflikt',
        self::MINOR_INVOLVED => 'Alaealine',
        self::VULNERABLE_PERSON => 'Haavatav isik',
        self::SCANNED_OR_IMAGE_CONTENT => 'Skaneeritud või pildisisu',
        self::MULTIPLE_PRIVATE_PERSONS => 'Mitu eraisikut',
    ];

    public static function kind(string $flag): ?string
    {
        return self::KINDS[$flag] ?? null;
    }

    public static function label(string $flag): string
    {
        return self::LABELS[$flag] ?? $flag;
    }

    /** @param string[] $flags */
    public static function ofKind(array $flags, string $kind): array
    {
        return array_values(array_filter($flags, fn($f) => self::kind($f) === $kind));
    }
}
