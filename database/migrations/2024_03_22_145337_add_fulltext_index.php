<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::statement('create virtual table documents_fts using fts5(title, ai_title, ai_summary, contents);');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
