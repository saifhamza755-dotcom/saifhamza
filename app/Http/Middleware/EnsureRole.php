<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * يسمح بمتابعة الطلب فقط إن كان دور الجلسة الحالي من الأدوار المطلوبة.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! $request->user() || ! $request->user()->hasRole(...$roles)) {
            abort(403, 'لا تملك صلاحية الوصول إلى هذه الصفحة.');
        }

        return $next($request);
    }
}
