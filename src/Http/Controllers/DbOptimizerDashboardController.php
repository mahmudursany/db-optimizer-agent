<?php

namespace Mdj\DbOptimizer\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Mdj\DbOptimizer\Services\QuerySnapshotReader;

class DbOptimizerDashboardController extends Controller
{
    public function __construct(private readonly QuerySnapshotReader $reader)
    {
    }

    public function index(): View
    {
        return view('db-optimizer::index', [
            'snapshots' => $this->reader->latest(50),
        ]);
    }

    public function show(string $snapshotId): View
    {
        $snapshot = $this->reader->findById($snapshotId);

        abort_if($snapshot === null, 404);

        return view('db-optimizer::show', [
            'snapshot' => $snapshot,
        ]);
    }
}
