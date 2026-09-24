<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = ['name', 'slug', 'health_url', 'check_interval_sec', 'is_active'];

    public function healthChecks()
    {
        return $this->hasMany(HealthCheck::class);
    }

    public function incidents()
    {
        return $this->hasMany(Incident::class);
    }
}
