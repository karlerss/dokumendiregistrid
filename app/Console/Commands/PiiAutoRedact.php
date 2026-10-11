<?php

namespace App\Console\Commands;

use App\Lib\Compliance\AutoRedaction;
use Illuminate\Console\Command;

/**
 * Daily: redact documents whose HIGH or WARN assessment nobody reviewed in
 * time (config compliance.auto_redact_after_days). Scheduled in
 * routes/console.php; run by hand with --dry-run to see what is due.
 */
class PiiAutoRedact extends Command
{
    protected $signature = 'pii:auto-redact {--dry-run : List what is due and change nothing}';

    protected $description = 'Redact documents whose HIGH/WARN PII assessment was not reviewed within the configured number of days';

    public function handle(AutoRedaction $auto): int
    {
        $result = $auto->run(!$this->option('dry-run'));
        if ($result === null) {
            $this->info('Automaatne redigeerimine on välja lülitatud (compliance.auto_redact_after_days = 0).');
            return self::SUCCESS;
        }
        if ($result['rows']) {
            $this->table(['Dokument', 'Pealkiri', 'Tase', 'Asendusi', 'Failid', 'Tulemus'], $result['rows']);
        }
        $c = $result['counts'];
        $this->info(sprintf('%s: %d redigeeritakse, %d jäetud vahele, %d vigu.',
            $this->option('dry-run') ? 'Kuivkäivitus' : 'Tehtud', $c['redact'], $c['skipped'], $c['failed']));
        return $c['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
