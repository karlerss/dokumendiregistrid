<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::unprepared(<<<SQLITE
        create virtual table fts_documents using fts5
        (
            title,
            responsible,
            series,
            "to",
            function,
            original_id,
            reference,
            file_contents,
            content='documents',
            content_rowid='id',
            tokenize="unicode61"
        );
        SQLITE
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
