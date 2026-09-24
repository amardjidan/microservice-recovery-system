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
    Schema::create('incidents', function (Blueprint $table) {
        $table->id();
        $table->foreignId('service_id')->constrained()->cascadeOnDelete();
        $table->timestamp('started_at');
        $table->timestamp('ended_at')->nullable();
        $table->unsignedInteger('duration_sec')->nullable();
        $table->string('status')->default('open'); // open / resolved
        $table->string('detected_by')->default('health_check');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
