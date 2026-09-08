<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Document::class)->constrained()->cascadeOnDelete();
            $table->timestamp('occurred_at');
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('from_restriction')->nullable();
            $table->string('to_restriction')->nullable();
            $table->text('basis')->nullable();
            $table->boolean('personal_data')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('action', 32)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['acknowledged_at', 'personal_data', 'occurred_at'], 'dsc_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_status_changes');
    }
};
