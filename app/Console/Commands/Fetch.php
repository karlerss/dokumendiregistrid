<?php

namespace App\Console\Commands;

use App\Lib\Fetcher\AdrFetcher;
use App\Lib\Fetcher\DateTypeBasedList;
use App\Lib\Fetcher\Enumeratable;
use App\Models\Organisation;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Fetch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fetch {orgId} {--date=} {--limit=} {--no-files} {--backwards} {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $orgId = $this->argument('orgId');

        /** @var Organisation $org */
        $org = Organisation::query()->findOrFail($orgId);
        $this->info("Fetching $org->name ($org->id)");
        $fetcher = $org->getFetcher();
        $this->info("Fetcher type: " . get_class($fetcher));

        if ($this->option('no-files')) {
            $fetcher->setDownloadFiles(false);
        }

        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $days = (int)($this->option('limit') ?? 7);

        if ($fetcher instanceof DateTypeBasedList) {
            while ($days > 0) {
                $this->info($date);
                foreach ($fetcher->list($date) as $id) {
                    try {
                        $this->info("Fetching $org->slug/$id");
                        $fetcher->store($id);
                    } catch (\Exception $e) {
                        $this->error($e->getMessage());
                        throw $e;
                    }
                }
                $date = $date->subDay();
                $days--;
            }
        } elseif ($fetcher instanceof Enumeratable) {
            if (!$this->option('backwards')) {
                $fetcher->enumerateForwards($fetcher->getCurrentMaxId(), callback: function (array $data) use ($fetcher) {
                    $doc = $fetcher->store(null, null, $data);
                    $this->info("Fetched " . $doc->url);
                });
            } else {
                if (!$this->option('start')) {
                    $this->error("Start id is required for backwards enumeration");
                    return;
                }
                $fetcher->enumerateBackwards($this->option('start'), $this->option('end') ?? 1, callback: function (array $data) use ($fetcher) {
                    try {
                        retry(3, function () use ($fetcher, $data) {
                            $doc = $fetcher->store(null, null, $data);
                            $this->info("Fetched " . $doc->url);
                        }, 5000);
                    } catch (\Exception $e) {
                        Log::error($e);
                    }
                });
            }
        } else {
            $this->error("Fetcher does not implement DateTypeBasedList or Enumeratable");
            return;
        }

        $org->last_fetched_at = now();
        $org->save();
    }
}
