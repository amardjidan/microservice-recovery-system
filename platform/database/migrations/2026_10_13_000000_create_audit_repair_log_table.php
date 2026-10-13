<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_repair_log', function (Blueprint $table) {
            $table->id();
            $table->text('referensi')->index();
            $table->integer('scenario');
            $table->integer('idx');
            $table->text('coa_name');
            $table->decimal('debit', 20, 2);
            $table->decimal('credit', 20, 2);
            $table->text('dibuat_oleh');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_repair_log');
    }
};
