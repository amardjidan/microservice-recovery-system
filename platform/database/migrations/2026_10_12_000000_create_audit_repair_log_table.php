<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_repair_log', function (Blueprint $t) {
            $t->id();
            $t->text('referensi')->index();
            $t->integer('scenario');
            $t->integer('idx');
            $t->text('coa_name');
            $t->decimal('debit', 20, 2);
            $t->decimal('credit', 20, 2);
            $t->text('dibuat_oleh');
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_repair_log');
    }
};
