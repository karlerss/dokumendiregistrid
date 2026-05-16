<?php

namespace App\Models;

use App\Lib\Fetcher\BaseFetcher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organisation extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function restrictedDocuments()
    {
        return $this->documents()->where('restriction', 'AK');
    }

    public function getFetcher(): BaseFetcher
    {
        switch ($this->fetcher_type) {
            case 'delta-adr':
                return new \App\Lib\Fetcher\AdrFetcher($this);
            case 'rmk':
                return new \App\Lib\Fetcher\RMKFetcher($this);
            case 'tallinn-atp':
                return new \App\Lib\Fetcher\TallinnFetcher($this);
            case 'riigikantselei-dhs':
                return new \App\Lib\Fetcher\RiigikantseleiFetcher($this);
            case 'riigikogu':
                return new \App\Lib\Fetcher\RiigikoguFetcher($this);
        }
    }
}
