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
        AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'تم تعليم الإشعارات كمقروءة.']);
    }

    public function audit(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('audit.view'), 403);
        $items = AuditLog::with('user')->latest('created_at')->limit(100)->get();

        return response()->json(['audit' => $items->map(fn (AuditLog $item) => ['id' => $item->id, 'user' => $item->user?->name ?? 'النظام', 'action' => $item->action, 'entity' => class_basename($item->target_type ?? '').($item->target_id ? '#'.$item->target_id : ''), 'date' => $item->created_at?->toIso8601String(), 'metadata' => $item->metadata ?? []])]);
    }
}
