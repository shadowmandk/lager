<?php

namespace App\Http\Middleware;

use App\Models\Operator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/** Tavle og log kræver, at der er valgt en medarbejder på enheden. */
class EnsureOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $operator = Operator::query()->where('active', true)->find($request->session()->get('operator_id'));

        if ($operator === null) {
            $request->session()->forget('operator_id');

            return redirect()->route('operator.pick');
        }

        $request->attributes->set('operator', $operator);
        View::share('currentOperator', $operator);

        return $next($request);
    }
}
