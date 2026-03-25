<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>DB Optimizer Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="max-w-7xl mx-auto px-6 py-8">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-3xl font-semibold tracking-tight">DB Optimizer</h1>
            <p class="text-slate-400 mt-1">Recent request snapshots and query diagnostics.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('db-optimizer.scanner.index') }}" class="inline-flex items-center rounded-lg bg-blue-700 hover:bg-blue-600 px-4 py-2 text-sm">Remote Scanner</a>
            <a href="{{ route('db-optimizer.index') }}" class="inline-flex items-center rounded-lg bg-slate-800 hover:bg-slate-700 px-4 py-2 text-sm">Refresh</a>
        </div>
    </div>

    @if(empty($snapshots))
        <div class="rounded-xl border border-slate-800 bg-slate-900 p-6">
            <p class="text-slate-300">No snapshots found yet. Hit your app routes first, then reload this page.</p>
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-800/50 text-slate-300">
                    <tr>
                        <th class="text-left px-4 py-3">Time</th>
                        <th class="text-left px-4 py-3">Route</th>
                        <th class="text-right px-4 py-3">Queries</th>
                        <th class="text-right px-4 py-3">Total (ms)</th>
                        <th class="text-right px-4 py-3">Slow</th>
                        <th class="text-right px-4 py-3">N+1</th>
                        <th class="text-right px-4 py-3">Missing Index</th>
                        <th class="text-right px-4 py-3">Cache Hints</th>
                        <th class="text-right px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                @foreach($snapshots as $snapshot)
                    <tr class="hover:bg-slate-800/40">
                        <td class="px-4 py-3 text-slate-300">{{ $snapshot['captured_at'] ?? '-' }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ data_get($snapshot, 'meta.method', 'GET') }} {{ data_get($snapshot, 'meta.route', '-') }}</div>
                            <div class="text-slate-400 text-xs truncate max-w-sm">{{ data_get($snapshot, 'meta.url', '-') }}</div>
                        </td>
                        <td class="px-4 py-3 text-right">{{ data_get($snapshot, 'summary.query_count', 0) }}</td>
                        <td class="px-4 py-3 text-right">{{ data_get($snapshot, 'summary.total_time_ms', 0) }}</td>
                        <td class="px-4 py-3 text-right">{{ data_get($snapshot, 'summary.slow_count', 0) }}</td>
                        <td class="px-4 py-3 text-right">{{ data_get($snapshot, 'summary.n_plus_one_count', 0) }}</td>
                        <td class="px-4 py-3 text-right">{{ data_get($snapshot, 'summary.missing_index_suggestions', 0) }}</td>
                        <td class="px-4 py-3 text-right">{{ data_get($snapshot, 'summary.cache_candidate_count', 0) }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('db-optimizer.show', ['snapshotId' => $snapshot['id']]) }}" class="text-blue-300 hover:text-blue-200">Inspect</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
</body>
</html>
