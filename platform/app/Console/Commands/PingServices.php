<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\HealthCheck;
use App\Models\Incident;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class PingServices extends Command
{
    protected $signature = 'app:ping-services';
    protected $description = 'Ping all active services and record health status';

    public function handle()
    {
        $services = Service::where('is_active', true)->get();

        foreach ($services as $service) {
            $start = microtime(true);
            $status = 'down';
            $code = null;
            $error = null;

            try {
                $res = Http::timeout(3)->get($service->health_url);
                $status = $res->successful() ? 'up' : 'down';
                $code = $res->status();
            } catch (\Throwable $e) {
                $status = 'down';
                $error = $e->getMessage();
            }

            $latency = round((microtime(true) - $start) * 1000);

            HealthCheck::create([
                'service_id' => $service->id,
                'status' => $status,
                'http_code' => $code,
                'latency_ms' => $latency,
                'error' => $error,
                'checked_at' => now(),
            ]);

            $this->syncIncident($service, $status);

            $this->info("{$service->name}: {$status} ({$latency}ms)");
        }
    }

    private function syncIncident(Service $service, string $status)
    {
        $open = Incident::where('service_id', $service->id)
            ->where('status', 'open')
            ->first();

        if ($status === 'down' && !$open) {
            Incident::create([
                'service_id' => $service->id,
                'started_at' => now(),
                'status' => 'open',
                'detected_by' => 'health_check',
            ]);
            $this->warn("Incident opened for {$service->name}");
        }

        if ($status === 'up' && $open) {
            $open->update([
                'ended_at' => now(),
                'duration_sec' => abs(now()->diffInSeconds($open->started_at)),
                'status' => 'resolved',
            ]);
            $this->info("Incident resolved for {$service->name}, duration: {$open->duration_sec}s");
        }
    }
}