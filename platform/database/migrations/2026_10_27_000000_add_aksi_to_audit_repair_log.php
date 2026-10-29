<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_repair_log') && ! Schema::hasColumn('audit_repair_log', 'aksi')) {
            Schema::table('audit_repair_log', function (Blueprint $table) {
                $table->string('aksi', 10)->default('TAMBAH');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('audit_repair_log', 'aksi')) {
            Schema::table('audit_repair_log', fn (Blueprint $table) => $table->dropColumn('aksi'));
        }
    }
};