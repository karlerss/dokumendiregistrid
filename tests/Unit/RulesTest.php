<?php

namespace Tests\Unit;

use App\Lib\Pii\Contexts;
use App\Lib\Pii\Flags;
use App\Lib\Pii\Rules;
use App\Models\PiiAssessment;
use App\Models\PiiExtraction;
use App\Models\PiiSubject;
use PHPUnit\Framework\TestCase;

class RulesTest extends TestCase
{
    private function subject(int $id, string $context, array $flags = [], ?string $override = null): PiiSubject
    {
        $s = new PiiSubject(['name' => "Isik $id", 'context' => $context, 'subject_flags' => $flags, 'context_override' => $override]);
        $s->id = $id;
        return $s;
    }

    private function extraction(array $flags = []): PiiExtraction
    {
        return new PiiExtraction(['flags' => $flags, 'raw_response' => []]);
    }

    public function test_only_keep_contexts_is_info(): void
    {
        $r = (new Rules())->assess($this->extraction([Flags::HEALTH]), collect([
            $this->subject(1, Contexts::PUBLIC_OFFICIAL_WORK_MATTER),
            $this->subject(2, Contexts::REPRESENTING_LEGAL_ENTITY),
        ]));
        $this->assertSame(PiiAssessment::BAND_INFO, $r['band']);
        $this->assertSame(PiiAssessment::RECOMMEND_NONE, $r['recommendation']);
        $this->assertSame(Rules::KEEP, $r['subject_actions']['1']['name']);
        $this->assertSame(Rules::KEEP, $r['subject_actions']['1']['personal_code']);
        $this->assertSame(Rules::REMOVE, $r['subject_actions']['1']['private_contacts']);
        $this->assertSame(Rules::KEEP, $r['subject_actions']['1']['work_contacts']);
    }

    public function test_private_person_is_warn_even_with_sensitivity_alone(): void
    {
        $r = (new Rules())->assess($this->extraction([Flags::POLITICAL_OR_RELIGIOUS_OPINION, Flags::FAMILY_CIRCUMSTANCES]), collect([
            $this->subject(1, Contexts::PRIVATE_PERSON_APPLICANT),
            $this->subject(2, Contexts::PUBLIC_OFFICIAL_WORK_MATTER),
        ]));
        $this->assertSame(PiiAssessment::BAND_WARN, $r['band']);
        $this->assertSame(PiiAssessment::RECOMMEND_REDACT, $r['recommendation']);
        $this->assertSame(Rules::INITIALS, $r['subject_actions']['1']['name']);
        $this->assertSame(Rules::REMOVE, $r['subject_actions']['1']['personal_code']);
        $this->assertSame(Rules::REMOVE, $r['subject_actions']['1']['property_ids']);
    }

    public function test_reidentification_plus_sensitivity_is_high(): void
    {
        $r = (new Rules())->assess($this->extraction([Flags::HOME_LOCATION_DETAIL, Flags::HEALTH]), collect([
            $this->subject(1, Contexts::PRIVATE_PERSON_SUBJECT),
        ]));
        $this->assertSame(PiiAssessment::BAND_HIGH, $r['band']);
        $this->assertSame(PiiAssessment::RECOMMEND_REVIEW_WITHHOLD, $r['recommendation']);
        $this->assertContains('HIGH_REID_PLUS_SENSITIVE', array_column($r['fired_rules'], 'id'));
    }

    public function test_subject_level_flags_count_too(): void
    {
        $r = (new Rules())->assess($this->extraction([]), collect([
            $this->subject(1, Contexts::PRIVATE_PERSON_THIRD_PARTY, [Flags::MINOR_INVOLVED]),
        ]));
        $this->assertSame(PiiAssessment::BAND_HIGH, $r['band']);
        $this->assertContains('HIGH_MINOR_OR_VULNERABLE', array_column($r['fired_rules'], 'id'));
    }

    public function test_hr_matter_and_source_stamp_and_multiple_are_high(): void
    {
        $r = (new Rules())->assess($this->extraction([]), collect([$this->subject(1, Contexts::PUBLIC_OFFICIAL_HR_MATTER)]));
        $this->assertSame(PiiAssessment::BAND_HIGH, $r['band']);
        $this->assertSame(Rules::REMOVE, $r['subject_actions']['1']['work_contacts']);

        $r = (new Rules())->assess($this->extraction([Flags::RESTRICTION_STAMP_PRESENT]), collect([$this->subject(1, Contexts::PRIVATE_PERSON_APPLICANT)]));
        $this->assertSame(PiiAssessment::BAND_HIGH, $r['band']);

        $r = (new Rules())->assess($this->extraction([Flags::MULTIPLE_PRIVATE_PERSONS]), collect([$this->subject(1, Contexts::PRIVATE_PERSON_APPLICANT)]));
        $this->assertSame(PiiAssessment::BAND_HIGH, $r['band']);
    }

    public function test_scanned_flag_alone_does_not_change_band(): void
    {
        $r = (new Rules())->assess($this->extraction([Flags::SCANNED_OR_IMAGE_CONTENT]), collect([$this->subject(1, Contexts::PRIVATE_PERSON_APPLICANT)]));
        $this->assertSame(PiiAssessment::BAND_WARN, $r['band']);
        $this->assertContains('NOTE_SCANNED', array_column($r['fired_rules'], 'id'));
    }

    public function test_override_wins(): void
    {
        $r = (new Rules())->assess($this->extraction([]), collect([
            $this->subject(1, Contexts::PRIVATE_PERSON_APPLICANT, [], Contexts::REPRESENTING_LEGAL_ENTITY),
        ]));
        $this->assertSame(PiiAssessment::BAND_INFO, $r['band']);
    }
}
