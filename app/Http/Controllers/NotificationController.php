<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = AppNotification::where('user_id', $request->user()->id)->latest()->paginate(20);

        return view('notifications.index', compact('notifications'));
    }

    public function read(Request $request, AppNotification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->update(['read_at' => now()]);
        if ($notification->route_name && \Route::has($notification->route_name)) {
            return redirect()->route($notification->route_name, $notification->route_parameters ?? []);
        }

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('success', 'تم تعليم جميع الإشعارات كمقروءة.');
    }
}
