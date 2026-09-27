<?php

namespace App\Lib\Pii;

/**
 * Processing context of a natural person in a document: in what capacity
 * they appear. Drives the per-subject action in Rules.
 */
final class Contexts
{
    public const PUBLIC_OFFICIAL_WORK_MATTER = 'PUBLIC_OFFICIAL_WORK_MATTER';
    public const PUBLIC_OFFICIAL_HR_MATTER = 'PUBLIC_OFFICIAL_HR_MATTER';
    public const REPRESENTING_LEGAL_ENTITY = 'REPRESENTING_LEGAL_ENTITY';
    public const REGULATED_PROFESSIONAL = 'REGULATED_PROFESSIONAL';
    public const NATURAL_PERSON_CONTRACT_PARTY = 'NATURAL_PERSON_CONTRACT_PARTY';
    public const LICENCE_OR_CERTIFICATE_HOLDER = 'LICENCE_OR_CERTIFICATE_HOLDER';
    public const PRIVATE_PERSON_APPLICANT = 'PRIVATE_PERSON_APPLICANT';
    public const PRIVATE_PERSON_SUBJECT = 'PRIVATE_PERSON_SUBJECT';
    public const PRIVATE_PERSON_THIRD_PARTY = 'PRIVATE_PERSON_THIRD_PARTY';
    public const STUDENT_OR_INTERN = 'STUDENT_OR_INTERN';
    public const PUBLIC_FIGURE = 'PUBLIC_FIGURE';
    public const UNKNOWN = 'UNKNOWN';

    public const ALL = [
        self::PUBLIC_OFFICIAL_WORK_MATTER,
        self::PUBLIC_OFFICIAL_HR_MATTER,
        self::REPRESENTING_LEGAL_ENTITY,
        self::REGULATED_PROFESSIONAL,
        self::NATURAL_PERSON_CONTRACT_PARTY,
        self::LICENCE_OR_CERTIFICATE_HOLDER,
        self::PRIVATE_PERSON_APPLICANT,
        self::PRIVATE_PERSON_SUBJECT,
        self::PRIVATE_PERSON_THIRD_PARTY,
        self::STUDENT_OR_INTERN,
        self::PUBLIC_FIGURE,
        self::UNKNOWN,
    ];

    /** Contexts where the name (and personal code) stays as-is. */
    public const KEEP = [
        self::PUBLIC_OFFICIAL_WORK_MATTER,
        self::REPRESENTING_LEGAL_ENTITY,
        self::REGULATED_PROFESSIONAL,
        self::NATURAL_PERSON_CONTRACT_PARTY,
        self::LICENCE_OR_CERTIFICATE_HOLDER,
        self::PUBLIC_FIGURE,
    ];

    /** Contexts where work contact details are removed too. */
    public const REMOVE_WORK_CONTACT = [
        self::STUDENT_OR_INTERN,
        self::PUBLIC_OFFICIAL_HR_MATTER,
        self::UNKNOWN,
    ];

    public const PRIVATE_PERSON = [
        self::PRIVATE_PERSON_APPLICANT,
        self::PRIVATE_PERSON_SUBJECT,
        self::PRIVATE_PERSON_THIRD_PARTY,
    ];

    public const LABELS = [
        self::PUBLIC_OFFICIAL_WORK_MATTER => 'Ametnik tööülesannetes',
        self::PUBLIC_OFFICIAL_HR_MATTER => 'Ametnik personaliasjas',
        self::REPRESENTING_LEGAL_ENTITY => 'Juriidilise isiku esindaja',
        self::REGULATED_PROFESSIONAL => 'Reguleeritud kutse (notar, kohtutäitur jne)',
        self::NATURAL_PERSON_CONTRACT_PARTY => 'Füüsilisest isikust lepingupool',
        self::LICENCE_OR_CERTIFICATE_HOLDER => 'Loa või sertifikaadi omanik',
        self::PRIVATE_PERSON_APPLICANT => 'Eraisik: pöörduja',
        self::PRIVATE_PERSON_SUBJECT => 'Eraisik: menetluse subjekt',
        self::PRIVATE_PERSON_THIRD_PARTY => 'Eraisik: kolmas isik',
        self::STUDENT_OR_INTERN => 'Õpilane või praktikant',
        self::PUBLIC_FIGURE => 'Avaliku elu tegelane',
        self::UNKNOWN => 'Teadmata',
    ];

    public static function isKeep(string $context): bool
    {
        return in_array($context, self::KEEP, true);
    }

    public static function label(string $context): string
    {
        return self::LABELS[$context] ?? $context;
    }
}
