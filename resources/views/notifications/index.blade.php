@extends('layouts.app')
@section('title', 'الإشعارات')
@section('page-title', 'الإشعارات')
@section('page-actions')<form method="post" action="{{ route('notifications.read-all') }}">@csrf @method('PATCH')<button class="secondary-button">تعليم الكل كمقروء</button></form>@endsection

@section('content')
<section class="panel" style="margin-top:0"><div class="notification-list">
@forelse($notifications as $notification)<a class="notification-item {{ $notification->read_at?'read':'unread' }}" href="{{ route('notifications.read',$notification) }}"><span class="notification-dot"></span><div><strong>{{ $notification->title }}</strong><p>{{ $notification->body }}</p><small>{{ $notification->created_at->diffForHumans() }}</small></div><span>‹</span></a>
@empty<div class="empty-state">لا توجد إشعارات.</div>@endforelse
</div><div class="pagination">{{ $notifications->links() }}</div></section>
@endsection
