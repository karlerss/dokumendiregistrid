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
        Schema::create('restriction_bases', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Document::class)->constrained()->cascadeOnDelete();
            $table->string('basis')->nullable();
            $table->timestamps();
        });

        Schema::table('files', function (Blueprint $table) {
            $table->foreignIdFor(\App\Models\Document::class)->nullable()->constrained()->cascadeOnDelete();
        });

        // drop file table triggers files_ai, files_au, files_ad
        \Illuminate\Support\Facades\DB::unprepared(<<<SQLITE
        drop trigger files_ai;
        drop trigger files_ad;
        drop trigger files_au;
        drop table files_fts;
        SQLITE
        );

        \Illuminate\Support\Facades\DB::statement(<<<SQLITE
        with mapping as (select file_id, document_id from document_file)
        update files
        set document_id = (select document_id from mapping where files.id = mapping.file_id limit 1)
        SQLITE
        );
        Schema::dropIfExists('document_file');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restriction_bases');
    }
};
