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
    Schema::create('impacted_records', function (Blueprint $table) {
        $table->id();
        $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
        $table->string('entity_type'); // contoh: 'order'
        $table->string('business_key'); // contoh: order_ref
        $table->string('category'); // missing / duplicate / inconsistent
        $table->json('source_snapshot')->nullable();
        $table->json('target_snapshot')->nullable();
        $table->string('status')->default('detected'); // detected/fixing/fixed/verified/failed/ignored
        $table->timestamp('detected_at');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('impacted_records');
    }
};
