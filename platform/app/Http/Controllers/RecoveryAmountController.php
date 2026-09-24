<?php

namespace App\Http\Controllers;

use App\Models\ImpactedRecord;
use Illuminate\Http\Request;

class RecoveryAmountController extends Controller
{
    public function show($incidentId)
    {
        $records = ImpactedRecord::where('incident_id', $incidentId)->get();

        $breakdown = $records->groupBy('category')->map->count();

        $totalValue = $records->sum(function ($r) {
            return $r->source_snapshot['amount'] ?? 0;
        });

        return response()->json([
            'incident_id' => (int) $incidentId,
            'total_records' => $records->count(),
            'breakdown_by_category' => $breakdown,
            'total_estimated_value' => $totalValue,
            'status_breakdown' => $records->groupBy('status')->map->count(),
        ]);
    }
}