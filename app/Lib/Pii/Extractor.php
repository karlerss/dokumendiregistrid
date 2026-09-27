<?php

namespace App\Lib\Pii;

use App\Lib\LLM\AiProvider;
use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\PiiExtraction;
use App\Models\PiiSubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Runs one extraction: assembles the prompt, calls the model once per chunk,
 * merges, verifies every returned string against the document (grounding),
 * sweeps for personal codes the model missed, and stores subjects.
 *
 * Writes only to pii_extractions / pii_subjects.
 */
class Extractor
{
    public const PROMPT_VERSION = 1;

    public function __construct(private AiProvider $ai)
    {
    }

    /**
     * Automatic selection: flagged by the re-check daemon as restricted for
     * personal data at the source, still visible here, and not yet extracted
     * with the current prompt version.
     */
    public static function selectionQuery(): Builder
    {
        return Document::query()
            ->where('visible', true)
            ->whereIn('id', function ($q) {
                $q->select('document_id')->from('document_remote_states')->where('personal_data_restriction', true);
            })
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('pii_extractions')
                    ->whereColumn('pii_extractions.document_id', 'documents.id')
                    ->where('pii_extractions.prompt_version', self::PROMPT_VERSION);
            })
            ->orderBy('id');
    }

    /** Whether a document is in the automatic selection's population (ignoring extraction state). */
    public static function isFlagged(Document $document): bool
    {
        return DocumentRemoteState::query()->where('document_id', $document->id)->where('personal_data_restriction', true)->exists();
    }

    /**
     * Create the pending row for a run, or return the one already pending.
     */
    public static function request(Document $document, string $requestedBy): PiiExtraction
    {
        $existing = PiiExtraction::query()
            ->where('document_id', $document->id)
            ->where('prompt_version', self::PROMPT_VERSION)
            ->where('status', PiiExtraction::STATUS_PENDING)
            ->first();
        if ($existing) {
            return $existing;
        }
        return PiiExtraction::create([
            'document_id' => $document->id,
            'model' => config('pii.model'),
            'prompt_version' => self::PROMPT_VERSION,
            'schema_version' => Schema::VERSION,
            'status' => PiiExtraction::STATUS_PENDING,
            'requested_by' => $requestedBy,
        ]);
    }

    /** Tokens spent by the automatic selection today (UTC), for the cap. */
    public static function tokensUsedToday(): int
    {
        return (int)PiiExtraction::query()
            ->where('requested_by', PiiExtraction::REQUESTED_BY_WORKER)
            ->where('created_at', '>=', now('UTC')->startOfDay())
            ->selectRaw('coalesce(sum(input_tokens + output_tokens), 0) as t')
            ->value('t');
    }

    public function run(PiiExtraction $extraction): void
    {
        $document = $extraction->document()->with(['organisation', 'files.signatures'])->first();
        if (!$document) {
            $extraction->forceFill(['status' => PiiExtraction::STATUS_FAILED, 'error' => 'document deleted', 'finished_at' => now()])->save();
            return;
        }

        $extraction->forceFill(['status' => PiiExtraction::STATUS_PENDING, 'started_at' => now(), 'attempts' => $extraction->attempts + 1, 'error' => null])->save();

        $builder = new PromptBuilder($document);
        if ($builder->needsOcr()) {
            $extraction->forceFill(['status' => PiiExtraction::STATUS_NEEDS_OCR, 'finished_at' => now(), 'input_chars' => 0])->save();
            return;
        }

        $chunks = $builder->chunks();
        $system = PromptBuilder::systemPrompt();
        $schema = Schema::json();

        try {
            $responses = [];
            $inputTokens = 0;
            $outputTokens = 0;
            $model = $extraction->model;
            foreach ($chunks as $chunk) {
                $res = $this->ai->getJsonWithUsage($system, $chunk, $schema);
                $responses[] = $res['data'];
                $inputTokens += $res['input_tokens'];
                $outputTokens += $res['output_tokens'];
                $model = $res['model'] ?: $model;
            }
        } catch (\Throwable $e) {
            $final = $extraction->attempts >= PiiExtraction::MAX_ATTEMPTS;
            $extraction->forceFill([
                'status' => $final ? PiiExtraction::STATUS_FAILED : PiiExtraction::STATUS_PENDING,
                'queued_at' => null,
                'finished_at' => $final ? now() : null,
                'error' => mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 2000),
            ])->save();
            return;
        }

        $merged = $this->merge($responses);
        $grounding = Grounding::forDocument($document);
        [$subjects, $dropped] = $this->groundSubjects($merged['subjects'], $grounding);
        $subjects = $this->sweepPersonalCodes($subjects, $grounding);

        $flags = array_values(array_unique($merged['document']['flags'] ?? []));
        $privateCount = count(array_filter($subjects, fn($s) => in_array($s['context'], Contexts::PRIVATE_PERSON, true)));
        if ($privateCount >= 3) {
            $flags[] = Flags::MULTIPLE_PRIVATE_PERSONS;
        }
        if (($merged['document']['restriction_stamp_holder'] ?? null) || ($merged['document']['restriction_stamp_basis'] ?? null)) {
            $flags[] = Flags::RESTRICTION_STAMP_PRESENT;
        }
        $flags = array_values(array_unique($flags));

        DB::transaction(function () use ($extraction, $subjects, $merged, $flags, $dropped, $responses, $builder, $chunks, $inputTokens, $outputTokens, $model) {
            $extraction->subjects()->delete();
            foreach ($subjects as $s) {
                PiiSubject::create([
                    'extraction_id' => $extraction->id,
                    'document_id' => $extraction->document_id,
                    'name' => $s['name'],
                    'personal_code' => $s['personal_code'],
                    'context' => $s['context'],
                    'role' => $s['role'],
                    'organisation' => $s['organisation'],
                    'surface_forms' => $s['surface_forms'],
                    'unverified_forms' => $s['unverified_forms'],
                    'identifiers' => $s['identifiers'],
                    'subject_flags' => $s['subject_flags'],
                    'evidence' => $s['evidence'],
                    'confidence' => $s['confidence'],
                    'linked_metadata_initials' => $s['linked_metadata_initials'],
                ]);
            }
            $extraction->forceFill([
                'status' => $builder->wasTruncated() ? PiiExtraction::STATUS_TOO_LARGE : PiiExtraction::STATUS_DONE,
                'finished_at' => now(),
                'model' => $model,
                'chunks' => count($chunks),
                'input_chars' => $builder->inputChars(),
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'matter' => $merged['document']['matter'] ?? null,
                'summary' => $merged['document']['summary'] ?? null,
                'flags' => $flags,
                'public_interest' => $merged['document']['public_interest'] ?? null,
                'public_interest_reason' => $merged['document']['public_interest_reason'] ?? null,
                'legal_entities' => $merged['legal_entities'],
                'raw_response' => [
                    'chunks' => $responses,
                    'dropped_subjects' => $dropped,
                    'restriction_stamp_holder' => $merged['document']['restriction_stamp_holder'] ?? null,
                    'restriction_stamp_basis' => $merged['document']['restriction_stamp_basis'] ?? null,
                ],
                'error' => null,
            ])->save();
        });
    }

    /**
     * Merge chunk responses: subjects by personal code, then by normalised
     * name; document fields from the first chunk, flags and entities unioned.
     */
    private function merge(array $responses): array
    {
        $subjects = [];
        $index = [];
        $entities = [];
        $document = null;
        $flags = [];

        foreach ($responses as $res) {
            foreach ($res['subjects'] ?? [] as $s) {
                $s['personal_code_raw'] = $s['personal_code'] ?? null;
                $code = PersonalCode::normalize($s['personal_code'] ?? null);
                $nameKey = $s['name'] ? mb_strtolower(Grounding::normalize($s['name'])) : null;
                $key = ($code && PersonalCode::isValid($code)) ? 'c:' . $code : ($nameKey ? 'n:' . $nameKey : 'x:' . count($subjects));
                if (isset($index[$key])) {
                    $t = &$subjects[$index[$key]];
                    $t['surface_forms'] = array_merge($t['surface_forms'] ?? [], $s['surface_forms'] ?? []);
                    $t['identifiers'] = array_merge($t['identifiers'] ?? [], $s['identifiers'] ?? []);
                    $t['subject_flags'] = array_merge($t['subject_flags'] ?? [], $s['subject_flags'] ?? []);
                    $t['name'] ??= $s['name'] ?? null;
                    $t['personal_code'] ??= $code;
                    $t['linked_metadata_initials'] ??= $s['linked_metadata_initials'] ?? null;
                    unset($t);
                    continue;
                }
                $s['personal_code'] = $code;
                $index[$key] = count($subjects);
                $subjects[] = $s;
            }
            foreach ($res['legal_entities'] ?? [] as $e) {
                $entities[mb_strtolower($e['name'] ?? '')] = $e;
            }
            $document ??= $res['document'] ?? [];
            $flags = array_merge($flags, $res['document']['flags'] ?? []);
        }
        $document = $document ?? [];
        $document['flags'] = array_values(array_unique($flags));

        return ['subjects' => $subjects, 'legal_entities' => array_values($entities), 'document' => $document];
    }

    /**
     * Verify surface forms, personal codes and identifiers. Subjects with
     * nothing verifiable are dropped (and recorded on the extraction).
     *
     * @return array{0: array, 1: array}
     */
    private function groundSubjects(array $subjects, Grounding $grounding): array
    {
        $kept = [];
        $dropped = [];
        foreach ($subjects as $s) {
            $forms = $s['surface_forms'] ?? [];
            if (!empty($s['name'])) {
                $forms[] = $s['name'];
            }
            $code = $s['personal_code'];
            if ($code !== null) {
                if (!PersonalCode::isValid($code)) {
                    $forms[] = $code; // will land in unverified with the rest
                    $code = null;
                } elseif ($grounding->occurs($code)) {
                    $forms[] = $code;
                } else {
                    // the model may have seen it with a space; try the raw text once
                    $raw = $s['personal_code_raw'] ?? null;
                    if ($raw && $grounding->occurs($raw)) {
                        $forms[] = $raw;
                    } else {
                        $forms[] = $code;
                        $code = null;
                    }
                }
            }
            [$verified, $unverified] = $grounding->verifyAll($forms);

            $identifiers = [];
            foreach ($s['identifiers'] ?? [] as $id) {
                if (!is_array($id) || empty($id['value'])) {
                    continue;
                }
                $identifiers[] = [
                    'type' => $id['type'] ?? 'OTHER',
                    'value' => $id['value'],
                    'nature' => $id['nature'] ?? 'UNKNOWN',
                    'verified' => $grounding->occurs($id['value']),
                ];
            }
            $verifiedIdentifiers = array_filter($identifiers, fn($i) => $i['verified']);

            $row = [
                'name' => $s['name'] ?: null,
                'personal_code' => $code,
                'context' => in_array($s['context'] ?? null, Contexts::ALL, true) ? $s['context'] : Contexts::UNKNOWN,
                'role' => $s['role'] ?? null,
                'organisation' => $s['organisation'] ?? null,
                'surface_forms' => $verified,
                'unverified_forms' => $unverified,
                'identifiers' => $identifiers,
                'subject_flags' => array_values(array_unique(array_filter($s['subject_flags'] ?? [], fn($f) => in_array($f, Flags::SUBJECT_FLAGS, true)))),
                'evidence' => $s['evidence'] ?? null,
                'confidence' => $s['confidence'] ?? null,
                'linked_metadata_initials' => $s['linked_metadata_initials'] ?? null,
            ];

            if ($verified === [] && $verifiedIdentifiers === []) {
                $dropped[] = $row;
                continue;
            }
            $kept[] = $row;
        }
        return [$kept, $dropped];
    }

    /**
     * Any valid personal code in the text that no subject accounts for
     * becomes an UNKNOWN subject, so nothing with a code goes unseen.
     */
    private function sweepPersonalCodes(array $subjects, Grounding $grounding): array
    {
        $known = [];
        foreach ($subjects as $s) {
            if ($s['personal_code']) {
                $known[$s['personal_code']] = true;
            }
            foreach ($s['surface_forms'] as $f) {
                foreach (PersonalCode::findAll($f['text']) as $c) {
                    $known[$c] = true;
                }
            }
        }
        foreach (PersonalCode::findAll($grounding->allText()) as $code) {
            if (isset($known[$code])) {
                continue;
            }
            $subjects[] = [
                'name' => null,
                'personal_code' => $code,
                'context' => Contexts::UNKNOWN,
                'role' => 'MENTIONED',
                'organisation' => null,
                'surface_forms' => [['text' => $code, 'in' => $grounding->find($code)]],
                'unverified_forms' => [],
                'identifiers' => [],
                'subject_flags' => [],
                'evidence' => 'Isikukood esineb tekstis, kuid mudel ei omistanud seda ühelegi isikule.',
                'confidence' => 'LOW',
                'linked_metadata_initials' => null,
            ];
        }
        return $subjects;
    }
}
