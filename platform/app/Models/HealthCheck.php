<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HealthCheck extends Model
{
    protected $fillable = ['service_id', 'status', 'http_code', 'latency_ms', 'error', 'checked_at'];
    protected $casts = ['checked_at' => 'datetime'];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}