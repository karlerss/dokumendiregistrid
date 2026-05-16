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
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location');
            $table->longText('contents')->nullable();
            $table->longText('html')->nullable();
            $table->string('parsed_with')->nullable();
            $table->foreignIdFor(\App\Models\File::class, 'parent_id')->nullable()->constrained('files');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('document_file', function (Blueprint $schema) {
            $schema->foreignIdFor(\App\Models\Document::class)->constrained();
            $schema->foreignIdFor(\App\Models\File::class)->constrained();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
