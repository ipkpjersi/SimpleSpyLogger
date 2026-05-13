<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IngestTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.ingest.token');

        if (empty($expected)) {
            return response()->json(['error' => 'Ingest token not configured on server.'], 500);
        }

        $header = $request->header('Authorization', '');
        $provided = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            $provided = trim($m[1]);
        }

        if (!hash_equals($expected, $provided)) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
