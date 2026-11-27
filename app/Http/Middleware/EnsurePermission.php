<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * يحمي الرابط عبر التحقق من صلاحية الدور الفعّال للمستخدم قبل متابعة الطلب.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $request->user() || ! $request->user()->hasPermission($permission)) {
            abort(403, 'لا تملك الصلاحية المطلوبة لتنفيذ هذه العملية.');
        }

        return $next($request);
    }
}
