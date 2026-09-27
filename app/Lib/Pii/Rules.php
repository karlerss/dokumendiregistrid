<?php

namespace App\Lib\Pii;

use App\Models\PiiAssessment;
use App\Models\PiiExtraction;
use App\Models\PiiSubject;
use Illuminate\Support\Collection;

/**
 * Rules-based legitimate-interest assessment (pii_plan.md §4). Pure: takes
 * an extraction and its subjects (with admin overrides applied), returns the
 * band, recommendation, per-subject actions and the rules that fired.
 *
 * Advisory only. Nothing here touches documents.
 */
class Rules
{
    public const VERSION = 1;

    public const KEEP = 'KEEP';
    public const INITIALS = 'INITIALS';
    public const REMOVE = 'REMOVE';

    /**
     * @param Collection<int, PiiSubject> $subjects
     * @return array{band: string, recommendation: string, subject_actions: array, fired_rules: array}
     */
    public function assess(PiiExtraction $extraction, Collection $subjects): array
    {
        $fired = [];
        $actions = [];
        $docFlags = $extraction->flags ?? [];

        $initialsSubjects = [];
        foreach ($subjects as $subject) {
            $context = $subject->effectiveContext();
            $keep = Contexts::isKeep($context);
            $actions[(string)$subject->id] = [
                'context' => $context,
                'name' => $keep ? self::KEEP : self::INITIALS,
                'personal_code' => $keep ? self::KEEP : self::REMOVE,
                'private_contacts' => self::REMOVE,
                'work_contacts' => (in_array($context, Contexts::REMOVE_WORK_CONTACT, true) || !$keep) ? self::REMOVE : self::KEEP,
                'property_ids' => $keep ? self::KEEP : self::REMOVE,
            ];
            if (!$keep) {
                $initialsSubjects[] = $subject;
            }
        }

        if ($initialsSubjects === []) {
            $fired[] = $this->rule('INFO_ONLY_KEEP_CONTEXTS', 'Ainult ametnikud, esindajad või avaliku elu tegelased', ['subjects' => $subjects->count()]);
            return [
                'band' => PiiAssessment::BAND_INFO,
                'recommendation' => PiiAssessment::RECOMMEND_NONE,
                'subject_actions' => $actions,
                'fired_rules' => $fired,
            ];
        }

        $fired[] = $this->rule('WARN_PRIVATE_PERSON_PRESENT', 'Eraisik või muu initsiaalideks taandatav isik', [
            'subjects' => array_map(fn(PiiSubject $s) => $s->displayName() . ' (' . $s->effectiveContextLabel() . ')', $initialsSubjects),
        ]);
        $band = PiiAssessment::BAND_WARN;

        // Sensitivity alone stays WARN: once names, codes and identifiers are
        // gone, the narrative is anonymous. Sensitivity plus re-identification
        // is not.
        $sensitiveDoc = Flags::ofKind($docFlags, Flags::KIND_SENSITIVITY);
        $reidDoc = Flags::ofKind($docFlags, Flags::KIND_REID);
        foreach ($initialsSubjects as $s) {
            $sflags = $s->subject_flags ?? [];
            $reid = array_merge($reidDoc, Flags::ofKind($sflags, Flags::KIND_REID));
            $sensitive = array_merge($sensitiveDoc, Flags::ofKind($sflags, Flags::KIND_SENSITIVITY));
            if ($reid && $sensitive) {
                $fired[] = $this->rule('HIGH_REID_PLUS_SENSITIVE', 'Isik jääb tuvastatavaks ka pärast redigeerimist ja sisu on tundlik', [
                    'subject' => $s->displayName(), 'reid' => array_values(array_unique($reid)), 'sensitive' => array_values(array_unique($sensitive)),
                ]);
                $band = PiiAssessment::BAND_HIGH;
            }
            $vuln = array_intersect([Flags::MINOR_INVOLVED, Flags::VULNERABLE_PERSON], array_merge($docFlags, $sflags));
            if ($vuln) {
                $fired[] = $this->rule('HIGH_MINOR_OR_VULNERABLE', 'Alaealine või haavatav isik', ['subject' => $s->displayName(), 'flags' => array_values($vuln)]);
                $band = PiiAssessment::BAND_HIGH;
            }
            if ($s->effectiveContext() === Contexts::PUBLIC_OFFICIAL_HR_MATTER) {
                $fired[] = $this->rule('HIGH_HR_MATTER', 'Ametniku personaliasi', ['subject' => $s->displayName()]);
                $band = PiiAssessment::BAND_HIGH;
            }
        }

        if (in_array(Flags::RESTRICTION_STAMP_PRESENT, $docFlags, true)) {
            $fired[] = $this->rule('HIGH_SOURCE_STAMP', 'Allikas on dokumendi ise AK-märkega varustanud', ['basis' => $extraction->raw_response['restriction_stamp_basis'] ?? null]);
            $band = PiiAssessment::BAND_HIGH;
        }
        if (in_array(Flags::MULTIPLE_PRIVATE_PERSONS, $docFlags, true)) {
            $fired[] = $this->rule('HIGH_MULTIPLE_PRIVATE', 'Kolm või enam eraisikut', []);
            $band = PiiAssessment::BAND_HIGH;
        }
        if (in_array(Flags::SCANNED_OR_IMAGE_CONTENT, $docFlags, true)) {
            $fired[] = $this->rule('NOTE_SCANNED', 'Skaneeritud või pildisisu: tekstiredigeerimine ei pruugi olla täielik (ei mõjuta astet)', []);
        }

        return [
            'band' => $band,
            'recommendation' => $band === PiiAssessment::BAND_HIGH ? PiiAssessment::RECOMMEND_REVIEW_WITHHOLD : PiiAssessment::RECOMMEND_REDACT,
            'subject_actions' => $actions,
            'fired_rules' => $fired,
        ];
    }

    private function rule(string $id, string $label, array $inputs): array
    {
        return ['id' => $id, 'label' => $label, 'inputs' => $inputs];
    }
}
