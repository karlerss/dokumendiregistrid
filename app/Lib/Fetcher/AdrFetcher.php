<?php

namespace App\Lib\Fetcher;

use App\Lib\Parser\DirParser;
use App\Models\Document;
use App\Models\File;
use App\Models\Organisation;
use App\Models\Topic;
use Carbon\Carbon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class AdrFetcher extends BaseFetcher implements DateTypeBasedList
{

    private ?int $typeCount = null;

    private array $types = [];


    public function list(Carbon $date, $type = null): array
    {
        $this->getTypeCount();
        $res = [];
        for ($i = 1; $i < 6; $i++) {
            $resultsFromPage = $this->fetchPage($i, $date, 'Avalik', $type);
            $res = [
                ...$res,
                ...$resultsFromPage
            ];
            if (count($resultsFromPage) < 20) {
                break;
            }
        }

        for ($i = 1; $i < 6; $i++) {
            $resultsFromPage = $this->fetchPage($i, $date, 'AK', $type);
            $res = [
                ...$res,
                ...$resultsFromPage
            ];

            if (count($resultsFromPage) < 20) {
                break;
            }
        }

        return $res;
    }

    /**
     * @param int $id
     * @return Document
     */
    public function store(?int $id = null, Document $previous = null, array $rawData = null): Document
    {
        if ($rawData !== null) {
            throw new \Exception("Not implemented");
        }
        if ($id === null) {
            throw new \Exception("Id is null");
        }

        info("Called store for $id");
        $base = $this->organisation->registry_base_uri . 'dokument/';
        $docUrl = $base . $id;

        if ($doc = Document::query()->where('url', $docUrl)->first()) {
            return $doc;
        }


        [$data, $fileUrls, $relationUrls] = $this->getPageData($docUrl);

        if ($this->downloadFiles) {
            $files = $this->downloadFiles($id, $fileUrls);
        } else {
            $files = [];
        }

        [$props, $restrictionBases] = $this->getDocPropsFromData($id, $data);

        /** @var Document $document */
        $document = Document::query()->create($props);

        if ($relationCount = count($relationUrls)) {
            $relatedDocuments = [];
            info("Handling relationUrls ($relationCount) for $id");

            $relationIdsFromExistingRelations = array_map(function ($relation) {
                $relationId = explode('/', $relation);
                return intval($relationId[count($relationId) - 1]);
            }, $relationUrls);

            $topicId = Document::query()
                ->whereIn('original_id', $relationIdsFromExistingRelations)
                ->whereNotNull('topic_id')
                ->pluck('topic_id')
                ->first();

            if ($topicId) {
                $document->update(['topic_id' => $topicId]);
            }

            if (!$previous && !$topicId) {
                $document->update(['topic_id' => Topic::query()->create()->id]);
            } elseif (!$previous && $topicId) {
                $document->update(['topic_id' => $topicId]);
            } else {
                $document->update(['topic_id' => $previous->topic_id]);
            }

            foreach ($relationUrls as $relation) {
                $relationId = explode('/', $relation);
                $relationId = intval($relationId[count($relationId) - 1]);
                $relatedDocuments[] = $this->store($relationId, $document);
            }

            // get first topic id from related documents
            $topicId = Arr::first($relatedDocuments, fn(Document $d) => $d->topic_id !== null);
            if ($topicId) {
                $document->update(['topic_id' => $topicId->topic_id]);
            }

        }

        $document->files()->saveMany($files);
        foreach ($restrictionBases as $basis) {
            $document->restrictions()->create(['basis' => $basis]);
        }
        $document->ftsIndexSingle();

        return $document;
    }

    protected function getFullBaseUrl()
    {
        $url = $this->organisation->registry_base_uri;
        $url = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
        return trim($url, '/');
    }

    public function getPageData(string $docUrl)
    {
        $contents = $this->http()->get($docUrl)->body();

        $c = new Crawler($contents);


        $topTable = $c->filter('table.form');
        $topTableData = $this->handleTable($topTable);
        $data = [];
        foreach ($topTableData as $datum) {
            $data[str_replace(':', '', $datum[0])] = $datum[1] ?? null;
        }

        // todo: better!
        if (data_get($data, 'Juurdepääsupiirang') === 'Avalik') {
            $filePos = 0;
            $linksPos = 1;
        } else {
            $filePos = false;
            $linksPos = 0;
        }

        $fileLinks = [];

        // todo: make these absolute
        if ($filePos !== false) {
            $c->filter('table.data.hover')
                ->eq($filePos)
                ->filter('td:not(.subfile) a')->each(function (Crawler $link) use (&$fileLinks) {
                    $fileLinks[] = rtrim($this->getFullBaseUrl(), '/') . $link->attr('href');
                });
        }

        // todo: make these absolute
        $relationLinks = [];
        if ($relationsTable = $c->filter('table.data.hover')->eq($linksPos)) {
            $relationsTable->filter('td:first-child')
                ->each(function (Crawler $link) use (&$relationLinks) {
                    $relationLinks[] = rtrim($this->getFullBaseUrl(), '/') . $link->filter('a')->attr('href');
                });
        }

        return [$data, $fileLinks, $relationLinks];
    }

    /**
     * @param Crawler $tables
     * @param array $data
     * @param array $links
     * @return array
     */
    public function handleTable(Crawler $tables): array
    {
        $data = [];
        $links = [];


        $tables->each(function ($t) use (&$data, &$links) {
            $t->filter('tr')->each(function ($tr) use (&$data, &$links) {
                $cells = [];
                $tr->filter('td,th')->each(function ($td) use (&$cells, &$data, &$links) {
                    $cells[] = $td->text();
                    if ($td->filter('a')->count() > 0) {
                        $link = $td->filter('a')->attr('href');
                        $link = $this->getFullBaseUrl() . $link;
                        $cells[] = $link;
                        $links[] = $link;
                    }
                });
                $data[] = $cells;
            });
        });
        return $data;
    }

    /**
     * @param $data
     * @return void
     */
    public function getDocPropsFromData($id, $data): array
    {
        $mapping = [
            'Viit' => 'reference',
            'Registreerimise kpv' => 'registration_date',
            'Dokumendi liik' => 'type',
            'Pealkiri' => 'title',
            'Funktsioon' => 'function',
            'Sari' => 'series',
            'Toimik' => 'dossier',
            'Juurdepääsupiirang' => 'restriction',
            'Adressaat' => 'to',
            'Saabumis/saatmisviis' => 'method',
            'Vastutaja' => 'responsible',
        ];

        $out = [];

        foreach ($mapping as $from => $to) {
            $out[$to] = $data[$from] ?? null;
        }

        $restrictionBases = array_filter(explode(', ', $data['Juurdepääsupiirangu alus'] ?? ''));

        if (isset($data['Registreerimise kpv'])) {
            $out['registration_date'] = Carbon::parse($data['Registreerimise kpv']);
        }

        $out['original_id'] = $id;
        $out['organisation_id'] = $this->organisation->id;
        $out['url'] = $this->organisation->registry_base_uri . 'dokument/' . $id;

        return [$out, $restrictionBases];
    }

    private function fetchPage(int $page, Carbon $date, string $restriction = 'Avalik', $type = null): array
    {
        $params = [
            'title' => '',
            'regDateBegin' => $date->format('d.m.Y'),
            'regDateEnd' => $date->format('d.m.Y'),
            'party' => '',
            'senderRegNr' => '',
            'accessRestriction' => $restriction,
            'accessRestrictionReason' => '',
            'accessRestrictionBeginDate' => '',
            'accessRestrictionEndDate' => '',
            'pageNumber' => $page,
        ];

        $typeCount = $this->getTypeCount();

        $body = http_build_query($params, '', '&');

        $repeated = str_repeat('&_documentTypes=on', $typeCount);
        $body = str_replace('&party=', $repeated . '&party=', $body);

        $res = $this->http()
            ->withBody($body, 'application/x-www-form-urlencoded')
            ->post($this->organisation->registry_base_uri . 'otsing');

        $contents = $res->body();
        $crawler = new Crawler($contents);

        $items = [];

        $crawler->filter('table td:first-child a')->each(function (Crawler $item) use (&$items) {
            $parts = explode('/', $item->attr('href'));
            $items[] = intval($parts[count($parts) - 1]);
        });

        return $items;
    }

    /**
     * @return int|null
     */
    public function getTypeCount(): ?int
    {
        if ($this->typeCount !== null) {
            return $this->typeCount;
        }

        $contents = $this->http()
            ->get($this->organisation->registry_base_uri . 'otsing')
            ->body();

        $c = new Crawler($contents);

        $inputs = [];
        $documentTypes = [];
        $c->filter('input')->each(function (Crawler $item) use (&$inputs, &$documentTypes) {
            $name = $item->attr('name');
            $inputs[] = $name;
            if ($name === 'documentTypes') {
                $documentTypes[] = $item->attr('value');
            }
        });
        $this->types = $documentTypes;

        $filtered = array_filter($inputs, fn($i) => Str::contains($i, '_documentTypes'));

        $this->typeCount = count($filtered);

        return $this->typeCount;
    }

    static function getFetcherType(): string
    {
        return 'delta-adr';
    }
}
