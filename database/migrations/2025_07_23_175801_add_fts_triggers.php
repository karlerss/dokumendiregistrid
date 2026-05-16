<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::unprepared(<<<SQLITE
        --- create triggers for fts_documents
        CREATE TRIGGER documents_ai AFTER INSERT ON documents
            BEGIN
                INSERT INTO fts_documents (rowid, title, responsible, series, "to", function, original_id, reference, file_contents)
                VALUES (new.id, new.title, new.responsible, new.series, new."to", new.function, new.original_id, new.reference, new.file_contents);
            END;

        CREATE TRIGGER documents_ad AFTER DELETE ON documents
            BEGIN
                INSERT INTO fts_documents (fts_documents, rowid, title, responsible, series, "to", function, original_id, reference, file_contents)
                VALUES ('delete', old.id, old.title, old.responsible, old.series, old."to", old.function, old.original_id, old.reference, old.file_contents);
            END;

        CREATE TRIGGER documents_au AFTER UPDATE ON documents
            BEGIN
                INSERT INTO fts_documents (fts_documents, rowid, title, responsible, series, "to", function, original_id, reference, file_contents)
                VALUES ('delete', old.id, old.title, old.responsible, old.series, old."to", old.function, old.original_id, old.reference, old.file_contents);
                INSERT INTO fts_documents (rowid, title, responsible, series, "to", function, original_id, reference, file_contents)
                VALUES (new.id, new.title, new.responsible, new.series, new."to", new.function, new.original_id, new.reference, new.file_contents);
            END;

SQLITE
        );

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \Illuminate\Support\Facades\DB::unprepared(<<<SQLITE
        drop trigger documents_ai;
        drop trigger documents_ad;
        drop trigger documents_au;
        SQLITE
        );
    }
};
