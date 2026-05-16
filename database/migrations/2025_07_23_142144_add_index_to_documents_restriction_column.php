<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->index('restriction');
        });

        DB::statement('
            CREATE INDEX idx_documents_fast_query
            ON documents (restriction, registration_date, original_id)
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX idx_documents_fast_query');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_restriction_index');
        });
    }
};
