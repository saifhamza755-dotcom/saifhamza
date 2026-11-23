@extends('layouts.app')
@section('title', 'سجل التدقيق')
@section('page-title', 'سجل التدقيق والعمليات')

@section('content')
<section class="panel" style="margin-top:0"><h2>تصفية السجل</h2><form method="get" class="filter-bar"><div class="field"><label>العملية</label><input name="action" value="{{ request('action') }}" placeholder="مثال: submitted"></div><div class="field"><label>المستخدم</label><select name="user_id"><option value="">جميع المستخدمين</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected(request('user_id')==$user->id)>{{ $user->name }}</option>@endforeach</select></div><button class="secondary-button">تطبيق</button><a class="small-button" href="{{ route('audit.index') }}">إزالة التصفية</a></form></section>
<section class="panel"><h2>العمليات المسجلة</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>الوقت</th><th>المستخدم</th><th>العملية</th><th>نوع العنصر</th><th>المعرّف</th><th>عنوان IP</th></tr></thead><tbody>
@forelse($logs as $log)<tr><td>{{ $log->created_at?->format('Y-m-d H:i:s') }}</td><td>{{ $log->user?->name ?? 'النظام' }}</td><td><code>{{ $log->action }}</code></td><td>{{ class_basename($log->target_type) }}</td><td>{{ $log->target_id }}</td><td>{{ $log->ip_address }}</td></tr>@empty<tr><td colspan="6">لا توجد عمليات مطابقة.</td></tr>@endforelse
</tbody></table></div><div class="pagination">{{ $logs->links() }}</div></section>
@endsection
