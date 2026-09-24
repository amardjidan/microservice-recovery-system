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
    Schema::create('recovery_actions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('impacted_record_id')->constrained()->cascadeOnDelete();
        $table->string('action_type')->default('replay');
        $table->string('idempotency_key')->unique();
        $table->boolean('is_dry_run')->default(false);
        $table->string('status')->default('pending');
        $table->json('result')->nullable();
        $table->text('error')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recovery_actions');
    }
};
