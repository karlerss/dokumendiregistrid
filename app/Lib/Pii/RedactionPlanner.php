<?php

namespace App\Lib\Pii;

use App\Models\Document;
use App\Models\File;
use App\Models\PiiAssessment;
use App\Models\PiiSubject;
use Illuminate\Support\Collection;

/**
 * Turns an assessment into a concrete, previewable plan: which strings get
 * replaced with what, and what happens to each original file. The plan is
 * stored on the redaction row and executed by Redactor.
 */
class RedactionPlanner
{
    public const ACTION_KEEP = 'KEEP';
    public const ACTION_REDACT_PDF = 'REDACT_PDF';
    public const ACTION_CONVERT_AND_REDACT = 'CONVERT_AND_REDACT';
    public const ACTION_REWRITE_TEXT = 'REWRITE_TEXT';
    public const ACTION_WITHHOLD = 'WITHHOLD_ORIGINAL';

    public const CODE_PLACEHOLDER = '[isikukood eemaldatud]';
    public const REMOVED_PLACEHOLDER = '[eemaldatud]';

    private const CONVERTIBLE = ['docx', 'doc', 'rtf', 'odt', 'xlsx', 'xls', 'ods', 'pptx'];

    /**
     * @param Collection<int, PiiSubject> $subjects
     */
    public function plan(Document $document, PiiAssessment $assessment, Collection $subjects): array
    {
        $document->loadMissing('files');

        $labels = $this->initialsLabels($subjects, $assessment);
        $replacements = [];
        $subjectPlan = [];

        foreach ($subjects as $subject) {
            $actions = $assessment->actionsFor($subject->id);
            if (!$actions) {
                continue;
            }
            $label = $labels[$subject->id] ?? null;
            $subjectPlan[(string)$subject->id] = [
                'name' => $subject->displayName(),
                'context' => $subject->effectiveContext(),
                'label' => $label,
                'actions' => $actions,
            ];

            foreach ($subject->verifiedForms() as $form) {
                $isCode = (bool)preg_match('/^\d{11}$/', preg_replace('/\s+/', '', $form)) && PersonalCode::isValid(preg_replace('/\D/', '', $form));
                if ($isCode) {
                    if ($actions['personal_code'] === Rules::REMOVE) {
                        $replacements[] = $this->replacement($subject->id, $form, self::CODE_PLACEHOLDER, 'personal_code');
                    }
                } elseif ($actions['name'] === Rules::INITIALS && $label) {
                    $replacements[] = $this->replacement($subject->id, $form, $label, 'name');
                }
            }

            foreach ($subject->identifiers ?? [] as $id) {
                if (empty($id['verified'])) {
                    continue;
                }
                $remove = match (true) {
                    ($id['type'] ?? '') === 'PROPERTY_CADASTRAL' => $actions['property_ids'] === Rules::REMOVE,
                    ($id['nature'] ?? 'UNKNOWN') === 'WORK' => $actions['work_contacts'] === Rules::REMOVE,
                    default => $actions['private_contacts'] === Rules::REMOVE,
                };
                if ($remove) {
                    $replacements[] = $this->replacement($subject->id, $id['value'], self::REMOVED_PLACEHOLDER, 'identifier');
                }
            }
        }

        // Longest first, so "Jaan Tamm" is replaced before "Tamm"; dedupe by surface.
        $bySurface = [];
        foreach ($replacements as $r) {
            $key = Grounding::normalize($r['surface']);
            if ($key === '' || isset($bySurface[$key])) {
                continue;
            }
            $bySurface[$key] = $r;
        }
        $replacements = array_values($bySurface);
        usort($replacements, fn($a, $b) => mb_strlen($b['surface']) <=> mb_strlen($a['surface']));

        return [
            'assessment_id' => $assessment->id,
            'rules_version' => $assessment->rules_version,
            'replacements' => $replacements,
            'subjects' => $subjectPlan,
            'files' => $this->fileActions($document, $replacements),
            'clear_ai_summary' => $replacements !== [],
        ];
    }

    private function replacement(int $subjectId, string $surface, string $replacement, string $kind): array
    {
        return ['subject_id' => $subjectId, 'surface' => $surface, 'replacement' => $replacement, 'kind' => $kind];
    }

    /**
     * "V. S." from "Valentina Sutt"; collisions get " (1)", " (2)".
     *
     * @return array<int, string|null>
     */
    private function initialsLabels(Collection $subjects, PiiAssessment $assessment): array
    {
        $labels = [];
        $counts = [];
        foreach ($subjects as $subject) {
            $actions = $assessment->actionsFor($subject->id);
            if (!$actions || $actions['name'] !== Rules::INITIALS || !$subject->name) {
                $labels[$subject->id] = null;
                continue;
            }
            $base = self::initials($subject->name);
            $counts[$base] = ($counts[$base] ?? 0) + 1;
            $labels[$subject->id] = $base;
        }
        $seen = [];
        foreach ($labels as $id => $label) {
            if ($label !== null && ($counts[$label] ?? 0) > 1) {
                $seen[$label] = ($seen[$label] ?? 0) + 1;
                $labels[$id] = $label . ' (' . $seen[$label] . ')';
            }
        }
        return $labels;
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/[\s\-]+/u', trim($name)) ?: [];
        $letters = [];
        foreach ($parts as $p) {
            $p = preg_replace('/[^\p{L}]/u', '', $p);
            if ($p !== '') {
                $letters[] = mb_strtoupper(mb_substr($p, 0, 1)) . '.';
            }
        }
        return implode(' ', $letters) ?: 'X.';
    }

    /**
     * Per-file action. Files whose text or name carry no replaced string are
     * kept, except: images are withheld whenever anything is redacted, and a
     * container is withheld when any file inside it is touched.
     *
     * @return array<string, array{name: string, action: string, reason: string}>
     */
    private function fileActions(Document $document, array $replacements): array
    {
        $actions = [];
        if ($replacements === []) {
            foreach ($document->files as $file) {
                $actions[(string)$file->id] = ['name' => $file->name, 'action' => self::ACTION_KEEP, 'reason' => 'midagi ei asendata'];
            }
            return $actions;
        }

        $surfaces = array_map(fn($r) => $r['surface'], $replacements);
        foreach ($document->files as $file) {
            /** @var File $file */
            $g = new Grounding(['body' => $file->contents, 'name' => $file->name, 'html' => Grounding::stripHtml($file->html)]);
            $touched = false;
            foreach ($surfaces as $s) {
                if ($g->occurs($s)) {
                    $touched = true;
                    break;
                }
            }
            $ext = $file->getExtension();
            $hasText = mb_strlen(trim((string)$file->contents)) >= PromptBuilder::MIN_FILE_CHARS;

            if ($file->isImage()) {
                $actions[(string)$file->id] = ['name' => $file->name, 'action' => self::ACTION_WITHHOLD, 'reason' => 'pilt: sisu ei saa kontrollida'];
                continue;
            }
            if (!$touched) {
                $actions[(string)$file->id] = ['name' => $file->name, 'action' => self::ACTION_KEEP, 'reason' => 'asendatavaid stringe ei leidu'];
                continue;
            }
            $actions[(string)$file->id] = match (true) {
                $ext === 'pdf' && $hasText => ['name' => $file->name, 'action' => self::ACTION_REDACT_PDF, 'reason' => 'PDF tekstikihiga'],
                $ext === 'pdf' => ['name' => $file->name, 'action' => self::ACTION_WITHHOLD, 'reason' => 'PDF ilma tekstikihita'],
                in_array($ext, self::CONVERTIBLE, true) => ['name' => $file->name, 'action' => self::ACTION_CONVERT_AND_REDACT, 'reason' => 'teisendatakse PDF-iks ja redigeeritakse'],
                $ext === 'txt' => ['name' => $file->name, 'action' => self::ACTION_REWRITE_TEXT, 'reason' => 'tekstifail kirjutatakse redigeerituna uuesti'],
                default => ['name' => $file->name, 'action' => self::ACTION_WITHHOLD, 'reason' => 'formaati ei saa redigeerida'],
            };
        }

        // Containers: withhold when any descendant is touched.
        $byParent = $document->files->groupBy('parent_id');
        $descendantTouched = function (int $fileId) use (&$descendantTouched, $byParent, &$actions): bool {
            foreach ($byParent->get($fileId, collect()) as $child) {
                if (($actions[(string)$child->id]['action'] ?? self::ACTION_KEEP) !== self::ACTION_KEEP || $descendantTouched($child->id)) {
                    return true;
                }
            }
            return false;
        };
        foreach ($document->files as $file) {
            if ($actions[(string)$file->id]['action'] === self::ACTION_KEEP && $descendantTouched($file->id)) {
                $actions[(string)$file->id] = ['name' => $file->name, 'action' => self::ACTION_WITHHOLD, 'reason' => 'sisaldab redigeeritavat faili'];
            }
        }
        return $actions;
    }

    /**
     * Apply replacements to a string with letter/digit boundaries, so "Tamm"
     * does not eat "Tammsaare". Inflected forms must be listed explicitly.
     */
    public static function replaceAll(?string $text, array $replacements): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        foreach ($replacements as $r) {
            $pattern = self::pattern($r['surface'], $r['kind'] ?? 'identifier');
            $text = preg_replace($pattern, $r['replacement'], $text);
        }
        return $text;
    }

    /**
     * Regex for a surface form: whitespace-tolerant, bounded by
     * non-alphanumerics. Name forms that are multi-word or reasonably long
     * also swallow a trailing run of letters, so Estonian case endings
     * ("Mari Maasikase", "Maasikasele") are covered without being listed.
     * Short single-word forms stay strict so "Tamm" cannot eat "Tammsaare".
     */
    public static function pattern(string $surface, string $kind = 'identifier'): string
    {
        $normalized = Grounding::normalize($surface);
        $parts = preg_split('/\s+/u', $normalized);
        $quoted = implode('\s+', array_map(fn($p) => preg_quote($p, '/'), $parts));
        $tolerant = $kind === 'name' && (count($parts) > 1 || mb_strlen($normalized) >= 6) && !preg_match('/\.$/u', $normalized);
        $right = $tolerant ? '\p{Ll}*(?![\p{L}\p{N}])' : '(?![\p{L}\p{N}])';
        return '/(?<![\p{L}\p{N}])' . $quoted . $right . '/u';
    }
}
