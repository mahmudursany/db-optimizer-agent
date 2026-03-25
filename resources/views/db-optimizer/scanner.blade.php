<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Remote Scanner</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="max-w-7xl mx-auto px-6 py-8">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <a href="{{ route('db-optimizer.index') }}" class="text-blue-300 hover:text-blue-200 text-sm">← Dashboard</a>
            <h1 class="text-3xl font-semibold mt-2">Remote Project Scanner</h1>
            <p class="text-slate-400">একটি Laravel project URL স্ক্যান করে query diagnostics report দেখাবে।</p>
        </div>
    </div>

    <div class="rounded-xl border border-slate-800 bg-slate-900 p-5 mb-6">
        <form action="{{ route('db-optimizer.scanner.run') }}" method="POST" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs text-slate-400">Target URL</label>
                    <input type="url" name="target_url" required value="{{ old('target_url') }}" placeholder="http://127.0.0.1:8001" class="w-full mt-1 rounded-lg bg-slate-950 border border-slate-700 px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-xs text-slate-400">Agent Token</label>
                    <input type="text" name="agent_token" value="{{ old('agent_token') }}" placeholder="db-optimizer token" class="w-full mt-1 rounded-lg bg-slate-950 border border-slate-700 px-3 py-2 text-sm" />
                </div>
            </div>

            <div>
                <label class="text-xs text-slate-400">Paths (one per line)</label>
                <textarea name="paths" rows="5" class="w-full mt-1 rounded-lg bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="/
/users
/products">{{ old('paths', '/') }}</textarea>
            </div>

            @if($errors->any())
                <div class="rounded-lg border border-rose-700 bg-rose-950/40 p-3 text-sm text-rose-200">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <button type="submit" class="rounded-lg bg-blue-600 hover:bg-blue-500 px-4 py-2 text-sm">Run Scan</button>
        </form>
    </div>

    @if(is_array($report ?? null))
        <div class="space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-4"><p class="text-xs text-slate-400">Requests</p><p class="text-xl font-semibold">{{ data_get($report, 'summary.request_count', 0) }}</p></div>
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-4"><p class="text-xs text-slate-400">Snapshots</p><p class="text-xl font-semibold">{{ data_get($report, 'summary.snapshot_count', 0) }}</p></div>
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-4"><p class="text-xs text-slate-400">Queries</p><p class="text-xl font-semibold">{{ data_get($report, 'summary.query_count', 0) }}</p></div>
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-4"><p class="text-xs text-slate-400">Slow Queries</p><p class="text-xl font-semibold">{{ data_get($report, 'summary.slow_query_count', 0) }}</p></div>
            </div>

            <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                <h2 class="font-semibold mb-3">Top Missing Index Suggestions</h2>
                <div class="space-y-2 text-sm">
                    @forelse(data_get($report, 'issues.missing_indexes', []) as $idx)
                        <div class="flex justify-between border-b border-slate-800 pb-2">
                            <div>{{ $idx['table'] ?? '?' }}.{{ $idx['column'] ?? '?' }} <span class="text-slate-400">{{ $idx['reason'] ?? '' }}</span></div>
                            <div class="text-slate-300">x{{ $idx['count'] ?? 0 }}</div>
                        </div>
                    @empty
                        <div class="text-slate-400">No missing index hints found.</div>
                    @endforelse
                </div>
            </div>

            <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                <h2 class="font-semibold mb-3">Top Slow Queries</h2>
                <div class="space-y-3 text-xs">
                    @forelse(data_get($report, 'issues.slow_queries', []) as $q)
                        <div class="bg-slate-950 border border-slate-800 rounded-lg p-3">
                            <div class="mb-2 text-slate-300">{{ $q['time_ms'] ?? 0 }} ms · {{ data_get($q, '_snapshot_meta.route', '-') }}</div>
                            <pre class="whitespace-pre-wrap overflow-x-auto text-slate-200">{{ $q['raw_sql'] ?? $q['sql'] ?? '' }}</pre>
                        </div>
                    @empty
                        <div class="text-slate-400">No slow queries detected.</div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>
</body>
</html>
