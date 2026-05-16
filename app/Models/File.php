<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class File extends Model
{
    use HasFactory;

    protected $guarded = [];

    public static function store(string $path): string
    {
        $name = basename($path);
        $rand = Str::random(32);
        Storage::disk('r2')->put("$rand/$name", file_get_contents($path));
        return "$rand/$name";
    }

    public function removeRemoteFile()
    {

    }

    public function getUrlAttribute()
    {
        return Storage::disk('r2')->url($this->location);
    }

    public function children()
    {
        return $this->hasMany(File::class, 'parent_id');
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function delete()
    {
        DB::transaction(function () {
            // Recursively delete children first
            foreach ($this->children as $child) {
                $child->delete();
            }
            $this->signatures()->delete();
            $location = $this->location;
            parent::delete();
            Storage::disk('r2')->delete($location);
        });
    }

    /**
     * Replace this file with a new uploaded file.
     * Deletes this file and its children (db + remote), parses the new file,
     * preserves the parent_id, and reindexes the document.
     *
     * @param string $newFilePath Path to the new file on disk
     * @return File[] The newly created files
     */
    public function replaceWith(string $newFilePath): array
    {
        $document = $this->document;
        $parentId = $this->parent_id;

        // Delete this file and all its children
        $this->delete();

        // Parse the new file using DirParser's logic
        $extension = strtolower(pathinfo($newFilePath, PATHINFO_EXTENSION));
        $parsed = match ($extension) {
            'asice', 'bdoc' => (new \App\Lib\Parser\AsiceParser($newFilePath))->parse($parentId),
            'eml' => (new \App\Lib\Parser\EmlParser($newFilePath))->parse($parentId),
            'rtf' => (new \App\Lib\Parser\RtfParser($newFilePath))->parse($parentId),
            'docx', 'doc' => (new \App\Lib\Parser\DocxParser($newFilePath))->parse($parentId),
            'txt' => (new \App\Lib\Parser\TxtParser($newFilePath))->parse($parentId),
            'pdf' => (new \App\Lib\Parser\PdfParser($newFilePath))->parse($parentId),
            'msg' => (new \App\Lib\Parser\MsgParser($newFilePath))->parse($parentId),
            default => (new \App\Lib\Parser\FileParser($newFilePath))->parse($parentId),
        };

        // Associate new files with the document
        $document->files()->saveMany($parsed);

        // Reindex the document FTS
        $document->ftsIndexSingle();

        return $parsed;
    }

    public function isImage()
    {
        $ext = $this->getExtension();
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp']);
    }

    public function getExtension(): string
    {
        return mb_strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }

}
