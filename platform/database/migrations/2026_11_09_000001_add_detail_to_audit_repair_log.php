<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('audit_repair_log', 'jenis')) {
            return;
        }

        Schema::table('audit_repair_log', function (Blueprint $table) {
            $table->text('jenis')->nullable();
            $table->integer('muncul_sebelum')->nullable();
            $table->integer('muncul_sesudah')->nullable();
            $table->integer('seharusnya')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audit_repair_log', function (Blueprint $table) {
            $table->dropColumn(['jenis', 'muncul_sebelum', 'muncul_sesudah', 'seharusnya']);
        });
    }
};