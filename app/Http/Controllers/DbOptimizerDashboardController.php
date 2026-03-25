<?php

namespace App\Http\Controllers;

use App\DbOptimizer\Services\QuerySnapshotReader;
use Illuminate\Contracts\View\View;

class DbOptimizerDashboardController extends Controller
{
    public function __construct(
        private readonly QuerySnapshotReader $reader,
    ) {
    }

    public function index(): View
    {
        return view('db-optimizer.index', [
            'snapshots' => $this->reader->latest(50),
        ]);
    }

    public function show(string $snapshotId): View
    {
        $snapshot = $this->reader->findById($snapshotId);

        abort_if($snapshot === null, 404);

        return view('db-optimizer.show', [
            'snapshot' => $snapshot,
        ]);
    }
}
