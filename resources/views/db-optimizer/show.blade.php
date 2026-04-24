<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Snapshot Details</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="max-w-7xl mx-auto px-6 py-8">
    <div class="mb-6">
        <a href="{{ route('db-optimizer.index') }}" class="text-blue-300 hover:text-blue-200 text-sm">← Back to Dashboard</a>
        <h1 class="text-2xl font-semibold mt-2">Snapshot Details</h1>
        <p class="text-slate-400 mt-1">{{ data_get($snapshot, 'captured_at', '-') }} · {{ data_get($snapshot, 'meta.method', 'GET') }} {{ data_get($snapshot, 'meta.route', '-') }}</p>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4"><p class="text-xs text-slate-400">Queries</p><p class="text-xl font-semibold">{{ data_get($snapshot, 'summary.query_count', 0) }}</p></div>
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4"><p class="text-xs text-slate-400">Total ms</p><p class="text-xl font-semibold">{{ data_get($snapshot, 'summary.total_time_ms', 0) }}</p></div>
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4"><p class="text-xs text-slate-400">Slow</p><p class="text-xl font-semibold">{{ data_get($snapshot, 'summary.slow_count', 0) }}</p></div>
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4"><p class="text-xs text-slate-400">N+1</p><p class="text-xl font-semibold">{{ data_get($snapshot, 'summary.n_plus_one_count', 0) }}</p></div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4"><p class="text-xs text-slate-400">Missing Index</p><p class="text-xl font-semibold">{{ data_get($snapshot, 'summary.missing_index_suggestions', 0) }}</p></div>
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4"><p class="text-xs text-slate-400">Cache Hints</p><p class="text-xl font-semibold">{{ data_get($snapshot, 'summary.cache_candidate_count', 0) }}</p></div>
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4"><p class="text-xs text-slate-400">Suggestions</p><p class="text-xl font-semibold">{{ data_get($snapshot, 'summary.recommendation_count', 0) }}</p></div>
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4"><p class="text-xs text-slate-400">Auto-Apply Ready</p><p class="text-xl font-semibold">{{ data_get($snapshot, 'summary.auto_apply_eligible_count', 0) }}</p></div>
    </div>

    <div class="space-y-4">
        @foreach(data_get($snapshot, 'queries', []) as $query)
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                <div class="flex flex-wrap items-center gap-2 mb-3 text-xs">
                    <span class="px-2 py-1 rounded bg-slate-800">{{ $query['time_ms'] ?? 0 }} ms</span>
                    <span class="px-2 py-1 rounded bg-slate-800">{{ $query['connection'] ?? '-' }}</span>
                    @if(data_get($query, 'detectors.n_plus_one.is_suspected'))
                        <span class="px-2 py-1 rounded bg-amber-700/60">N+1 suspected</span>
                    @endif
                    @if(!empty(data_get($query, 'detectors.missing_indexes', [])))
                        <span class="px-2 py-1 rounded bg-rose-700/60">Missing index hint</span>
                    @endif
                    @if(data_get($query, 'detectors.cache_candidate.is_candidate'))
                        <span class="px-2 py-1 rounded bg-emerald-700/60">Cache candidate</span>
                    @endif
                </div>

                <pre class="text-xs overflow-x-auto whitespace-pre-wrap text-slate-200 bg-slate-950 border border-slate-800 rounded-lg p-3">{{ $query['raw_sql'] ?? $query['sql'] ?? '' }}</pre>

                <div class="mt-3 text-xs text-slate-400">
                    Origin: {{ data_get($query, 'origin.file', '-') }}:{{ data_get($query, 'origin.line', '-') }}
                </div>

                @if(!empty(data_get($query, 'detectors.missing_indexes', [])))
                    <div class="mt-3 text-xs text-rose-200">
                        @foreach(data_get($query, 'detectors.missing_indexes', []) as $idx)
                            <div>Index suggestion: {{ $idx['table'] ?? '?' }}.{{ $idx['column'] ?? '?' }} ({{ $idx['reason'] ?? '' }})</div>
                        @endforeach
                    </div>
                @endif

                @if(isset($query['explain']))
                    <div class="mt-3 text-xs text-amber-200">
                        <div class="font-medium">EXPLAIN</div>
                        <div>{{ data_get($query, 'explain.summary', 'No summary') }}</div>
                    </div>
                @endif

                @if(!empty(data_get($query, 'recommendations', [])))
                    <div class="mt-4 space-y-2">
                        <div class="text-xs font-medium text-slate-300">Optimization Suggestions</div>
                        @foreach(data_get($query, 'recommendations', []) as $recommendation)
                            <div class="rounded-lg border border-slate-800 bg-slate-950 p-3 text-xs">
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <span class="px-2 py-1 rounded bg-blue-700/60 uppercase tracking-wide">{{ $recommendation['type'] ?? 'suggestion' }}</span>
                                    <span class="font-medium text-slate-200">{{ $recommendation['title'] ?? 'Recommendation' }}</span>
                                    <span class="text-slate-400">priority {{ $recommendation['priority'] ?? 0 }}</span>
                                    <span class="text-slate-400">confidence {{ $recommendation['confidence'] ?? 0 }}</span>
                                    @if(!empty($recommendation['safe_auto_apply']))
                                        <span class="px-2 py-1 rounded bg-emerald-700/60">safe auto-apply</span>
                                    @endif
                                    @if(!empty($recommendation['auto_apply_eligible']))
                                        <span class="px-2 py-1 rounded bg-emerald-700/80">eligible</span>
                                    @endif
                                </div>
                                <div class="text-slate-300 mb-2">{{ $recommendation['description'] ?? '' }}</div>
                                @if(!empty($recommendation['code_hint']))
                                    <pre class="overflow-x-auto whitespace-pre-wrap text-slate-200 bg-slate-900 border border-slate-800 rounded p-2">{{ $recommendation['code_hint'] }}</pre>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
</body>
</html>
