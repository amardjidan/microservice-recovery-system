<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    protected $fillable = ['service_id', 'started_at', 'ended_at', 'duration_sec', 'status', 'detected_by'];
    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime'];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
