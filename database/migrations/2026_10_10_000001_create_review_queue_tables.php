<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Set by a "remove" decision. Adding a nullable column without a
        // default is a metadata-only change in SQLite, so this is instant
        // even on the production table.
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable();
        });

        Schema::create('review_items', function (Blueprint $table) {
            $table->id();
            // Null only for file-only items: an R2 object with no files row.
            $table->foreignIdFor(\App\Models\Document::class)->nullable()->constrained()->nullOnDelete();
            $table->string('file_key')->nullable();
            $table->string('status', 16)->default('open');
            $table->unsignedTinyInteger('priority');
            $table->json('flags');
            $table->string('decision', 16)->nullable();
            $table->text('decision_reason')->nullable();
            $table->string('decision_reference')->nullable();
            $table->string('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('first_flagged_at');
            $table->timestamps();

            $table->index(['status', 'priority', 'first_flagged_at']);
            $table->index('document_id');
            $table->index('file_key');
        });

        Schema::create('compliance_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_item_id')->nullable()->constrained('review_items')->nullOnDelete();
            // No foreign key: the log must outlive whatever it describes.
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('file_key')->nullable();
            $table->string('effect', 48);
            $table->string('actor');
            $table->json('result')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('review_item_id');
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_actions');
        Schema::dropIfExists('review_items');
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
