<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyLabourImporterToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.labour_importer.token');
        $given = (string) $request->header('X-Labour-Importer-Token', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
