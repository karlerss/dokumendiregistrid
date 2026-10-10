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
 * The three-part test: purpose, necessity (per person), balancing, then what
 * happened (source changes, decisions and redactions from the logs), the
 * standing safeguards and an outcome. A source restriction is an input, not
 * a verdict. A passing assessment is the justification for a "keep"
 * decision; its conclusion is meant to be that decision's reason.
 */
class LegitimateInterest
{
    public const VERSION = 1;

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

    private const MATTER_LABELS = [
        'PUBLIC_CONSULTATION' => 'avalik konsultatsioon või kooskõlastus',
        'PERMIT_OR_LICENCE' => 'luba või litsents',
        'CONTRACT' => 'leping',
        'HR' => 'personaliasi',
        'SUPERVISION_OR_ENFORCEMENT' => 'järelevalve või täitemenetlus',
        'DISPUTE_OR_CHALLENGE' => 'vaie või vaidlus',
        'INFORMATION_REQUEST' => 'teabenõue või selgitustaotlus',
        'PROPERTY_NOTIFICATION' => 'kinnisvaraga seotud teavitus',
        'SYSTEM_ACCESS' => 'infosüsteemi juurdepääs',
        'COURT_CORRESPONDENCE' => 'kohtumenetlusega seotud kirjavahetus',
        'OTHER' => 'muu asutuse tegevus',
    ];

    private const INTEREST_LABELS = ['HIGH' => 'kõrge', 'MEDIUM' => 'keskmine', 'LOW' => 'madal'];

    /** Why naming these people is necessary (kept contexts). */
    private const KEEP_REASONS = [
        Contexts::PUBLIC_OFFICIAL_WORK_MATTER => 'Nimi ja ametikoht näitavad, kes asutuse nimel otsustas või suhtles. See on avaliku halduse läbipaistvuse sisu.',
        Contexts::REPRESENTING_LEGAL_ENTITY => 'Esindaja nimi näitab, kes ja kelle nimel asutusega suhtles.',
        Contexts::REGULATED_PROFESSIONAL => 'Reguleeritud kutsetegevus on avaliku usalduse alus ja tegija nimi on selle osa.',
        Contexts::NATURAL_PERSON_CONTRACT_PARTY => 'Avaliku raha kasutamise läbipaistvus eeldab, et lepingupool on tuvastatav.',
        Contexts::LICENCE_OR_CERTIFICATE_HOLDER => 'Kellele luba või sertifikaat on antud, on avalikkusele oluline teave.',
        Contexts::PUBLIC_FIGURE => 'Dokument puudutab avaliku elu tegelase avalikku rolli.',
    ];

    /** Why naming these people is not necessary (minimised contexts). */
    private const MINIMISE_REASONS = [
        Contexts::PRIVATE_PERSON_APPLICANT => 'Avalik huvi on asutuse tegevuse, mitte pöörduja isiku vastu.',
        Contexts::PRIVATE_PERSON_SUBJECT => 'Avalik huvi on asutuse otsuse ja selle põhjenduste, mitte menetlusaluse isiku vastu.',
        Contexts::PRIVATE_PERSON_THIRD_PARTY => 'Mainitud kolmas isik ei ole dokumendi avaliku huvi kandja.',
        Contexts::PUBLIC_OFFICIAL_HR_MATTER => 'Dokument puudutab ametniku teenistussuhet, mitte avaliku võimu teostamist.',
        Contexts::STUDENT_OR_INTERN => 'Õpilane või praktikant ei teosta avalikku võimu.',
        Contexts::UNKNOWN => 'Roll dokumendis ei ole teada, seega käsitletakse ettevaatuse põhimõttel eraisikuna.',
    ];

    private const IDENTIFIER_LABELS = [
        'EMAIL' => 'e-posti aadress', 'PHONE' => 'telefoninumber', 'POSTAL_ADDRESS' => 'postiaadress',
        'PROPERTY_CADASTRAL' => 'kinnistu andmed', 'IBAN' => 'pangakonto', 'BIRTH_DATE' => 'sünniaeg',
        'VEHICLE' => 'sõiduki andmed', 'CASE_NUMBER' => 'toimiku number', 'OTHER' => 'ametikoht või muu tunnus',
    ];

    private const FILE_ACTION_LABELS = [
        'REDACT_PDF' => 'redigeeritud',
        'CONVERT_AND_REDACT' => 'teisendatud ja redigeeritud',
        'RENDER_AND_REDACT' => 'teisendatud ja redigeeritud',
        'WITHHOLD_ORIGINAL' => 'avalikust juurdepääsust eemaldatud',
    ];

    /**
     * @return array{outcome: string, outcome_label: string, outcome_reason: string, sections: array<int, array{title: string, paragraphs: string[]}>, basis: array}|null
     *         null when the document has no assessment to build on
     */
    public function assess(Document $document): ?array
    {
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
        [$outcome, $reason] = $this->outcome($assessment, $kept, $minimised, $redaction, $redacted, $remote, $changes, $decision, $flags, $sensitive);

        return [
            'outcome' => $outcome,
            'outcome_label' => self::OUTCOME_LABELS[$outcome],
            'outcome_reason' => $reason,
            'sections' => [
                ['title' => '1. Eesmärk', 'paragraphs' => $this->purpose($document, $extraction, $remote)],
                ['title' => '2. Vajalikkus', 'paragraphs' => $this->necessity($kept, $minimised, $subjects, $redacted)],
                ['title' => '3. Kaalumine', 'paragraphs' => $this->balancing($kept, $minimised, $sensitive, $reid, $assessment, $remote, $changes, $redacted, $flags)],
                ['title' => '4. Toimingud', 'paragraphs' => $this->history($changes, $remote, $decision, $redaction, $minimised)],
                ['title' => '5. Kaitsemeetmed', 'paragraphs' => $this->safeguards()],
                ['title' => '6. Järeldus', 'paragraphs' => [self::OUTCOME_LABELS[$outcome] . '. ' . $reason]],
            ],
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

    private function outcome(PiiAssessment $assessment, Collection $kept, Collection $minimised, ?PiiRedaction $redaction, bool $redacted, ?DocumentRemoteState $remote, Collection $changes, ?ReviewItem $decision, array $flags, array $sensitive): array
    {
        $filesDone = $redacted && in_array($redaction->files_status, [PiiRedaction::STATUS_APPLIED, PiiRedaction::STATUS_SKIPPED], true);
        $kept_decision = $decision?->decision === ReviewItem::DECISION_KEEP;
        $sourceRestrictedPd = ($remote?->remote_status === DocumentRemoteState::STATUS_RESTRICTED && $remote->personal_data_restriction)
            || $changes->contains(fn($c) => $c->to_status === DocumentRemoteState::STATUS_RESTRICTED && $c->personal_data);
        // A personal code follows the name: kept people keep theirs. Only their private contacts are an issue.
        $privateLeft = !$redacted && $kept->contains(fn(PiiSubject $s) => collect($s->identifiers ?? [])->contains('nature', 'PRIVATE'));

        // Problems that block.
        if ($minimised->isNotEmpty() && !$redacted) {
            return [self::FAIL, 'Dokumendis on isikuid, kelle nime avaldamine ei ole vajalik, ja nende andmeid ei ole minimeeritud. Vajab otsust: redigeerimine või eemaldamine.'];
        }
        if ($redacted && !$filesDone) {
            return [self::FAIL, 'Tekst on redigeeritud, kuid failide redigeerimine ei õnnestunud täielikult. Failid võivad sisaldada minimeerimata andmeid.'];
        }

        // Things a person has to look at.
        if ($privateLeft && !$kept_decision) {
            return [self::NEEDS_REVIEW, 'Ametiülesandeid täitvate isikute või esindajate eraviisilised kontaktandmed on dokumendis avalikud. Need ei ole eesmärgi jaoks vajalikud. Vajab otsust nende eemaldamise kohta.'];
        }
        if (in_array(Flags::SCANNED_OR_IMAGE_CONTENT, $flags, true) && !$kept_decision) {
            return [self::NEEDS_REVIEW, 'Dokumendis on skaneeritud või pildisisu, mida tekstiredigeerimine ei pruugi katta. Vajab inimese kinnitust, et pildid ei sisalda minimeerimata andmeid.'];
        }
        if ($minimised->isEmpty() && $sensitive && !$redacted && !$kept_decision) {
            return [self::NEEDS_REVIEW, 'Dokumendis on ainult ametiülesandeid täitvad isikud, kuid sisus tuvastati tundlikke asjaolusid (' . implode(', ', $sensitive) . '). Vajab inimese kinnitust.'];
        }

        // Nothing left unresolved: keeping the document public is justified.
        if ($redacted) {
            return [self::PASS_REDACTED, ($sourceRestrictedPd
                    ? 'Allikas piiras dokumendi isikuandmete kaitseks ja vastuseks redigeeriti dokument: piirangu põhjuseks olnud isikuandmed on eemaldatud või asendatud. '
                    : ($minimised->isNotEmpty() ? 'Eraisikute isikuandmed on minimeeritud. ' : 'Dokument on redigeeritud. '))
                . 'Lahendamata probleeme ei ole. Avalikuks jääv sisu teenib avaliku halduse läbipaistvust ega riiva ülemääraselt isikute eraelu, seega on redigeeritud dokumendi avalikuks jätmine õigustatud.'];
        }
        if ($kept_decision) {
            return [self::PASS, 'Dokument vaadati läbi ja jäeti avalikuks põhjendusega: ' . rtrim($decision->decision_reason, '.') . '.'];
        }
        return [self::PASS, ($sourceRestrictedPd
                ? 'Allikas piiras dokumendi isikuandmete kaitseks, kuid dokumendis on ainult ametiülesandeid täitvad isikud või esindajad ning eraelu kahjustavat teavet ei tuvastatud. '
                : 'Dokumendis on ainult ametiülesandeid täitvad isikud või esindajad. ')
            . 'Avalikkuse huvi asutuse tegevuse vastu kaalub üles nende isikute huvi, seega on dokumendi avalikuks jätmine õigustatud.'];
    }

    private function purpose(Document $document, $extraction, ?DocumentRemoteState $remote): array
    {
        $paragraphs = [
            'Dokumendiregistrid.karlerss.com avaldab avaliku sektori asutuste dokumendiregistrites avalikuks tehtud dokumente ühtses otsitavas vormis. '
            . 'Eesmärk on avaliku halduse läbipaistvus ja avaliku teabe kättesaadavus (avaliku teabe seadus, § 1). See on töötleja ja avalikkuse õigustatud huvi.',
        ];
        $line = [];
        if ($extraction->matter && $extraction->matter !== 'OTHER' && isset(self::MATTER_LABELS[$extraction->matter])) {
            $line[] = 'Dokumendi liik: ' . self::MATTER_LABELS[$extraction->matter] . '.';
        }
        if ($this->isEstonian($extraction->summary)) {
            $line[] = 'Sisu: ' . rtrim($extraction->summary, '.') . '.';
        }
        if ($line) {
            $paragraphs[] = implode(' ', $line);
        }
        if ($extraction->public_interest) {
            $paragraphs[] = 'Avalik huvi: ' . (self::INTEREST_LABELS[$extraction->public_interest] ?? mb_strtolower($extraction->public_interest))
                . ($this->isEstonian($extraction->public_interest_reason) ? '. ' . rtrim($extraction->public_interest_reason, '.') . '.' : '.');
        }
        $paragraphs[] = "Allikas: {$document->organisation->name} avaldas dokumendi oma dokumendiregistris"
            . ($document->restriction ? " (juurdepääs: {$document->restriction})" : '') . '.';
        return $paragraphs;
    }

    private function necessity(Collection $kept, Collection $minimised, Collection $subjects, bool $redacted): array
    {
        if ($subjects->isEmpty()) {
            return ['Dokumendis ei tuvastatud füüsilisi isikuid.'];
        }
        $paragraphs = [];
        foreach ($kept->groupBy(fn(PiiSubject $s) => $s->effectiveContext()) as $context => $group) {
            $paragraphs[] = $this->group($group, fn(PiiSubject $s) => $s->name)
                . ' ' . (self::KEEP_REASONS[$context] ?? 'Isik tegutseb dokumendis avalikus rollis.');
        }
        foreach ($minimised->groupBy(fn(PiiSubject $s) => $s->effectiveContext()) as $context => $group) {
            $paragraphs[] = $this->group($group, fn(PiiSubject $s) => $this->initials($s))
                . ' ' . (self::MINIMISE_REASONS[$context] ?? self::MINIMISE_REASONS[Contexts::UNKNOWN])
                . ' Nime avaldamine ei ole eesmärgi jaoks vajalik: '
                . ($redacted
                    ? 'nimi on asendatud initsiaalidega ning isikukood, kontaktandmed ja kinnistu andmed on eemaldatud.'
                    : 'nimi tuleb asendada initsiaalidega ning isikukood, kontaktandmed ja kinnistu andmed eemaldada.');
        }
        return $paragraphs;
    }

    /** "Ametnik tööülesannetes (3): A, B, C." — the label with the people in it. */
    private function group(Collection $group, callable $name): string
    {
        // Never a personal code in the text: nameless people are counted, not shown.
        $unnamed = $group->filter(fn(PiiSubject $s) => !$s->name)->count();
        $named = $group->filter(fn(PiiSubject $s) => $s->name)->map($name)->unique()->values();
        $list = $named->implode(', ') . ($unnamed ? ($named->isNotEmpty() ? ' ja ' : '') . ($unnamed === 1 ? 'üks nimetu isik' : "{$unnamed} nimetut isikut") : '');
        return $group->first()->effectiveContextLabel() . ' (' . $group->count() . '): ' . $list . '.';
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
        $paragraphs = [];
        if (in_array(Flags::RESTRICTION_STAMP_PRESENT, $flags, true)) {
            $paragraphs[] = 'Dokumendi tekstis on asutusesiseseks kasutamiseks märge. See seob avaliku sektori asutusi, mitte kolmandaid isikuid. '
                . ($minimised->isEmpty()
                    ? 'Dokumendis ei ole eraisikuid, kelle andmeid märge kaitseks.'
                    : ($redacted ? 'Eraisikute andmed on redigeeritud.' : 'Eraisikute andmeid ei ole redigeeritud.'));
        }
        $pdChange = $changes->last(fn($c) => $c->to_status === DocumentRemoteState::STATUS_RESTRICTED && $c->personal_data);
        if ($pdChange || ($remote?->remote_status === DocumentRemoteState::STATUS_RESTRICTED && $remote->personal_data_restriction)) {
            $basis = $pdChange?->basis ?: $remote?->remote_restriction_basis;
            $paragraphs[] = 'Allikas on dokumendi hiljem piiranud isikuandmete kaitseks' . ($basis ? " ({$basis})" : '') . '. '
                . ($redacted
                    ? 'Piirangut on arvestatud: dokument on redigeeritud (vt punkt 4).'
                    : ($minimised->isEmpty()
                        ? 'Isikuandmete kontroll tuvastas dokumendis ainult ametiülesandeid täitvad isikud ja esindajad. Nende tegevus ametiülesannetes ei ole eraelu puutumatust kahjustav teave, seega ei kaalu piirang üles avalikkuse huvi asutuse tegevuse vastu.'
                        : 'Dokumendis on eraisikute andmeid, mida ei ole redigeeritud.'));
        }
        $categories = $this->categories($kept->merge($minimised));
        if ($categories) {
            $paragraphs[] = 'Töödeldavad isikuandmed: ' . implode(', ', $categories) . '.';
        }
        if ($kept->isNotEmpty()) {
            $paragraphs[] = 'Ametiülesandeid täitvad isikud ja esindajad võivad mõistlikult eeldada, et nende tegevus selles rollis on avalik. Sekkumine nende eraellu on väike.';
        }
        if ($minimised->isNotEmpty()) {
            $paragraphs[] = 'Eraisikud ei pruugi eeldada, et nende nimi on internetis otsitav. Seetõttu jääb nende kohta avalikuks ainult isikustamata sisu.';
        }
        if ($sensitive) {
            $paragraphs[] = 'Tundlikud asjaolud dokumendis: ' . implode(', ', $sensitive) . '.';
        }
        if ($reid) {
            $paragraphs[] = 'Tuvastatavust suurendavad: ' . implode(', ', $reid) . '.';
        }
        foreach ($assessment->fired_rules ?? [] as $rule) {
            if (str_starts_with($rule['id'] ?? '', 'HIGH_') && $rule['id'] !== 'HIGH_SOURCE_STAMP') {
                $paragraphs[] = 'Kõrgendatud risk: ' . mb_strtolower(mb_substr($rule['label'], 0, 1)) . mb_substr($rule['label'], 1) . '.';
            }
        }
        return $paragraphs;
    }

    /** What happened, from the logs: source changes, the decision and the redaction. */
    private function history(Collection $changes, ?DocumentRemoteState $remote, ?ReviewItem $decision, ?PiiRedaction $redaction, Collection $minimised): array
    {
        $paragraphs = [];
        foreach ($changes as $c) {
            $paragraphs[] = $c->occurred_at->format('d.m.Y') . ' allikas ' . match ($c->to_status) {
                DocumentRemoteState::STATUS_RESTRICTED => 'piiras juurdepääsu: ' . ($c->from_restriction ?: 'avalik') . ' → ' . ($c->to_restriction ?: 'piiratud')
                    . ($c->basis ? " ({$c->basis}" . ($c->personal_data ? ', isikuandmete kaitse' : '') . ')' : ''),
                DocumentRemoteState::STATUS_GONE => 'eemaldas dokumendi oma registrist',
                DocumentRemoteState::STATUS_PUBLIC => 'muutis dokumendi taas avalikuks',
                default => 'muutis dokumendi seisu',
            } . '.';
        }
        if ($changes->isEmpty() && $remote?->remote_status === DocumentRemoteState::STATUS_RESTRICTED) {
            $paragraphs[] = 'Allikas on dokumendile kehtestanud juurdepääsupiirangu'
                . ($remote->remote_restriction_basis ? " ({$remote->remote_restriction_basis})" : '') . '.';
        }
        if ($decision) {
            $paragraphs[] = $decision->decided_at->format('d.m.Y') . ' otsus: ' . match ($decision->decision) {
                ReviewItem::DECISION_REDACT => 'redigeerida',
                ReviewItem::DECISION_KEEP => 'jätta avalikuks',
                ReviewItem::DECISION_REMOVE => 'eemaldada',
                default => $decision->decision,
            } . ' (' . $this->actorLabel($decision->decided_by) . '). Põhjendus: ' . rtrim($decision->decision_reason, '.') . '.';
        }
        if ($redaction) {
            $files = collect($redaction->plan['files'] ?? [])->countBy('action')
                ->filter(fn($n, $action) => isset(self::FILE_ACTION_LABELS[$action]))
                ->map(fn($n, $action) => self::FILE_ACTION_LABELS[$action] . " {$n}")
                ->values()->all();
            $paragraphs[] = $redaction->created_at->format('d.m.Y') . ' redigeerimine: tekstis ' . count($redaction->plan['replacements'] ?? []) . ' asendust'
                . ($files ? '; failid: ' . implode(', ', $files) : '') . '. Seis: tekst ' . PiiRedaction::statusLabel($redaction->text_status)
                . ', failid ' . PiiRedaction::statusLabel($redaction->files_status) . '.';
        } elseif ($minimised->isNotEmpty()) {
            $paragraphs[] = 'Redigeerimist ei ole tehtud.';
        }
        return $paragraphs ?: ['Allika muudatusi ega otsuseid ei ole.'];
    }

    private function actorLabel(?string $actor): string
    {
        return match (true) {
            $actor === 'haldus' => 'administraator',
            $actor === 'haldus:pakett' => 'administraator, pakettotsus',
            str_starts_with((string)$actor, 'mcp') => 'administraator MCP kaudu',
            default => (string)$actor,
        };
    }

    private function safeguards(): array
    {
        return [
            'Allikaregistrit kontrollitakse korduvalt. Kui allikas piirab juurdepääsu või eemaldab dokumendi, märgitakse dokument ülevaatamiseks.',
            'Iga dokumendi lehel on eemaldamistaotluse vorm. Taotlused vaadatakse läbi ja otsused logitakse.',
            'Dokumendi või faili eemaldamisel kustutatakse ka kõik koopiad ja CDN-i vahemälu.',
        ];
    }

    /** Data categories present, without values. */
    private function categories(Collection $subjects): array
    {
        $out = $subjects->isNotEmpty() ? ['nimed'] : [];
        if ($subjects->contains(fn($s) => $s->personal_code)) {
            $out[] = 'isikukood';
        }
        foreach ($subjects as $s) {
            foreach ($s->identifiers ?? [] as $id) {
                $label = self::IDENTIFIER_LABELS[$id['type'] ?? 'OTHER'] ?? self::IDENTIFIER_LABELS['OTHER'];
                $out[] = (($id['nature'] ?? null) === 'PRIVATE' ? 'eraviisiline ' : '') . $label;
            }
        }
        return array_values(array_unique($out));
    }

    private function labels(array $flags): array
    {
        return array_values(array_unique(array_map(fn($f) => mb_strtolower(Flags::label($f)), $flags)));
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
