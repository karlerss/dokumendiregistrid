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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Organisation::class)->constrained();

            $table->longText('ai_title')->nullable();
            $table->longText('ai_summary')->nullable();

            $table->string('url')->nullable();
            $table->string('original_id');

            $table->string('title');
            $table->string('reference');
            $table->date('registration_date');
            $table->string('type');

            $table->string('function')->nullable();
            $table->string('series')->nullable();
            $table->string('dossier')->nullable();

            $table->string('restriction');

            $table->string('to')->nullable();
            $table->string('method')->nullable();
            $table->string('responsible')->nullable();

            $table->timestamps();

            $table->longText('file_contents')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
