<?php

namespace Mdj\DbOptimizer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDbOptimizerAgentToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = (string) config('db_optimizer.agent_token', '');

        abort_if($expectedToken === '', 403, 'DB Optimizer agent token is not configured.');

        $providedToken = (string) $request->bearerToken();

        abort_unless(hash_equals($expectedToken, $providedToken), 401, 'Invalid DB Optimizer token.');

        return $next($request);
    }
}
