<?php

namespace App\Http\Controllers;

use App\DbOptimizer\Services\RemoteProjectScanner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DbOptimizerScannerController extends Controller
{
    public function index(Request $request): View
    {
        return view('db-optimizer.scanner', [
            'report' => $request->session()->get('db_optimizer_scan_report'),
        ]);
    }

    public function run(Request $request, RemoteProjectScanner $scanner): RedirectResponse
    {
        $payload = $request->validate([
            'target_url' => ['required', 'url'],
            'agent_token' => ['nullable', 'string', 'max:255'],
            'paths' => ['nullable', 'string', 'max:10000'],
        ]);

        $paths = preg_split('/\r\n|\r|\n/', $payload['paths'] ?? '/') ?: ['/'];

        try {
            $report = $scanner->scan(
                (string) $payload['target_url'],
                (string) ($payload['agent_token'] ?? ''),
                array_map('strval', $paths),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['scan' => $e->getMessage()]);
        }

        $request->session()->put('db_optimizer_scan_report', $report);

        return redirect()->route('db-optimizer.scanner.index');
    }
}
