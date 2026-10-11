<?php

namespace App\Lib\Pii;

use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
use App\Models\PiiAssessment;
use App\Models\PiiRedaction;
use App\Models\PiiSubject;
use App\Models\ReviewItem;
use Illuminate\Support\Collection;

/**
 * Legitimate interest assessment (GDPR art 6(1)(f)) for one document that
 * has been through the PII check, built from the stored extraction, its
 * assessment, the redaction state and the source status. Pure and
 * deterministic: the same inputs give the same text. Nothing is stored.
 *
 * The three cumulative conditions in C-13/16 Rīgas satiksme, structured as
 * in EDPB Guidelines 1/2024, version 1.0. Source changes, redactions and
 * safeguards are premises of the balancing, not separate procedural logs.
 */
class LegitimateInterest
{
    public const VERSION = 3;

    public const PASS = 'PASS';
    public const PASS_REDACTED = 'PASS_REDACTED';
    public const NEEDS_REVIEW = 'NEEDS_REVIEW';
    public const FAIL = 'FAIL';

    public const OUTCOME_LABELS = [
        self::PASS => 'Läbib',
        self::PASS_REDACTED => 'Läbib redigeerituna',
        self::NEEDS_REVIEW => 'Vajab inimese kinnitust',
        self::FAIL => 'Ei läbi',
    ];

    public const OUTCOME_COLORS = [
        self::PASS => 'green',
        self::PASS_REDACTED => 'green',
        self::NEEDS_REVIEW => 'orange',
        self::FAIL => 'red',
    ];

    // Presentation facts, reset on each assessment to preserve section signatures.
    private array $text = [];

    private const MATTER_LABELS = [
        'PUBLIC_CONSULTATION' => 'avalike otsuste ettevalmistamise ja kooskõlastamise kohta',
        'PERMIT_OR_LICENCE' => 'lubade andmise ja nende tingimuste kohta',
        'CONTRACT' => 'asutuse lepinguliste suhete ja kohustuste kohta',
        'HR' => 'asutuse personalikorralduse kohta',
        'SUPERVISION_OR_ENFORCEMENT' => 'järelevalve ja täitemenetluse läbiviimise kohta',
        'DISPUTE_OR_CHALLENGE' => 'asutuse otsuste vaidlustamise ja vaidluste lahendamise kohta',
        'INFORMATION_REQUEST' => 'asutuselt teabe küsimise ja teabe kättesaadavuse kohta',
        'PROPERTY_NOTIFICATION' => 'asutuse kinnisvaraga seotud asjaajamise kohta',
        'SYSTEM_ACCESS' => 'asutuse infosüsteemile juurdepääsu korraldamise kohta',
        'COURT_CORRESPONDENCE' => 'asutuse osalemise kohta kohtumenetluses',
        'OTHER' => 'dokumendis kajastatud avaliku halduse küsimuse kohta',
    ];

    private const INTEREST_LABELS = ['HIGH' => 'kõrge', 'MEDIUM' => 'keskmine', 'LOW' => 'madal'];

    /** Why naming these people is necessary (kept contexts). */
    private const KEEP_REASONS = [
        Contexts::PUBLIC_OFFICIAL_WORK_MATTER => 'Nime säilitamine võimaldab seostada dokumendis kajastatud ametitegevuse selle eest vastutava isikuga; üksnes ametinimetusest selleks ei piisa.',
        Contexts::REPRESENTING_LEGAL_ENTITY => 'Esindaja tuvastatavus näitab, kelle kaudu juriidiline isik selles asjas tegutses; üksnes organisatsiooni nime avaldamine jätaks esindustegevuse omistatavuse ebaselgeks.',
        Contexts::REGULATED_PROFESSIONAL => 'Nime säilitamine võimaldab omistada dokumendis kajastatud kutsetegevuse konkreetsele kutseisikule; kutse nimetamine üksi seda ei võimalda.',
        Contexts::NATURAL_PERSON_CONTRACT_PARTY => 'Lepingupoole tuvastatavus näitab, kellega asutus kohustused kokku leppis; nime eemaldamine jätaks avalikkusele teadmata tehingu teise poole.',
        Contexts::LICENCE_OR_CERTIFICATE_HOLDER => 'Nime säilitamine näitab, kellele dokumendis käsitletud luba või sertifikaat anti; üksnes loa tingimustest selleks ei piisa.',
        Contexts::PUBLIC_FIGURE => 'Nime säilitamine seob dokumendis käsitletud avaliku rolli selle kandjaga ja võimaldab hinnata tema osalemist avaliku elu korraldamises.',
    ];

    /** Why naming these people is not necessary (minimised contexts). */
    private const MINIMISE_REASONS = [
        Contexts::PRIVATE_PERSON_APPLICANT => 'Avalik huvi puudutab siin pöördumise käsitlemist, mille mõistmiseks ei ole pöörduja nime vaja.',
        Contexts::PRIVATE_PERSON_SUBJECT => 'Avalikkus saab tutvuda menetluse sisu ja põhjendustega ka menetluses puudutatud isiku nime teadmata.',
        Contexts::PRIVATE_PERSON_THIRD_PARTY => 'Kõrvalise isiku tuvastatavus ei lisa dokumendis käsitletud avaliku halduse küsimusele vajalikku teavet.',
        Contexts::PUBLIC_OFFICIAL_HR_MATTER => 'Avalik huvi personalikorralduse vastu on rahuldatav teenistussuhte sisu kaudu, avaldamata selle tõttu isiklikke asjaolusid nimeliselt.',
        Contexts::STUDENT_OR_INTERN => 'Õppe või praktika korralduse mõistmiseks ei ole selles dokumendis vaja õppija nime avaldada.',
        Contexts::UNKNOWN => 'Rolli kohta puudub nimelist avaldamist põhjendav teave, mistõttu avalikku huvi saab rahuldada nime avaldamata.',
    ];

    private const IDENTIFIER_LABELS = [
        'EMAIL' => 'e-posti aadress', 'PHONE' => 'telefoninumber', 'POSTAL_ADDRESS' => 'postiaadress',
        'PROPERTY_CADASTRAL' => 'kinnistu andmed', 'IBAN' => 'pangakonto', 'BIRTH_DATE' => 'sünniaeg',
        'VEHICLE' => 'sõiduki andmed', 'CASE_NUMBER' => 'toimiku number', 'OTHER' => 'ametikoht või muu ametialane tunnus',
    ];

    private const FILE_ACTION_LABELS = [
        'REDACT_PDF' => 'redigeerimine',
        'CONVERT_AND_REDACT' => 'teisendamine ja redigeerimine',
        'RENDER_AND_REDACT' => 'teisendamine ja redigeerimine',
        'WITHHOLD_ORIGINAL' => 'avalikust juurdepääsust eemaldamine',
    ];

    /**
     * @return array{outcome: string, outcome_label: string, outcome_reason: string, sections: array<int, array{title: string, paragraphs: string[]}>, basis: array}|null
     *         null when the document has no assessment to build on
     */
    public function assess(Document $document): ?array
    {
        $this->text = [];
        $assessment = $document->latestPiiAssessment;
        $extraction = $assessment?->extraction;
        if (!$assessment || !$extraction) {
            return null;
        }
        $subjects = $extraction->subjects()->orderBy('id')->get();
        $kept = $subjects->filter(fn(PiiSubject $s) => $s->isKeep());
        $minimised = $subjects->reject(fn(PiiSubject $s) => $s->isKeep());
        $redaction = PiiRedaction::query()->where('document_id', $document->id)->whereNull('reverted_at')->latest('id')->first();
        $remote = $document->remoteState;
        $flags = $extraction->flags ?? [];
        $sensitive = $this->labels(Flags::ofKind(array_merge($flags, ...$subjects->map(fn($s) => $s->subject_flags ?? [])->all()), Flags::KIND_SENSITIVITY));
        $reid = $this->labels(Flags::ofKind(array_merge($flags, ...$subjects->map(fn($s) => $s->subject_flags ?? [])->all()), Flags::KIND_REID));

        $redacted = $redaction && $redaction->text_status === PiiRedaction::STATUS_APPLIED;
        $changes = DocumentStatusChange::query()->where('document_id', $document->id)->orderBy('occurred_at')->get();
        $decision = ReviewItem::query()->where('document_id', $document->id)->where('status', ReviewItem::STATUS_DECIDED)
            ->latest('decided_at')->first();
        $this->text = ['interest' => self::MATTER_LABELS[$extraction->matter] ?? self::MATTER_LABELS['OTHER'],
            'redaction' => $redaction, 'decision' => $decision];
        [$outcome, $reason] = $this->outcome($assessment, $kept, $minimised, $redaction, $redacted, $remote, $changes, $decision, $flags, $sensitive);
        $this->text['outcome'] = $outcome;

        // Summaries and recorded reasons can repeat the extracted names and codes.
        $replacements = [];
        foreach ($minimised as $subject) {
            foreach (array_filter(array_merge([$subject->name], $subject->verifiedForms())) as $form) {
                $replacements[] = ['surface' => $form, 'replacement' => $this->initials($subject), 'kind' => 'name'];
            }
        }
        foreach ($subjects as $subject) {
            if ($subject->personal_code) {
                $replacements[] = ['surface' => $subject->personal_code, 'replacement' => '[isikukood eemaldatud]', 'kind' => 'personal_code'];
            }
        }
        usort($replacements, fn($a, $b) => mb_strlen($b['surface']) <=> mb_strlen($a['surface']));
        $format = fn(string $text) => preg_replace(['/(?<!\d)\d(?:[ \t-]?\d){10}(?!\d)/u', '/(?<=\p{Lu}\.)\.(?=\s|$)/u'],
            ['[isikukood eemaldatud]', ''], RedactionPlanner::replaceAll($text, $replacements));
        $sections = [
            ['title' => '1. Õigustatud huvi', 'paragraphs' => $this->purpose($document, $extraction, $remote)],
            ['title' => '2. Vajalikkus', 'paragraphs' => $this->necessity($kept, $minimised, $subjects, $redacted)],
            ['title' => '3. Huvide kaalumine', 'paragraphs' => $this->balancing($kept, $minimised, $sensitive, $reid, $assessment, $remote, $changes, $redacted, $flags)],
            ['title' => '4. Järeldus', 'paragraphs' => [$reason]],
        ];
        foreach ($sections as &$section) {
            $section['paragraphs'] = array_map($format, $section['paragraphs']);
        }
        unset($section);

        return [
            'outcome' => $outcome,
            'outcome_label' => self::OUTCOME_LABELS[$outcome],
            'outcome_reason' => $format($reason),
            'sections' => $sections,
            'basis' => [
                'lia_version' => self::VERSION,
                'rules_version' => $assessment->rules_version,
                'extraction_id' => $extraction->id,
                'assessment_id' => $assessment->id,
                'redaction_id' => $redaction?->id,
                'band' => $assessment->band,
            ],
        ];
    }

    /**
     * The assessment as shown to the public: only for documents whose PII
     * assessment an admin has reviewed, and only when it justifies keeping
     * the document public.
     */
    public function forPublic(Document $document): ?array
    {
        if ($document->isRemoved() || !$document->latestPiiAssessment?->isReviewed()) {
            return null;
        }
        $lia = $this->assess($document);
        return $lia && in_array($lia['outcome'], [self::PASS, self::PASS_REDACTED], true) ? $lia : null;
    }

    private function outcome(PiiAssessment $assessment, Collection $kept, Collection $minimised, ?PiiRedaction $redaction, bool $redacted, ?DocumentRemoteState $remote, Collection $changes, ?ReviewItem $decision, array $flags, array $sensitive): array
    {
        $filesDone = $redacted && in_array($redaction->files_status, [PiiRedaction::STATUS_APPLIED, PiiRedaction::STATUS_SKIPPED], true);
        $kept_decision = $decision?->decision === ReviewItem::DECISION_KEEP;
        $sourceRestrictedPd = ($remote?->remote_status === DocumentRemoteState::STATUS_RESTRICTED && $remote->personal_data_restriction)
            || $changes->contains(fn($c) => $c->to_status === DocumentRemoteState::STATUS_RESTRICTED && $c->personal_data);
        // A personal code follows the name: kept people keep theirs. Only their private contacts are an issue.
        $privateLeft = !$redacted && $kept->contains(fn(PiiSubject $s) => collect($s->identifiers ?? [])->contains('nature', 'PRIVATE'));

        if ($minimised->isNotEmpty() && !$redacted) {
            return [self::FAIL, 'Dokumendis on isikuandmeid, mille avaldamine ei ole kirjeldatud avaliku huvi saavutamiseks vajalik, kuid teksti ei ole minimeeritud. IKÜM art 5 lg 1 p c ja art 6 lg 1 p f ei õigusta dokumendi avalikuks jätmist praegusel kujul. Avaldamise jätkamiseks tuleb need andmed redigeerida või dokument eemaldada.'];
        }
        if ($redacted && !$filesDone) {
            return [self::FAIL, 'Dokumendi teksti on redigeeritud, kuid failide töötlemise lõpetamist ei ole kinnitatud. Avalikuks jäetavates failides tuleb minimeerimine lõpule viia või need juurdepääsust eemaldada; seni ei ole kogu dokumendi avaldamine IKÜM art 6 lg 1 p f alusel põhjendatud.'];
        }
        if ($privateLeft && !$kept_decision) {
            return [self::NEEDS_REVIEW, 'Avalik huvi dokumendis kajastatud ameti- või esindustegevuse vastu põhjendab selles rollis tegutsevate isikute tuvastatavust, kuid ei nõua nende eraviisiliste kontakt- või muude eraeluliste andmete avaldamist. Läbivaatajal tuleb need andmed eemaldada või põhjendada nende konkreetne vajalikkus ja proportsionaalsus. Enne seda ei ole dokumendi avalikuks jätmine IKÜM art 6 lg 1 p f alusel piisavalt põhjendatud.'];
        }
        if (in_array(Flags::SCANNED_OR_IMAGE_CONTENT, $flags, true) && !$kept_decision) {
            return [self::NEEDS_REVIEW, 'Dokumendi skaneeritud või pildisisu tõttu ei ole avalikuks jäävate isikuandmete ulatus teksti põhjal selge. Läbivaataja peab kontrollima pildisisu ning kinnitama, et eesmärgiks mittevajalikud andmed on ka sealt eemaldatud. Alles seejärel saab põhjendada kogu dokumendi avalikuks jätmist IKÜM art 6 lg 1 p f alusel.'];
        }
        if ($minimised->isEmpty() && $sensitive && !$redacted && !$kept_decision) {
            return [self::NEEDS_REVIEW, 'Dokumendis tuvastatud tundlikud asjaolud (' . implode(', ', $sensitive) . ') vajavad eraldi sisulist kaalumist. Läbivaataja peab selgitama, keda need puudutavad, kas nende avaldamine on vajalik ning kas kohaldumisel on täidetud IKÜM art 9 või art 10 nõuded. Seni ei ole avalikuks jätmine üksnes IKÜM art 6 lg 1 p f alusel piisavalt põhjendatud.'];
        }

        $sourceBasis = $changes->last(fn($c) => $c->to_status === DocumentRemoteState::STATUS_RESTRICTED && $c->personal_data)?->basis ?: $remote?->remote_restriction_basis;
        $sourceText = $sourceRestrictedPd ? 'Allika isikuandmete kaitseks seatud piirangut'
            . ($sourceBasis ? " ({$sourceBasis})" : '') . ' on arvestatud ' . ($redacted ? 'redigeeritud sisu' : 'isikute rollide ja andmete laadi') . ' hindamisel. ' : '';
        $interest = 'Dokumendi avaldamine teenib avalikkuse huvi saada teavet ' . ($this->text['interest'] ?? self::MATTER_LABELS['OTHER']) . '. ';
        if ($redacted) {
            return [self::PASS_REDACTED, $interest . $sourceText
                . 'Rakendatud minimeerimine säilitab avalikku huvi teeniva sisu ja vähendab eraeluliste andmete avaldamise mõju. '
                . 'Töötleja hinnangul on sellises ulatuses avaldamine vajalik ning andmesubjektide huvid ega põhiõigused ja -vabadused ei kaalu koos rakendatud kaitsemeetmetega avalikku huvi üles, seega on redigeeritud dokumendi avalikuks jätmine õigustatud IKÜM art 6 lg 1 p f alusel.'];
        }
        if ($kept_decision) {
            return [self::PASS, $interest . $sourceText
                . 'Avaldamist toetab ka dokumendi sisulisel läbivaatamisel tehtud põhjendatud otsus jätta see avalikuks. '
                . 'Töötleja hinnangul on avaldatavad andmed selle huvi saavutamiseks vajalikud ning isikute huvid ega põhiõigused ja -vabadused ei kaalu avalikku huvi üles, seega on dokumendi avalikuks jätmine õigustatud IKÜM art 6 lg 1 p f alusel.'];
        }
        return [self::PASS, $interest . $sourceText . ($kept->isNotEmpty()
            ? 'Isikute tuvastatavus võimaldab seostada dokumendis kajastatud asjaajamise selle osaliste ja nende rollidega. Töötleja hinnangul on see vajalik ning rolliga seotud avaldamisootust ja kaitsemeetmeid arvestades ei kaalu isikute huvid ega põhiõigused ja -vabadused avalikku huvi üles, seega on dokumendi avalikuks jätmine õigustatud IKÜM art 6 lg 1 p f alusel.'
            : 'Füüsilisi isikuid ei tuvastatud. Seetõttu ei näita hinnangu aluseks olevad andmed isikuandmete kaitsega seotud takistust dokumendi avalikuks jätmisele.')];
    }

    private function purpose(Document $document, $extraction, ?DocumentRemoteState $remote): array
    {
        $paragraphs = [];
        $facts = array_filter([
            $this->isEstonian($extraction->summary) ? $this->sentence($extraction->summary) : null,
            $this->isEstonian($extraction->public_interest_reason) ? $this->sentence($extraction->public_interest_reason) : null,
        ]);
        $paragraphs[] = ($facts ? implode(' ', $facts) . ' ' : '')
            . 'Selle teabe avaldamine teenib veebilehte pidava eraisiku ja avalikkuse huvi saada ning levitada teavet '
            . (self::MATTER_LABELS[$extraction->matter] ?? self::MATTER_LABELS['OTHER']) . '. '
            . 'Huvi tugineb teabe- ja sõnavabadusele (põhiseaduse § 44 lg 1 ja § 45, EL põhiõiguste harta art 11) ning avaliku halduse läbipaistvuse eesmärgile (AvTS § 1).';
        $paragraphs[] = $document->organisation->name . ' avalikustas dokumendi oma dokumendiregistris. '
            . 'Taasavaldamine teenib sama läbipaistvuse eesmärki, milleks dokument registris avalikustati, kuid isikuandmete avaldamine vajab iseseisvat õiguslikku alust, milleks on IKÜM art 6 lg 1 p f.';
        if ($extraction->public_interest === 'LOW') {
            $paragraphs[] = 'Dokumendi vähene sisuline panus avalikku arutellu põhjendab isikuandmete avaldamist vaid kitsalt asja mõistmiseks vajalikus ulatuses.';
        }
        return $paragraphs;
    }

    private function necessity(Collection $kept, Collection $minimised, Collection $subjects, bool $redacted): array
    {
        $paragraphs = ['Avalikkuse huvi saada teavet ' . ($this->text['interest'] ?? self::MATTER_LABELS['OTHER'])
            . ' eeldab võimalust tutvuda dokumendi sisuga. Töötleja hinnangul ei võimalda kokkuvõte hinnata selle täpset sõnastust ega üksnes registriviide tagada sisu kättesaadavust allika muutumisel. Vähem riivava lahendusena piiritletakse avaldatavad isikuandmed allpool nende vajalikkuse järgi (IKÜM art 5 lg 1 p c, võimalikult väheste andmete kogumise põhimõte; C-252/21 Meta Platforms v Bundeskartellamt).'];
        if ($subjects->isEmpty()) {
            $paragraphs[] = 'Dokumendis ei tuvastatud füüsilisi isikuid, kelle andmete avaldamise vajalikkust tuleks eraldi põhjendada.';
            return $paragraphs;
        }
        foreach ($kept->merge($minimised)->groupBy(fn(PiiSubject $s) => $s->effectiveContext()) as $context => $group) {
            $keep = $group->first()->isKeep();
            $line = $this->group($group, fn(PiiSubject $s) => $keep ? $s->name : $this->initials($s));
            $line .= $this->participation($group);
            $categories = $this->categories($group);
            if ($categories) {
                $line .= ' ' . ($group->count() > 1 ? 'Nende' : 'Tema') . ' kohta on dokumendis märgitud ' . $this->join($categories) . '.';
            }
            $line .= ' ' . ($keep ? $this->namingReason($group) : (self::MINIMISE_REASONS[$context] ?? self::MINIMISE_REASONS[Contexts::UNKNOWN]));
            if ($keep && $group->contains(fn($s) => $s->personal_code)) {
                $line .= ' Isikukoodi käsitatakse nimega samaväärse tuvastamistunnusena ja selle säilitamist põhjendab sama roll.';
            }
            $work = $group->flatMap(fn($s) => $s->identifiers ?? [])->where('nature', 'WORK');
            if ($keep && $work->isNotEmpty()) {
                $line .= $this->workDataReason($work);
            }
            $private = $group->flatMap(fn($s) => $s->identifiers ?? [])->where('nature', 'PRIVATE');
            if (!$keep) {
                $line .= $redacted
                    ? ' Teksti on redigeeritud, et asendada nimed initsiaalidega ning eemaldada isikukoodid ja muud otsesed tuvastamistunnused, säilitades asja sisulise käsitluse.'
                    : ' Vajaliku minimeerimisena tuleb nimed asendada initsiaalidega ning isikukoodid ja muud otsesed tuvastamistunnused eemaldada.';
            } elseif ($private->isNotEmpty()) {
                $line .= $this->privateDataReason($group, $redacted);
            }
            $paragraphs[] = $line;
        }
        return $paragraphs;
    }

    /** Identify a context group in prose, counting unnamed subjects. */
    private function group(Collection $group, callable $name): string
    {
        $unnamed = $group->filter(fn(PiiSubject $s) => !$s->name)->count();
        $names = $group->filter(fn(PiiSubject $s) => $s->name)->map($name)->values()->all();
        if ($unnamed) {
            $names[] = $unnamed === 1 ? 'üks nimetu isik' : "{$unnamed} nimetut isikut";
        }
        $plural = $group->count() > 1;
        $role = match ($group->first()->effectiveContext()) {
            Contexts::PUBLIC_OFFICIAL_WORK_MATTER => 'ametiülesannetes',
            Contexts::REPRESENTING_LEGAL_ENTITY => $plural ? 'juriidiliste isikute esindajatena' : 'juriidilise isiku esindajana',
            Contexts::REGULATED_PROFESSIONAL => 'reguleeritud kutsetegevuses',
            Contexts::NATURAL_PERSON_CONTRACT_PARTY => $plural ? 'füüsilisest isikust lepingupooltena' : 'füüsilisest isikust lepingupoolena',
            Contexts::LICENCE_OR_CERTIFICATE_HOLDER => $plural ? 'loa või sertifikaadi omajatena' : 'loa või sertifikaadi omajana',
            Contexts::PUBLIC_FIGURE => $plural ? 'avaliku elu tegelastena' : 'avaliku elu tegelasena',
            Contexts::PRIVATE_PERSON_APPLICANT => $plural ? 'eraisikutest pöördujatena' : 'eraisikust pöördujana',
            Contexts::PRIVATE_PERSON_SUBJECT => $plural ? 'menetluses puudutatud eraisikutena' : 'menetluses puudutatud eraisikuna',
            Contexts::PRIVATE_PERSON_THIRD_PARTY => $plural ? 'kõrvaliste eraisikutena' : 'kõrvalise eraisikuna',
            Contexts::PUBLIC_OFFICIAL_HR_MATTER => $plural ? 'ametnikena personaliasjas' : 'ametnikuna personaliasjas',
            Contexts::STUDENT_OR_INTERN => $plural ? 'õpilaste või praktikantidena' : 'õpilase või praktikandina',
            default => 'määratlemata rollis',
        };
        return 'Dokumendis ' . (!$plural || $unnamed === $group->count() ? 'esineb ' : 'esinevad ') . $role . ' ' . rtrim($this->join($names), '.') . '.';
    }

    /** The extraction sometimes answers in English; only Estonian goes into the text. */
    private function isEstonian(?string $text): bool
    {
        if (!$text) {
            return false;
        }
        if (preg_match('/[õäöüšž]/iu', $text)) {
            return true;
        }
        $words = preg_split('/\W+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $english = count(array_intersect($words, ['the', 'of', 'and', 'to', 'for', 'with', 'a', 'is', 'concerns', 'which', 'by', 'its']));
        return $english < 2;
    }

    private function balancing(Collection $kept, Collection $minimised, array $sensitive, array $reid, PiiAssessment $assessment, ?DocumentRemoteState $remote, Collection $changes, bool $redacted, array $flags): array
    {
        $passing = $this->passing();
        $paragraphs = [];
        foreach ($kept->merge($minimised)->groupBy(fn(PiiSubject $s) => $s->effectiveContext()) as $context => $group) {
            $keep = $group->first()->isKeep();
            $line = $this->group($group, fn(PiiSubject $s) => $keep ? $s->name : $this->initials($s));
            $line .= ' ' . match ($context) {
                Contexts::PUBLIC_OFFICIAL_WORK_MATTER => 'Ametiülesannetes tegutsemisega kaasneb põhjendatud ootus, et avalikkus saab teada, kelle tegevust dokument kajastab.',
                Contexts::REPRESENTING_LEGAL_ENTITY => 'Juriidilise isiku tegevuses osalemine põhjendab ootust, et selles dokumendis kajastatud esindus- või juhtimisroll on avalik.',
                Contexts::REGULATED_PROFESSIONAL => 'Kutsealase vastutuse tõttu on põhjendatud ootus, et dokumendis kajastatud kutsetegevus on seostatav konkreetse kutseisikuga.',
                Contexts::NATURAL_PERSON_CONTRACT_PARTY => 'Asutusega lepingu sõlmimisel on põhjendatud ootus, et avalikkus saab teada tehingu poole ja asutuse võetud kohustused.',
                Contexts::LICENCE_OR_CERTIFICATE_HOLDER => 'Loa või sertifikaadi andmine põhjendab ootust, et avalikkusel on võimalik seostada antud õigus selle omajaga.',
                Contexts::PUBLIC_FIGURE => 'Avalikus elus osalemine põhjendab suuremat avaldamisootust just dokumendis kajastatud avaliku rolli suhtes; see roll suurendab ka avalikkuse teabehuvi kaalu (C-131/12 Google Spain).',
                Contexts::PUBLIC_OFFICIAL_HR_MATTER => 'Ametiseisund ei anna alust eeldada personaliasja eraeluliste üksikasjade laialdast levitamist; siin kaalub eraelu kaitse üles huvi avaldada neid nimeliselt.',
                Contexts::STUDENT_OR_INTERN => 'Õppes või praktikal osalemine ei põhjenda ootust, et isiklikud asjaolud tehakse laialdaselt leitavaks; asjaajamise avalikkus on saavutatav nimede avaldamata jätmisega.',
                Contexts::UNKNOWN => 'Teadmata rolli puhul ei saa eeldada ootust laialdaseks avaldamiseks; avalikku huvi teenib asja sisu avaldamine ilma nimeta.',
                default => 'Asutusega eraasjas suhtlemine ei põhjenda ootust, et isiku asjaolud muutuvad koondotsingus laialdaselt leitavaks; avalikku huvi teenib siin asja sisu, mitte eraisiku tuvastatavus.',
            };
            $private = $group->contains(fn($s) => collect($s->identifiers ?? [])->contains('nature', 'PRIVATE'));
            if ($keep) {
                $line .= ' ' . match ($context) {
                    Contexts::PUBLIC_OFFICIAL_WORK_MATTER => 'Ametitegevuse parem leitavus võib tuua kaasa rohkem tähelepanu, kuid seda riivet õigustab vajadus seostada asutuse tegevus vastutava isikuga.',
                    Contexts::REPRESENTING_LEGAL_ENTITY => 'Nime laiem leitavus puudutab siin esindus- või juhtimistegevust; selle tegevuse omistatavus kaalub üles rolliga seotud täiendava tähelepanu.',
                    Contexts::REGULATED_PROFESSIONAL => 'Kutsetegevuse koondotsingus leitavus võib suurendada isikule suunatud tähelepanu, kuid kutsealase vastutuse jälgitavus õigustab seda mõju.',
                    Contexts::NATURAL_PERSON_CONTRACT_PARTY => 'Koondotsing suurendab tehingupoole nähtavust, kuid avalikkuse huvi teada, kellega asutus kohustused kokku leppis, kaalub rolliga piirduva riive üles.',
                    Contexts::LICENCE_OR_CERTIFICATE_HOLDER => 'Suurem leitavus puudutab siin antud õiguse omajat; huvi teada, kellele asutus õiguse andis, põhjendab sellega kaasnevat tähelepanu.',
                    default => 'Koondotsing teeb selle avaliku tegevuse nähtavamaks; dokumendis käsitletud rolli avalik tähendus põhjendab sellest tulenevat täiendavat tähelepanu.',
                };
                if ($private) {
                    $line .= $redacted
                        ? ' Eraviisiliste tunnuste mõju vähendab rakendatud redigeerimine, säilitades avaliku rolli tuvastatavuse.'
                        : ($passing
                            ? ' Eraviisiliste andmete säilitamist on hinnatud ka dokumendi sisulisel läbivaatamisel, mille põhjendust on arvestatud allpool.'
                            : ' Eraviisiliste tunnuste laiem levik võib aga mõjutada eraelu väljaspool seda rolli; enne avalikuks jätmist tuleb nende vajalikkus eraldi põhjendada või need eemaldada.');
                }
            } else {
                $line .= $redacted
                    ? ' Rakendatud minimeerimine vähendab otsest seost eraasja ja isiku vahel ning säilitab avaliku halduse küsimuse sisulise käsitluse.'
                    : ' Kuna vajalikku minimeerimist ei ole rakendatud, kaalub sellise laialdase tuvastatavuse mõju üles nimelise avaldamise huvi.';
            }
            $groupFlags = array_merge(...$group->map(fn($s) => $s->subject_flags ?? [])->all());
            $line .= $this->riskReason($groupFlags, $redacted, $keep);
            $paragraphs[] = $line;
        }
        $covered = array_merge(...$kept->merge($minimised)->map(fn($s) => $s->subject_flags ?? [])->values()->all());
        $docRisks = array_values(array_diff(array_merge(Flags::ofKind($flags, Flags::KIND_SENSITIVITY), Flags::ofKind($flags, Flags::KIND_REID)), $covered));
        if ($docRisks) {
            $paragraphs[] = ltrim($this->riskReason($docRisks, $redacted, $minimised->isEmpty(), 'Dokumendi kui terviku puhul suurendavad kaitsevajadust'));
        }
        if ($kept->isNotEmpty() || $minimised->isNotEmpty()) {
            $paragraphs[] = 'Nende isikute eraelu ja isikuandmete kaitset (põhiseaduse § 26, EL põhiõiguste harta art 7 ja 8) on seega kaalutud dokumendi sisust tuleneva teabehuviga, arvestades avaldamise konteksti ja mõistlikke ootusi (IKÜM põhjenduspunkt 47). '
                . ($passing ? 'Kirjeldatud ulatuses jääb avalik teabehuvi ülekaalu.' : 'Eespool nimetatud lahendamata küsimused takistavad praegusel kujul avaldamise põhjendamist.');
        }
        if (in_array(Flags::RESTRICTION_STAMP_PRESENT, $flags, true)) {
            $paragraphs[] = 'Dokumendis olev asutusesiseseks kasutamiseks (AK) märge seob avaliku sektori asutusi ega laiene iseenesest kolmandast isikust taasavaldajale. '
                . ($minimised->isEmpty() ? ($kept->isEmpty() ? 'Füüsilisi isikuid ei tuvastatud; AK-märge üksi ei välista selle dokumendi avaldamist.'
                        : 'Minimeerimist vajavas eraisiku rollis isikuid ei tuvastatud, mistõttu AK-märge ei välista kirjeldatud avalike rollidega seotud andmete avaldamist.')
                    : ($redacted ? 'Eraisikute andmete kaitseks on rakendatud redigeerimine, mistõttu AK-märge ei takista minimeeritud sisu avaldamist.'
                        : 'Siin toetab piirang vajadust enne avaldamise jätkamist eraisikute andmeid minimeerida.'));
        }
        $paragraphs = array_merge($paragraphs, $this->history($changes, $remote, $this->text['decision'], $this->text['redaction'], $minimised));
        if (in_array(Flags::SCANNED_OR_IMAGE_CONTENT, $flags, true) && !$passing) {
            $paragraphs[] = 'Skaneeritud või pildina esitatud sisu vajab visuaalset läbivaatamist, et selgitada ka selles olevate isikuandmete ulatus ja minimeerimise piisavus.';
        }
        return array_merge($paragraphs, $this->safeguards());
    }

    /** Source restrictions and completed measures as premises of the balancing. */
    private function history(Collection $changes, ?DocumentRemoteState $remote, ?ReviewItem $decision, ?PiiRedaction $redaction, Collection $minimised): array
    {
        $paragraphs = [];
        $redacted = $redaction?->text_status === PiiRedaction::STATUS_APPLIED;
        $restricted = $changes->last(fn($c) => $c->to_status === DocumentRemoteState::STATUS_RESTRICTED);
        if ($restricted || $remote?->remote_status === DocumentRemoteState::STATUS_RESTRICTED) {
            $basis = $restricted?->basis ?: $remote?->remote_restriction_basis;
            $pd = $restricted?->personal_data ?? $remote?->personal_data_restriction;
            $line = ($restricted ? $restricted->occurred_at->format('d.m.Y') . ' tuvastati, et allikas oli piiranud' : 'Allikas on piiranud')
                . ' dokumendile juurdepääsu' . ($basis ? " {$basis} alusel." : '; piirangu täpne õiguslik alus ei ole teada.');
            if ($pd) {
                $line .= ' Piirang väljendab allika hinnangut isikuandmete kaitse vajadusele.';
                $line .= $redacted
                    ? ' Sellele kaitsevajadusele vastab siin algversiooni asemel redigeeritud sisu avaldamine: vähendatud on eraelu riivet, säilitades teabe avaliku halduse kohta.'
                    : ($this->passing()
                        ? ' Dokumendi sisu ja andmete laadi arvestades ei kaalu piirang töötleja hinnangul üles eespool põhjendatud avaldamishuvi.'
                        : ' Allika hinnang tugevdab vajadust lahendada eespool kirjeldatud minimeerimise küsimused enne avalikuks jätmist.');
            } else {
                $line .= $this->passing()
                    ? ' Piirangut on arvestatud dokumendi avaldamise kontekstina; see ei muuda eespool hinnatud isikuandmete laadi ega nende avaldamise eesmärki.'
                    : ' Läbivaatajal tuleb selgitada, millist huvi see piirang kaitseb ja kas see mõjutab siin kavandatud avaldamist.';
            }
            $paragraphs[] = $line;
        }
        if ($remote?->remote_status === DocumentRemoteState::STATUS_GONE) {
            $paragraphs[] = 'Dokument ei ole allikaregistris enam kättesaadav, mistõttu siin säilitatav koopia omandab teabe kättesaadavuse seisukohalt suurema tähenduse. '
                . ($this->passing() ? 'Eespool kirjeldatud sisu ja andmete ulatust arvestades õigustab avalik huvi koopia säilitamist ka pärast allikast kadumist.'
                    : 'Allikast kadumine ei selgita eemaldamise põhjust; läbivaatajal tuleb hinnata selle tähendust koos tuvastatud andmekaitseküsimustega.');
        }
        if ($decision?->decision_reason) {
            $why = rtrim($this->sentence($decision->decision_reason), '.');
            if ($decision->decided_by === \App\Lib\Compliance\AutoRedaction::ACTOR) {
                // Not a human review: say what actually happened.
                $paragraphs[] = $decision->decided_at->format('d.m.Y') . ' rakendati dokumendile automaatselt isikuandmete hinnangu redigeerimisplaan, kuna kõrge või hoiatava tasemega hinnang jäi ettenähtud aja jooksul läbi vaatamata.'
                    . ($redacted ? ' Rakendatud redigeerimine vähendab avaldamisest tulenevat eraelu riivet.' : '');
            } else {
                $paragraphs[] = 'Dokumendi sisulisel läbivaatamisel ' . $decision->decided_at->format('d.m.Y') . ' otsustati '
                    . match ($decision->decision) {
                        ReviewItem::DECISION_KEEP => 'jätta see avalikuks',
                        ReviewItem::DECISION_REDACT => 'see redigeerida',
                        ReviewItem::DECISION_REMOVE => 'see eemaldada',
                        default => 'rakendada valitud meedet',
                    } . ' põhjendusega, et ' . mb_strtolower(mb_substr($why, 0, 1)) . mb_substr($why, 1) . '.'
                    . ($decision->decision === ReviewItem::DECISION_REDACT && $redacted ? ' Rakendatud redigeerimine vastab sellele kaitsevajadusele, vähendades avaldamisest tulenevat eraelu riivet.' : '');
            }
        }
        if ($redaction && $redacted) {
            $files = collect($redaction->plan['files'] ?? [])->pluck('action');
            if ($redaction->files_status === PiiRedaction::STATUS_APPLIED && $files->intersect(array_keys(self::FILE_ACTION_LABELS))->isNotEmpty()) {
                $paragraphs[] = 'Minimeerimine hõlmab lisaks veebilehe tekstile ka faile: '
                    . ($files->contains('WITHHOLD_ORIGINAL') ? 'redigeerimata originaale on avalikust juurdepääsust eemaldatud' : 'failides on rakendatud redigeerimine')
                    . ($files->contains('WITHHOLD_ORIGINAL') && $files->intersect(['REDACT_PDF', 'CONVERT_AND_REDACT', 'RENDER_AND_REDACT'])->isNotEmpty() ? ' ning avaldamiseks on loodud redigeeritud versioonid' : '')
                    . '. See vähendab võimalust saada eemaldatud andmeid sama dokumendi failide kaudu.';
            } elseif (!in_array($redaction->files_status, [PiiRedaction::STATUS_APPLIED, PiiRedaction::STATUS_SKIPPED], true)) {
                $paragraphs[] = 'Teksti redigeerimine on rakendatud, kuid failide töötlemise lõpetamist ei ole kinnitatud. Seetõttu ei saa veel järeldada, et veebilehe tekstist eemaldatud andmed pole failide kaudu kättesaadavad.';
            }
        }
        return $paragraphs;
    }

    private function safeguards(): array
    {
        return ['Avaldamise mõju piiravad ka võimalus esitada dokumendi lehel eemaldamistaotluse kaudu vastuväide (IKÜM art 21) ning allikaregistri korduv kontroll. Taotlused vaadatakse läbi ja otsused logitakse; allika piirang või eemaldamine suunab dokumendi ülevaatamisele.'];
    }

    private function passing(): bool
    {
        return in_array($this->text['outcome'] ?? null, [self::PASS, self::PASS_REDACTED], true);
    }

    private function join(array $parts): string
    {
        return collect($parts)->join(', ', ' ja ');
    }

    private function sentence(string $text): string
    {
        $text = preg_replace('/^\s*(?:[•*–—-]|\d+[.)])\s+/mu', '', trim($text));
        return rtrim(preg_replace('/\s+/u', ' ', $text), ". \n\r\t") . '.';
    }

    private function participation(Collection $group): string
    {
        $sentences = [];
        foreach ($group->groupBy(fn($s) => json_encode([$s->role, $s->organisation])) as $people) {
            $s = $people->first();
            $plural = $people->count() > 1;
            $role = match ($s->role) {
                'AUTHOR' => $plural ? 'dokumendi koostajad' : 'dokumendi koostaja',
                'ADDRESSEE' => $plural ? 'dokumendi adressaadid' : 'dokumendi adressaat',
                'SIGNATORY' => $plural ? 'dokumendi allkirjastajad' : 'dokumendi allkirjastaja',
                'SUBJECT_OF_DECISION' => $plural ? 'otsusest puudutatud isikud' : 'otsusest puudutatud isik',
                'REPRESENTATIVE' => $plural ? 'esindajad' : 'esindaja',
                'MENTIONED' => 'dokumendis mainitud',
                default => null,
            };
            if (!$role && !$s->organisation) {
                continue;
            }
            $names = $people->filter(fn($p) => $p->name)->map(fn($p) => $p->isKeep() ? $p->name : $this->initials($p))->all();
            $unnamed = $people->filter(fn($p) => !$p->name)->count();
            if ($unnamed) {
                $names[] = $unnamed === 1 ? 'üks nimetu isik' : "{$unnamed} nimetut isikut";
            }
            $who = $people->count() === $group->count() ? ($plural ? 'Nad' : 'Ta') : $this->join($names);
            $org = $s->organisation ? ($s->isKeep()
                ? ($plural ? 'tegutsevad' : 'tegutseb') . ' organisatsiooni ' . $s->organisation . ' nimel'
                : 'on seotud organisatsiooniga ' . $s->organisation) : null;
            $sentences[] = $who . ' ' . ($role ? 'on ' . $role . ($org ? ' ja ' . $org : '') : $org) . '.';
        }
        return $sentences ? ' ' . implode(' ', $sentences) : '';
    }

    private function namingReason(Collection $group): string
    {
        if ($group->every(fn($s) => $s->role === 'SUBJECT_OF_DECISION')) {
            return 'Nime säilitamine näitab, keda dokumendis tehtud otsus puudutab; üksnes organisatsiooni nimest ei selgu otsuse isikuline ulatus.';
        }
        if ($group->every(fn($s) => $s->role === 'SIGNATORY')) {
            return 'Allkirjastaja tuvastatavus näitab, kes vastutab dokumendis väljendatud tahteavalduse või kinnituse eest; üksnes ametinimetus seda ei näita.';
        }
        if ($group->every(fn($s) => $s->role === 'MENTIONED')) {
            return 'Nimi seob tekstis nimetatud isiku tema ameti- või avaliku rolliga ning võimaldab mõista, kelle tegevusele dokument osutab.';
        }
        return self::KEEP_REASONS[$group->first()->effectiveContext()];
    }

    private function workDataReason(Collection $identifiers): string
    {
        $reasons = [];
        foreach ($identifiers->pluck('type')->unique() as $type) {
            $reasons[] = match ($type) {
                'EMAIL', 'PHONE', 'POSTAL_ADDRESS' => 'Tööalased kontaktandmed seovad dokumendi asjaajamise ametliku suhtluskanaliga; nende säilitamine võimaldab mõista, kelle kaudu ja millises ametialases seoses asja aeti.',
                'PROPERTY_CADASTRAL', 'VEHICLE' => 'Asja tunnused võimaldavad täpselt tuvastada dokumendis käsitletud objekti; nende eemaldamine muudaks tehingu või otsuse ulatuse ebaselgeks.',
                'CASE_NUMBER' => 'Toimiku number võimaldab seostada dokumendi konkreetse menetlusega ja mõista selle menetluslikku konteksti.',
                'IBAN' => 'Tööalase pangakonto tunnus seob dokumendis käsitletud makse selle saajaga ja täpsustab rahalise kohustuse täitmist.',
                'BIRTH_DATE' => 'Sünniaeg täpsustab selles rollis nimetatud isiku samasust.',
                default => 'Tööalane lisatunnus täpsustab isiku ametialast seost dokumendis käsitletud tegevusega.',
            };
        }
        return ' ' . implode(' ', array_unique($reasons));
    }

    private function privateDataReason(Collection $group, bool $redacted): string
    {
        if (!$redacted && $this->passing()) {
            return ' Eraviisiliste tunnuste säilitamist põhjendab käesolevas asjas allpool toodud sisuline läbivaatamisotsus, mitte üksnes isiku avalik roll.';
        }
        $line = ' Eraviisiliste kontakt- ja muude eraeluliste tunnuste avaldamine ei ole selle rolli mõistmiseks vajalik.';
        if (!$redacted) {
            return $line . ' Need tuleb eemaldada või nende vajalikkus eraldi põhjendada.';
        }
        $removed = [];
        $replacements = $this->text['redaction']->plan['replacements'] ?? [];
        foreach ($group as $s) {
            foreach ($s->identifiers ?? [] as $id) {
                if (($id['nature'] ?? '') !== 'PRIVATE') {
                    continue;
                }
                if (collect($replacements)->contains(fn($r) => ($r['surface'] ?? null) === ($id['value'] ?? null) && !empty($r['surface'])
                    && ($r['replacement'] ?? null) !== $r['surface'])) {
                    $removed[] = self::IDENTIFIER_LABELS[$id['type'] ?? 'OTHER'] ?? self::IDENTIFIER_LABELS['OTHER'];
                }
            }
        }
        return $line . ($removed ? ' Rakendatud tekstiasendustega on eemaldatud ' . $this->join(array_values(array_unique($removed))) . '.'
            : ' Rakendatud redigeerimine vähendab eraeluliste andmete avaldamise ulatust.');
    }

    private function riskReason(array $flags, bool $redacted, bool $keep, string $lead = 'Kaitsevajadust suurendavad'): string
    {
        $sensitive = Flags::ofKind($flags, Flags::KIND_SENSITIVITY);
        $reid = Flags::ofKind($flags, Flags::KIND_REID);
        if (!$sensitive && !$reid) {
            return '';
        }
        $line = ' ' . $lead . ' tuvastatud asjaolud (' . $this->join($this->labels(array_merge($sensitive, $reid))) . ').';
        if (array_intersect($flags, [Flags::HEALTH, Flags::POLITICAL_OR_RELIGIOUS_OPINION, Flags::ETHNICITY_OR_LANGUAGE])) {
            $line .= ' Nende asjaoludega seotud eriliiki isikuandmete avaldamine eeldab lisaks IKÜM art 9 lg 2 kohast erandit; õigustatud huvi kaalumine seda ei asenda.';
            if (in_array(Flags::ETHNICITY_OR_LANGUAGE, $flags, true)) {
                $line .= ' Keeleandmed üksi ei ole automaatselt eriliiki andmed.';
            }
        }
        if (in_array(Flags::CRIMINAL_MATTER, $flags, true)) {
            $line .= ' Süüteoasjades süüdimõistvaid kohtuotsuseid, süütegusid või nendega seotud turvameetmeid käsitlevatele isikuandmetele kohalduvad lisaks IKÜM art 10 tingimused.';
        }
        if ($this->passing()) {
            $line .= $redacted
                ? ' Seda suuremat kaitsevajadust on arvestatud avaldamise ulatuse vähendamisega; töötleja hinnangul jääb redigeeritud sisu teabeväärtus üles kaaluma selle avaldamise mõju isikutele.'
                : ' Nende asjaolude kaalu on hinnatud koos dokumendis kirjeldatud rollidega; töötleja hinnangul kaalub dokumendi teabeväärtus kirjeldatud ulatuses üles avaldamise mõju isikutele.';
        } else {
            $line .= ' Läbivaatajal tuleb hinnata nende asjaolude seostatavust konkreetse isikuga ja võimalikku eraelu- või mainekahju.';
            if (array_intersect($flags, [Flags::HEALTH, Flags::POLITICAL_OR_RELIGIOUS_OPINION, Flags::ETHNICITY_OR_LANGUAGE, Flags::CRIMINAL_MATTER])) {
                $line .= ' Kohaldumisel tuleb tuvastada ka art 9 või art 10 nõuete täitmine.';
            }
            if ($reid && !$keep) {
                $line .= ' Initsiaalidest üksi ei piisa, kui isik jääb sündmuste või muude üksikasjade kaudu äratuntavaks.';
            }
        }
        return $line;
    }

    /** Data categories present, without values. */
    private function categories(Collection $subjects): array
    {
        $out = $subjects->contains(fn($s) => $s->name) ? ['nimi'] : [];
        if ($subjects->contains(fn($s) => $s->personal_code)) {
            $out[] = 'isikukood';
        }
        foreach ($subjects as $s) {
            foreach ($s->identifiers ?? [] as $id) {
                $label = self::IDENTIFIER_LABELS[$id['type'] ?? 'OTHER'] ?? self::IDENTIFIER_LABELS['OTHER'];
                $contact = in_array($id['type'] ?? null, ['EMAIL', 'PHONE', 'POSTAL_ADDRESS', 'IBAN'], true);
                $out[] = ($contact ? match ($id['nature'] ?? null) {
                    'PRIVATE' => 'eraviisiline ',
                    'WORK' => 'tööalane ',
                    default => '',
                } : '') . $label;
            }
        }
        return array_values(array_unique($out));
    }

    private function labels(array $flags): array
    {
        return array_values(array_unique(array_map(fn($f) => match ($f) {
            Flags::IDENTIFIABLE_BY_CONTEXT => 'tuvastatavus konteksti järgi',
            Flags::MINOR_INVOLVED => 'alaealisus',
            Flags::VULNERABLE_PERSON => 'haavatavus',
            default => mb_strtolower(Flags::label($f)),
        }, $flags)));
    }

    private function initials(PiiSubject $s): string
    {
        if (!$s->name) {
            return 'Nimetu isik';
        }
        $parts = preg_split('/[\s-]+/u', trim($s->name), -1, PREG_SPLIT_NO_EMPTY);
        return implode(' ', array_map(fn($p) => mb_strtoupper(mb_substr($p, 0, 1)) . '.', $parts));
    }
}
