<?php

namespace App\Console\Commands;

use App\Models\Incident;
use App\Models\ImpactedRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiscoverImpact extends Command
{
    protected $signature = 'app:discover-impact {incident_id} {--entity=default_incident_entity}';
    protected $description = 'Find records impacted by a resolved incident';

    public function handle()
    {
        $incident = Incident::findOrFail($this->argument('incident_id'));

        if ($incident->status !== 'resolved') {
            $this->error('Incident belum resolved, discovery dibatalkan.');
            return;
        }

        // Ambil konfigurasi, bukan hardcode nama tabel/kolom
        $entityKey = $this->option('entity');
        $config = config("discovery.{$entityKey}");

        if (!$config) {
            $this->error("Konfigurasi entity '{$entityKey}' tidak ditemukan di config/discovery.php");
            return;
        }

        $source = $config['source'];
        $target = $config['target'];

        $sourceRows = DB::connection($source['connection'])
            ->table($source['table'])
            ->whereBetween($source['time_column'], [$incident->started_at, $incident->ended_at])
            ->get();

        $missing = 0;
        $duplicate = 0;
        $inconsistent = 0;

        foreach ($sourceRows as $row) {
            $keyValue = $row->{$source['key_column']};

            $targetRows = DB::connection($target['connection'])
                ->table($target['table'])
                ->where($target['key_column'], $keyValue)
                ->get();

            if ($targetRows->isEmpty()) {
                $this->saveImpacted($incident, $keyValue, 'missing', $row, null);
                $missing++;
            } elseif ($targetRows->count() > 1) {
                $this->saveImpacted($incident, $keyValue, 'duplicate', $row, $targetRows->toArray());
                $duplicate++;
            } else {
                $targetRow = $targetRows->first();
                $sourceAmount = (int) $row->{$source['amount_column']};
                $targetAmount = (int) $targetRow->{$target['amount_column']};

                if ($sourceAmount !== $targetAmount) {
                    $this->saveImpacted($incident, $keyValue, 'inconsistent', $row, $targetRow);
                    $inconsistent++;
                }
            }
        }

        $this->info("Discovery selesai. Missing: {$missing}, Duplicate: {$duplicate}, Inconsistent: {$inconsistent}");
    }

    private function saveImpacted(Incident $incident, string $businessKey, string $category, $source, $target)
    {
        ImpactedRecord::updateOrCreate(
            [
                'incident_id' => $incident->id,
                'entity_type' => 'order',
                'business_key' => $businessKey,
            ],
            [
                'category' => $category,
                'source_snapshot' => (array) $source,
                'target_snapshot' => $target ? (array) $target : null,
                'status' => 'detected',
                'detected_at' => now(),
            ]
        );
    }
}