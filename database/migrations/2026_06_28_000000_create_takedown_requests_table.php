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
        Schema::create('takedown_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Document::class)->nullable()->constrained()->nullOnDelete();
            $table->string('original_document_url')->nullable();
            $table->string('status')->default('unverified');

            $table->string('author_name');
            $table->string('author_email');
            $table->string('legal_basis');
            $table->longText('objection_note')->nullable();
            $table->string('ip')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->longText('resolution_note')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('takedown_requests');
    }
};
