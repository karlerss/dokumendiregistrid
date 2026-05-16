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
        Schema::table('document_file', function (Blueprint $table) {
            $table->index('document_id');
            $table->index('file_id');
        });
        Schema::table('files', function (Blueprint $table) {
            $table->index('parent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_file', function (Blueprint $table) {
            $table->dropIndex('document_file_document_id_index');
            $table->dropIndex('document_file_file_id_index');
        });
    }
};
