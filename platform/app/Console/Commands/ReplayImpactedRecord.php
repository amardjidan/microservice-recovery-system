<?php

namespace App\Console\Commands;

use App\Models\ImpactedRecord;
use App\Models\RecoveryAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class ReplayImpactedRecord extends Command
{
    protected $signature = 'app:replay {impacted_record_id} {--dry-run}';
    protected $description = 'Replay a missing payment for an impacted order record';

    public function handle()
    {
        $record = ImpactedRecord::findOrFail($this->argument('impacted_record_id'));
        $isDryRun = $this->option('dry-run');

        $idempotencyKey = hash('sha256',
            $record->incident_id . $record->entity_type . $record->business_key . 'replay'
        );

                $existing = RecoveryAction::where('idempotency_key', $idempotencyKey)
            ->where('status', 'success')
            ->first();

        if ($existing) {
            $this->info('Sudah pernah sukses sebelumnya, tidak dieksekusi ulang.');
            return;
        }

                $paymentExists = DB::connection('payment_sim')
            ->table('payments')
            ->where('order_ref', $record->business_key)
            ->exists();

        if ($paymentExists) {
            $this->info('Data sudah ada di payment-service, tidak perlu replay.');
            $record->update(['status' => 'verified']);
            return;
        }

                if ($isDryRun) {
            $amount = $record->source_snapshot['amount'] ?? 0;
            $this->info("[DRY RUN] Akan replay order {$record->business_key}, amount: {$amount}");
            return;
        }

                $action = RecoveryAction::create([
            'impacted_record_id' => $record->id,
            'action_type' => 'replay',
            'idempotency_key' => $idempotencyKey,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $record->update(['status' => 'fixing']);

        try {
            $res = Http::timeout(3)->post('http://localhost:4002/payments', [
                'order_ref' => $record->business_key,
                'amount' => $record->source_snapshot['amount'],
            ]);

                        $verified = DB::connection('payment_sim')
                ->table('payments')
                ->where('order_ref', $record->business_key)
                ->first();

            if ($verified) {
                $action->update([
                    'status' => 'success',
                    'result' => $res->json(),
                    'finished_at' => now(),
                ]);
                $record->update(['status' => 'verified']);
                $this->info("Berhasil & terverifikasi: {$record->business_key}");
            } else {
                throw new \Exception('Data tidak ditemukan setelah replay.');
            }
        } catch (\Throwable $e) {
            $action->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            $record->update(['status' => 'failed']);
            $this->error("Gagal: {$e->getMessage()}");
        }
    }
}