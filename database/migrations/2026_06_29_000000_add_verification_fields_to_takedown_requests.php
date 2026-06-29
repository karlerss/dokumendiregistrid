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
        Schema::table('takedown_requests', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->unique()->after('id');
            $table->string('verification_code', 6)->nullable()->after('status');
            $table->timestamp('verification_code_expires_at')->nullable()->after('verification_code');
            $table->timestamp('verified_at')->nullable()->after('verification_code_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('takedown_requests', function (Blueprint $table) {
            $table->dropColumn([
                'token',
                'verification_code',
                'verification_code_expires_at',
                'verified_at',
            ]);
        });
    }
};
