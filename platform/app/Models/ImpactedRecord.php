<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImpactedRecord extends Model
{
    protected $fillable = [
        'incident_id', 'entity_type', 'business_key', 'category',
        'source_snapshot', 'target_snapshot', 'status', 'detected_at',
    ];
    protected $casts = [
        'source_snapshot' => 'array',
        'target_snapshot' => 'array',
        'detected_at' => 'datetime',
    ];

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }
}