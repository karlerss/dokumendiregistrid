<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PII pipeline tables. All kept separate from `documents` so the pipeline's
 * writes never fire the fts_documents update trigger (see pii_plan.md §2).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pii_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Document::class)->constrained()->cascadeOnDelete();
            $table->string('model', 64);
            $table->unsignedSmallInteger('prompt_version');
            $table->unsignedSmallInteger('schema_version');
            // pending | done | failed | needs_ocr | too_large
            $table->string('status', 16)->default('pending');
            // 'worker' or the admin marker; admin-requested runs bypass the cap
            $table->string('requested_by', 32)->default('worker');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedInteger('input_chars')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedTinyInteger('chunks')->default(0);
            $table->string('matter', 40)->nullable();
            $table->text('summary')->nullable();
            $table->json('flags')->nullable();
            $table->string('public_interest', 8)->nullable();
            $table->text('public_interest_reason')->nullable();
            $table->json('legal_entities')->nullable();
            $table->json('raw_response')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'prompt_version']);
            $table->index(['status', 'queued_at']);
            $table->index('created_at');
        });

        Schema::create('pii_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extraction_id')->constrained('pii_extractions')->cascadeOnDelete();
            $table->foreignIdFor(\App\Models\Document::class)->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('personal_code', 11)->nullable();
            $table->string('context', 40);
            $table->string('role', 24)->nullable();
            $table->string('organisation')->nullable();
            $table->json('surface_forms')->nullable();
            $table->json('unverified_forms')->nullable();
            $table->json('identifiers')->nullable();
            $table->json('subject_flags')->nullable();
            $table->text('evidence')->nullable();
            $table->string('confidence', 8)->nullable();
            $table->string('linked_metadata_initials', 32)->nullable();
            $table->string('context_override', 40)->nullable();
            $table->text('override_note')->nullable();
            $table->string('overridden_by', 64)->nullable();
            $table->timestamp('overridden_at')->nullable();
            $table->timestamps();

            $table->index('document_id');
            $table->index('context');
        });

        Schema::create('pii_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Document::class)->constrained()->cascadeOnDelete();
            $table->foreignId('extraction_id')->constrained('pii_extractions')->cascadeOnDelete();
            $table->unsignedSmallInteger('rules_version');
            // INFO | WARN | HIGH
            $table->string('band', 8);
            // NONE | REDACT | REVIEW_WITHHOLD (advisory only)
            $table->string('recommendation', 24);
            $table->json('subject_actions')->nullable();
            $table->json('fired_rules')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by', 64)->nullable();
            // acknowledged | redacted | hidden | ignored
            $table->string('review_action', 24)->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->index(['document_id', 'rules_version']);
            $table->index(['band', 'reviewed_at']);
        });

        Schema::create('pii_redactions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Document::class)->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained('pii_assessments')->nullOnDelete();
            $table->string('applied_by', 64);
            $table->timestamp('applied_at')->nullable();
            $table->json('plan');
            // unredacted values, kept indefinitely, admin-only
            $table->json('before')->nullable();
            // pending | applied | failed
            $table->string('text_status', 16)->default('pending');
            // pending | applied | partially_applied | failed | skipped
            $table->string('files_status', 16)->default('pending');
            $table->json('log')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->string('reverted_by', 64)->nullable();
            $table->timestamps();

            $table->index('document_id');
        });

        Schema::table('files', function (Blueprint $table) {
            $table->string('redacted_location')->nullable();
            $table->string('original_private_location')->nullable();
            $table->boolean('original_withheld')->default(false);
            $table->timestamp('redacted_at')->nullable();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('redacted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', fn(Blueprint $t) => $t->dropColumn('redacted_at'));
        Schema::table('files', fn(Blueprint $t) => $t->dropColumn(['redacted_location', 'original_private_location', 'original_withheld', 'redacted_at']));
        Schema::dropIfExists('pii_redactions');
        Schema::dropIfExists('pii_assessments');
        Schema::dropIfExists('pii_subjects');
        Schema::dropIfExists('pii_extractions');
    }
};
