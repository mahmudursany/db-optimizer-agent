<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" /><title>Remote Scanner</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-950 text-slate-100 min-h-screen"><div class="max-w-7xl mx-auto px-6 py-8">
<a href="{{ route('db-optimizer.index') }}" class="text-blue-300 text-sm">← Dashboard</a>
<h1 class="text-3xl font-semibold mt-2 mb-4">Remote Project Scanner</h1>
<form method="POST" action="{{ route('db-optimizer.scanner.run') }}" class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">@csrf
<div><label class="text-xs text-slate-400">Target URL</label><input class="w-full mt-1 rounded bg-slate-950 border border-slate-700 px-3 py-2 text-sm" type="url" required name="target_url" value="{{ old('target_url') }}" placeholder="http://127.0.0.1:8001"></div>
<div><label class="text-xs text-slate-400">Agent Token</label><input class="w-full mt-1 rounded bg-slate-950 border border-slate-700 px-3 py-2 text-sm" type="text" name="agent_token" value="{{ old('agent_token') }}"></div>
<div><label class="text-xs text-slate-400">Paths (one per line)</label><textarea class="w-full mt-1 rounded bg-slate-950 border border-slate-700 px-3 py-2 text-sm" rows="5" name="paths">{{ old('paths','/') }}</textarea></div>
@if($errors->any())<div class="text-rose-300 text-sm">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
<button class="rounded-lg bg-blue-600 px-4 py-2 text-sm" type="submit">Run Scan</button>
</form>
@if(is_array($report ?? null))<div class="mt-6 rounded-xl border border-slate-800 bg-slate-900 p-4"><h2 class="font-semibold mb-3">Summary</h2><div class="text-sm">Requests: {{ data_get($report,'summary.request_count',0) }} | Queries: {{ data_get($report,'summary.query_count',0) }} | Slow: {{ data_get($report,'summary.slow_query_count',0) }}</div></div>@endif
</div></body></html>
