<?php

namespace App\Http\Controllers;

use App\Models\AccountRequest;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformAccountRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::exists('roles', 'key'), Rule::notIn(['admin'])],
            'note' => ['required', 'string', 'max:5000'],
        ]);
        $pending = AccountRequest::where('email', $data['email'])->where('status', 'pending')->exists();
        if ($pending) {
            return response()->json(['message' => 'لديك طلب حساب قيد المراجعة بالفعل.'], 422);
        }
        $item = AccountRequest::create($data);

        return response()->json(['request' => $this->data($item)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.manage'), 403);

        return response()->json([
            'requests' => AccountRequest::latest()->get()->map(fn (AccountRequest $item) => $this->data($item)),
        ]);
    }

    public function approve(Request $request, AccountRequest $accountRequest): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('users', 'username')],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'universityId' => ['nullable', 'string'],
            'collegeId' => ['nullable', 'string'],
            'reviewNote' => ['nullable', 'string', 'max:5000'],
        ]);

        if (User::where('email', $accountRequest->email)->exists()) {
            throw ValidationException::withMessages(['email' => 'يوجد مستخدم مسجل بهذا البريد بالفعل.']);
        }

        $role = Role::where('key', $accountRequest->role)->firstOrFail();
        [$universityId, $collegeId] = $this->validatedScope($role, $data);

        [$item, $user] = DB::transaction(function () use ($accountRequest, $collegeId, $data, $request, $role, $universityId) {
            $item = AccountRequest::whereKey($accountRequest->id)->lockForUpdate()->firstOrFail();
            $this->ensurePending($item);

            $user = User::create([
                'name' => $item->name,
                'username' => $data['username'],
                'email' => $item->email,
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'active' => true,
                'must_change_password' => true,
            ]);
            $user->assignments()->create([
                'role_id' => $role->id,
                'university_id' => $universityId,
                'college_id' => $collegeId,
                'active' => true,
                'assigned_at' => now(),
            ]);
            $item->update([
                'status' => 'approved',
                'review_note' => $data['reviewNote'] ?? null,
                'reviewed_by' => $request->user()->id,
            ]);
            $this->audit($request, 'account_requests.approved', $item, [
                'created_user_id' => $user->id,
                'role' => $role->key,
            ]);

            return [$item, $user];
        });

        return response()->json([
            'request' => $this->data($item->fresh()),
            'userId' => $user->id,
            'message' => 'تم اعتماد الطلب وإنشاء حساب المستخدم.',
        ]);
    }

    public function reject(Request $request, AccountRequest $accountRequest): JsonResponse
    {
        $data = $request->validate(['reviewNote' => ['required', 'string', 'max:5000']]);

        $item = DB::transaction(function () use ($accountRequest, $data, $request) {
            $item = AccountRequest::whereKey($accountRequest->id)->lockForUpdate()->firstOrFail();
            $this->ensurePending($item);
            $item->update([
                'status' => 'rejected',
                'review_note' => $data['reviewNote'],
                'reviewed_by' => $request->user()->id,
            ]);
            $this->audit($request, 'account_requests.rejected', $item);

            return $item;
        });

        return response()->json([
            'request' => $this->data($item->fresh()),
            'message' => 'تم رفض طلب الحساب.',
        ]);
    }

    private function validatedScope(Role $role, array $data): array
    {
        $universityId = $this->numericId($data['universityId'] ?? null, 'u');
        $collegeId = $this->numericId($data['collegeId'] ?? null, 'c');

        if ($role->scope === 'university' && ! $universityId) {
            throw ValidationException::withMessages(['universityId' => 'يجب تحديد الجامعة لهذا الدور.']);
        }
        if ($role->scope === 'college' && (! $universityId || ! $collegeId)) {
            throw ValidationException::withMessages(['collegeId' => 'يجب تحديد الجامعة والكلية لهذا الدور.']);
        }
        if ($collegeId && ! College::whereKey($collegeId)->where('university_id', $universityId)->exists()) {
            throw ValidationException::withMessages(['collegeId' => 'الكلية لا تتبع الجامعة المحددة.']);
        }
        if (in_array($role->scope, ['platform', 'assignment'], true)) {
            return [null, null];
        }

        return [$universityId, $role->scope === 'university' ? null : $collegeId];
    }

    private function numericId(?string $value, string $prefix): ?int
    {
        if (! $value) {
            return null;
        }
        if (! str_starts_with($value, $prefix) || ! ctype_digit(substr($value, 1))) {
            throw ValidationException::withMessages(['scope' => 'معرف المؤسسة غير صحيح.']);
        }

        return (int) substr($value, 1);
    }

    private function ensurePending(AccountRequest $item): void
    {
        if ($item->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'تمت معالجة هذا الطلب مسبقًا.']);
        }
    }

    private function audit(Request $request, string $action, AccountRequest $item, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'target_type' => AccountRequest::class,
            'target_id' => $item->id,
            'metadata' => $metadata ?: null,
            'ip_address' => $request->ip(),
        ]);
    }

    private function data(AccountRequest $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'email' => $item->email,
            'role' => $item->role,
            'note' => $item->note,
            'status' => $item->status === 'pending' ? 'بانتظار المراجعة' : ($item->status === 'approved' ? 'مقبول' : 'مرفوض'),
            'reviewNote' => $item->review_note,
            'createdAt' => $item->created_at?->toIso8601String(),
            'reviewedAt' => $item->status === 'pending' ? null : $item->updated_at?->toIso8601String(),
        ];
    }
}
