<?php

namespace App\Lib\Pii;

/**
 * Strict JSON schema for the extraction response. Strict mode requires every
 * property to be listed in `required` and `additionalProperties: false`;
 * optional values are expressed as nullable types.
 */
final class Schema
{
    public const VERSION = 1;

    public const MATTERS = [
        'PUBLIC_CONSULTATION', 'PERMIT_OR_LICENCE', 'CONTRACT', 'HR', 'SUPERVISION_OR_ENFORCEMENT',
        'DISPUTE_OR_CHALLENGE', 'INFORMATION_REQUEST', 'PROPERTY_NOTIFICATION', 'SYSTEM_ACCESS',
        'COURT_CORRESPONDENCE', 'OTHER',
    ];

    public const ROLES = ['AUTHOR', 'ADDRESSEE', 'SIGNATORY', 'SUBJECT_OF_DECISION', 'REPRESENTATIVE', 'MENTIONED'];

    public const IDENTIFIER_TYPES = ['EMAIL', 'PHONE', 'POSTAL_ADDRESS', 'PROPERTY_CADASTRAL', 'IBAN', 'BIRTH_DATE', 'VEHICLE', 'CASE_NUMBER', 'OTHER'];

    public const LEGAL_ENTITY_KINDS = ['COMPANY', 'NGO', 'PUBLIC_BODY', 'MUNICIPALITY', 'OTHER'];

    public static function json(): array
    {
        $string = ['type' => 'string'];
        $nullableString = ['type' => ['string', 'null']];

        $identifier = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['type', 'value', 'nature'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => self::IDENTIFIER_TYPES],
                'value' => $string,
                'nature' => ['type' => 'string', 'enum' => ['WORK', 'PRIVATE', 'UNKNOWN']],
            ],
        ];

        $subject = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'name', 'personal_code', 'surface_forms', 'linked_metadata_initials', 'context', 'role',
                'organisation', 'identifiers', 'subject_flags', 'evidence', 'confidence',
            ],
            'properties' => [
                'name' => $nullableString,
                'personal_code' => $nullableString,
                'surface_forms' => ['type' => 'array', 'items' => $string],
                'linked_metadata_initials' => $nullableString,
                'context' => ['type' => 'string', 'enum' => Contexts::ALL],
                'role' => ['type' => 'string', 'enum' => self::ROLES],
                'organisation' => $nullableString,
                'identifiers' => ['type' => 'array', 'items' => $identifier],
                'subject_flags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => Flags::SUBJECT_FLAGS]],
                'evidence' => $string,
                'confidence' => ['type' => 'string', 'enum' => ['HIGH', 'MEDIUM', 'LOW']],
            ],
        ];

        $legalEntity = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['name', 'registry_code', 'kind'],
            'properties' => [
                'name' => $string,
                'registry_code' => $nullableString,
                'kind' => ['type' => 'string', 'enum' => self::LEGAL_ENTITY_KINDS],
            ],
        ];

        $document = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['matter', 'summary', 'flags', 'restriction_stamp_holder', 'restriction_stamp_basis', 'public_interest', 'public_interest_reason'],
            'properties' => [
                'matter' => ['type' => 'string', 'enum' => self::MATTERS],
                'summary' => $string,
                'flags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => Flags::MODEL_FLAGS]],
                'restriction_stamp_holder' => $nullableString,
                'restriction_stamp_basis' => $nullableString,
                'public_interest' => ['type' => 'string', 'enum' => ['HIGH', 'MEDIUM', 'LOW']],
                'public_interest_reason' => $string,
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['subjects', 'legal_entities', 'document'],
            'properties' => [
                'subjects' => ['type' => 'array', 'items' => $subject],
                'legal_entities' => ['type' => 'array', 'items' => $legalEntity],
                'document' => $document,
            ],
        ];
    }
}
