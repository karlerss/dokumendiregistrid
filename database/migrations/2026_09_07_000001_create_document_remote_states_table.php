<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Admin-controlled flag; changes rarely, so it can live on documents
        // without the FTS update trigger becoming a cost.
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('visible')->default(true);
            $table->index('visible');
        });

        // Hot re-check state, one row per document that was public at ingest.
        // Kept separate from `documents` because every UPDATE there re-indexes
        // the row's full text via the fts_documents triggers.
        Schema::create('document_remote_states', function (Blueprint $table) {
            $table->foreignIdFor(\App\Models\Document::class)->primary()->constrained()->cascadeOnDelete();
            // public | restricted | gone; null = never checked successfully
            $table->string('remote_status', 16)->nullable();
            $table->string('remote_restriction')->nullable();
            $table->text('remote_restriction_basis')->nullable();
            $table->string('remote_restriction_change_basis')->nullable();
            $table->boolean('personal_data_restriction')->default(false);
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedSmallInteger('check_error_count')->default(0);
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->timestamps();

            $table->index('next_check_at');
            $table->index('remote_status');
        });

        // Keep hidden what the old `last_visibility = 'AK'` gate hides today.
        DB::statement("update documents set visible = 0 where last_visibility = 'AK'");

        // Seed state rows for every recheckable document, carrying over the
        // audit:full results where they exist:
        //  - AK      -> restricted
        //  - Avalik  -> public
        //  - Unknown -> never checked (it was only a transient error)
        DB::statement(<<<SQL
            insert into document_remote_states
                (document_id, remote_status, remote_restriction, remote_restriction_basis,
                 remote_restriction_change_basis, checked_at, next_check_at, created_at, updated_at)
            select id,
                   case last_visibility when 'AK' then 'restricted' when 'Avalik' then 'public' else null end,
                   case when last_visibility in ('AK', 'Avalik') then last_visibility else null end,
                   case when last_visibility in ('AK', 'Avalik') then last_reason else null end,
                   case when last_visibility in ('AK', 'Avalik') then last_reason_change else null end,
                   case when last_visibility in ('AK', 'Avalik') then last_audit_check_at else null end,
                   null,
                   current_timestamp,
                   current_timestamp
            from documents
            where restriction = 'Avalik'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('document_remote_states');
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['visible']);
            $table->dropColumn('visible');
        });
    }
};
