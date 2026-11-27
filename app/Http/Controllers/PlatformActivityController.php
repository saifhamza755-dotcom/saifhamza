<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformActivityController extends Controller
{
    public function notifications(Request $request): JsonResponse
    {
        $items = AppNotification::where('user_id', $request->user()->id)->latest()->limit(50)->get();

        return response()->json(['notifications' => $items->map(fn (AppNotification $item) => ['id' => $item->id, 'title' => $item->title, 'text' => $item->body, 'read' => $item->read_at !== null, 'date' => $item->created_at?->toIso8601String(), 'route' => $item->route_name ?? ''])]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $count = AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
        if ($count > 0) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'notifications.marked_read',
                'target_type' => AppNotification::class,
                'metadata' => ['count' => $count],
                'ip_address' => $request->ip(),
            ]);
        }

        return response()->json(['message' => 'تم تعليم الإشعارات كمقروءة.']);
    }

    public function audit(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('audit.view'), 403);
        $items = AuditLog::with('user')->latest('created_at')->limit(100)->get();

        return response()->json(['audit' => $items->map(fn (AuditLog $item) => ['id' => $item->id, 'user' => $item->user?->name ?? 'النظام', 'action' => $item->action, 'entity' => class_basename($item->target_type ?? '').($item->target_id ? '#'.$item->target_id : ''), 'date' => $item->created_at?->toIso8601String(), 'metadata' => $item->metadata ?? [], 'ipAddress' => $item->ip_address ?? '—'])]);
    }
}
