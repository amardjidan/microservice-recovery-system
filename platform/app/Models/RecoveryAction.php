<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecoveryAction extends Model
{
    protected $fillable = [
        'impacted_record_id', 'action_type', 'idempotency_key',
        'is_dry_run', 'status', 'result', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'is_dry_run' => 'boolean',
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function impactedRecord()
    {
        return $this->belongsTo(ImpactedRecord::class);
    }
}