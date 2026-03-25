<?php

namespace Mdj\DbOptimizer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mdj\DbOptimizer\Services\QuerySnapshotReader;

class DbOptimizerAgentController extends Controller
{
    public function ping(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'service' => 'db-optimizer-agent',
            'version' => '1.0.0',
            'time' => now()->toIso8601String(),
        ]);
    }

    public function snapshots(Request $request, QuerySnapshotReader $reader): JsonResponse
    {
        $limit = max(1, min(500, (int) $request->integer('limit', 100)));
        $since = $request->string('since')->toString();

        return response()->json([
            'ok' => true,
            'data' => $reader->latest($limit, $since !== '' ? $since : null),
        ]);
    }

    public function reset(QuerySnapshotReader $reader): JsonResponse
    {
        $reader->clear();

        return response()->json([
            'ok' => true,
            'message' => 'Snapshots cleared.',
        ]);
    }
}
