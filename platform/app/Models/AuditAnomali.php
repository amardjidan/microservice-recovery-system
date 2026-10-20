<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Read-only: view audit_anomali (satu baris per referensi + scenario). */
class AuditAnomali extends Model
{
    protected $table = 'audit_anomali';

    // id dibuat di query (referensi:scenario) karena view tidak punya kunci tunggal
    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** audit_anomali.jenis bisa berakhiran keterangan; kode = kata pertama. */
    public function getKodeAttribute(): string
    {
        return explode(' ', (string) $this->jenis)[0];
    }
}
