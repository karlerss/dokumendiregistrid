<?php

namespace App\Console\Commands;

use App\Lib\Compliance\BatchRedaction;
use Illuminate\Console\Command;

/**
 * One-off batch decision: redact every document whose current PII
 * assessment is in the chosen band(s) (HIGH by default; --band=WARN or
 * both), using that assessment's plan. Each document still gets its own
 * recorded "redact" decision; the redaction runs on the queue worker.
 * Dry run unless --apply. Same logic as the /haldus/isikuandmed button.
 */
class ComplianceRedactHighRisk extends Command
{
    protected $signature = 'compliance:redact-high-risk
        {--apply : Carry out the redactions (default: dry run)}
        {--reason=' . BatchRedaction::DEFAULT_REASON . ' : Reason recorded on every decision}
        {--reference= : Optional reference recorded on every decision}
        {--band=* : Assessment band(s) to redact: HIGH (default), WARN, or both (--band=HIGH --band=WARN)}
        {--include-reviewed : Also redact documents whose assessment was already reviewed}
        {--limit=0 : Stop after this many redactions (0 = no limit)}';

    protected $description = 'Redact all documents whose current PII assessment is HIGH (or WARN with --band), as one recorded decision per document';

    public function handle(BatchRedaction $batch): int
    {
        $apply = (bool)$this->option('apply');
        $reason = trim((string)$this->option('reason'));
        if ($reason === '') {
            $this->error('A reason is required.');
            return self::FAILURE;
        }
        $bands = array_values(array_unique(array_map('strtoupper', $this->option('band') ?: [BatchRedaction::BANDS[0]])));
        if ($unknown = array_diff($bands, BatchRedaction::BANDS)) {
            $this->error('Unknown band: ' . implode(', ', $unknown) . ' (use HIGH and/or WARN).');
            return self::FAILURE;
        }
        $this->info('Tasemed: ' . implode(', ', $bands));

        $result = $batch->run($bands, $reason, $this->option('reference') ?: null, $apply,
            (bool)$this->option('include-reviewed'), (int)$this->option('limit'));
        $counts = $result['counts'];

        $this->table(['Dokument', 'Pealkiri', 'Tase', 'Asendusi', 'Failid', 'Tulemus'], $result['rows']);
        $this->info(sprintf('%s: %d redigeeritakse, %d jäetud vahele, %d vigu. Põhjus: „%s“',
            $apply ? 'Tehtud' : 'Kuivkäivitus (midagi ei muudetud; lisa --apply)',
            $counts['redact'], $counts['skipped'], $counts['failed'], $reason));
        if ($apply && $counts['redact']) {
            $this->line('Redigeerimised töötab läbi järjekorra töötaja; seis on näha dokumendi lehel.');
        }
        return $counts['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
