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
        \Illuminate\Support\Facades\DB::statement('drop table if exists documents_fts;');

        \Illuminate\Support\Facades\DB::unprepared(<<<SQLITE
        create virtual table files_fts using fts5(contents, content='files', content_rowid='id', tokenize="unicode61");
        CREATE TRIGGER files_ai AFTER INSERT ON files
            BEGIN
                INSERT INTO files_fts (rowid, contents)
                VALUES (new.id, new.contents);
            END;

        CREATE TRIGGER files_ad AFTER DELETE ON files
            BEGIN
                INSERT INTO files_fts (files_fts, rowid, contents)
                VALUES ('delete', old.id, old.contents);
            END;

        CREATE TRIGGER files_au AFTER UPDATE ON files
            BEGIN
                INSERT INTO files_fts (files_fts, rowid, contents)
                VALUES ('delete', old.id, old.contents);
                INSERT INTO files_fts (rowid, contents)
                VALUES (new.id, new.contents);
            END;

        SQLITE
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared(<<<SQLITE
        drop trigger files_ai;
        drop trigger files_ad;
        drop trigger files_au;
        drop table files_fts;
        SQLITE);
    }
};
