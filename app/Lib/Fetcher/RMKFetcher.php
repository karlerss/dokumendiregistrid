<?php

namespace App\Lib\Fetcher;

use App\Models\Document;
use Carbon\Carbon;
use function Laravel\Prompts\warning;

class RMKFetcher extends BaseFetcher implements Enumeratable
{

    /**
     * {
     * "status": true,
     * "data": [
     * {
     * "id": 400855,
     * "doc_headline": "Sagadi puupäevade abitööd",
     * "doc_number": "99",
     * "doc_proc_marker": "2-14",
     * "doc_series_id": "20",
     * "doc_date": null,
     * "doc_trans_date": "02.08.2025",
     * "unit_id": "10",
     * "doc_type": "CONTRACT",
     * "doc_trans_actor": "Füüsiline isik",
     * "ds_name": "Füüsiliste isikutega sõlmitud võlaõiguslikud /teenuse osutamise lepingud ja aktid",
     * "unit_name": "Külastuskorraldusosakond",
     * "doc_type_friendly": "Leping"
     * },
     */


    public function enumerateBackwards(int $maxId, int $minId = 1, callable $callback = null): void
    {
        // enumerates in descending order
        // check if not 404 and then pass contents to callback
        for ($i = $maxId; $i >= $minId; $i--) {
            $url = "https://adr.rmk.ee/api/dokument/$i";
            $res = $this->http()->get($url);
            if ($res->successful()) {
                $data = $res->json();
                if ($data['data'] === false) {
                    warning("No data for $i");
                    continue;
                }
                $callback($data);
            } else {
                warning("Failed to fetch $i");
            }
        }
    }

    public function enumerateForwards(int $minId, int $maxFailures = 30, callable $callback = null): void
    {
        // enumerates in ascending order
        // check if not 404 and then pass contents to callback
        $failures = 0;
        for ($i = $minId; $failures < $maxFailures; $i++) {
            $url = "https://adr.rmk.ee/api/dokument/$i";
            $res = $this->http()->get($url);
            if ($res->successful()) {
                $data = $res->json();
                if ($data['data'] === false) {
                    warning("No data for $i");
                    $failures++;
                    continue;
                }
                $failures = 0;
                $callback($data);
            } else {
                warning("Failed to fetch $i");
                $failures++;
            }
        }
    }

    /**
     * {
     * "status": true,
     * "data": {
     * "id": 2,
     * "doc_type": "LETTER",
     * "doc_series_id": "2",
     * "doc_number": "2177",
     * "doc_proc_marker": "3-1.1",
     * "doc_trans_date": "18.06.2020",
     * "doc_trans_actor": "Kaitsevägi",
     * "doc_kind": "Lihtkiri",
     * "doc_access": "PUBLIC",
     * "unit_id": "2",
     * "doc_headline": "Graafik",
     * "doc_validity": null,
     * "doc_enactment_date": null,
     * "doc_delivery_mode": "E-post",
     * "doc_fulfilment_note": "Registreeritud",
     * "doc_expiration_date": "18.07.2020",
     * "doc_signed_by": "",
     * "doc_date": "18.06.2020",
     * "doc_procurement_ref_number": null,
     * "doc_sender_post_code": "TVJ-3.1-2/20/20633",
     * "doc_direction": "INCOMING",
     * "doc_partners_contract_number": null,
     * "doc_restrict_desc": null,
     * "doc_is_deleted": null,
     * "created_at": null,
     * "updated_at": null,
     * "doc_reference": null,
     * "doc_status": "kehtiv",
     * "doc_proceeder": {
     * "wResponsibleUserId": "514",
     * "wResponsibleUser": "Ruth Rajaveer",
     * "wfPrimaryResponsibleUserId": "1020",
     * "wfPrimaryResponsibleUser": "Agu Palo",
     * "wfResolutionGiverId": null,
     * "wfResolutionGiver": ""
     * },
     * "unit_name": "Metsaosakond",
     * "ds_name": "Metsahalduse / Maakasutuse alane kirjavahetus",
     * "ds_code": "3-1.1",
     * "doc_direction_friendly": "\tSissetulev",
     * "doc_type_friendly": "Kiri",
     * "doc_access_friendly": "Avalik",
     * "doc_validity_friendly": null,
     * "failid": [
     * {
     * "id": 2,
     * "file_headline": "Graafik",
     * "file_name": "02_nursipalu_info_25.06-07.07.pdf",
     * "file_mime": "application/pdf",
     * "file_relation": "Dokumendi fail",
     * "file_access": "PUBLIC",
     * "file_restriction_description": ""
     * },
     * {
     * "id": 3,
     * "file_headline": "Nursipalu harjutusvälja infotahvli graafik 25.06-07.07.2020",
     * "file_name": "avalik 18.06.2020 tvj-3.1-22020633 nursipalu harjutusvälja infotahvli graafik 25.06-07.07.2020  ....pdf",
     * "file_mime": "application/pdf",
     * "file_relation": "Lisaksolemise seos (Peadokument)",
     * "file_access": "PUBLIC",
     * "file_restriction_description": ""
     * }
     * ]
     * },
     * "message": "Andmed laetud"
     * }
     */
    public function store(?int $id = null, Document $previous = null, array $rawData = null): Document
    {
        if ($id && $rawData) {
            throw new \Exception("Id and rawData are mutually exclusive");
        }

        if (!$id && $rawData) {
            $data = $rawData;
            $docUrl = "https://adr.rmk.ee/dokument/" . $data['data']['id'];
            $id = $data['data']['id'];

            if ($doc = Document::query()->where('url', $docUrl)->first()) {
                return $doc;
            }

        } else {
            $docUrl = "https://adr.rmk.ee/dokument/$id";

            if ($doc = Document::query()->where('url', $docUrl)->first()) {
                return $doc;
            }

            $base = "https://adr.rmk.ee/api/dokument/%d";
            $url = sprintf($base, $id);
            $data = $this->http()->get($url)->json();

        }

        $fileBase = "https://adr.rmk.ee/api/fail/%d";

        if ($data['data'] === false) {
            throw new \Exception("No data for $id");
        }

        info("Processing $docUrl");

        $publicFiles = array_filter(is_array($data['data']['failid']) ? $data['data']['failid'] : [], function ($file) {
            return $file['file_access'] === 'PUBLIC';
        });

        // Document is "Avalik" if doc_access is PUBLIC or if any file has PUBLIC access
        $hasPublicAccess = $data['data']['doc_access'] === 'PUBLIC' || count($publicFiles) > 0;

        $docProps = [
            'organisation_id' => $this->organisation->id,
            'url' => $docUrl,
            'original_id' => $id,
            'title' => $data['data']['doc_headline'],
            'reference' => $data['data']['doc_proc_marker'] . '/' . $data['data']['doc_number'],
            'registration_date' => Carbon::parse($data['data']['doc_trans_date']),
            'type' => $data['data']['doc_type_friendly'],
            'function' => $data['data']['ds_code'],
            'series' => $data['data']['ds_name'],
            'restriction' => $hasPublicAccess ? 'Avalik' : 'AK',
            'to' => $data['data']['doc_trans_actor'],
            'method' => $data['data']['doc_delivery_mode'],
            'responsible' => data_get($data, 'data.doc_proceeder.wResponsibleUser') ?? $data['data']['unit_name'],
        ];

        $fileUrls = array_map(function ($file) use ($fileBase) {
            return sprintf($fileBase, $file['id']);
        }, $publicFiles);

        $files = [];
        if ($this->downloadFiles) {
            info("Downloading files for $id");
            $files = $this->downloadFiles($id, $fileUrls);
            info("Downloaded files for $id");
        }

        /** @var Document $document */
        $document = Document::query()->updateOrCreate([
            'url' => $docUrl,
        ], $docProps);

        if ($data['data']['doc_restrict_desc']) {
            $document->restrictions()->create(['basis' => $data['data']['doc_restrict_desc']]);
        }

        $document->files()->saveMany($files);
        info("Saved files for $id");

        $document->ftsIndexSingle();

        info("Stored $id");

        return $document;
    }

    public function getCurrentMaxId(): int
    {
        return Document::query()->where('organisation_id', $this->organisation->id)->max('original_id') ?? 1;
    }

    static function getFetcherType(): string
    {
        return 'rmk';
    }
}
