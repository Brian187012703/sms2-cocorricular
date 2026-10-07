<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AtomicWorkflow
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }
        DB::beginTransaction();
        try {
            foreach ($request->route()->parameters() as $name => $parameter) {
                if ($parameter instanceof Model) {
                    $request->route()->setParameter($name, $parameter->newQuery()->whereKey($parameter->getKey())->lockForUpdate()->firstOrFail());
                }
            }
            $response = $next($request);
            // Laravel may render an exception before it reaches this middleware.
            if ($response->getStatusCode() >= 400) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return $response;
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }
}
