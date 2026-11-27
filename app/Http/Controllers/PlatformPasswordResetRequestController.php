<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformPasswordResetRequestController extends Controller
{
    /** ينشئ طلب استعادة وصول من الصفحة العامة دون كشف وجود الحساب للزائر. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account' => ['required', 'string', 'max:255'],
        ]);
        $account = trim($data['account']);

        PasswordResetRequest::firstOrCreate([
            'account' => $account,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'تم إرسال طلب استعادة الوصول إلى مسؤول النظام.',
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.manage'), 403);

        return response()->json([
            'requests' => PasswordResetRequest::latest()->get()->map(
                fn (PasswordResetRequest $item) => $this->data($item)
            ),
        ]);
    }

    /** يعين كلمة مرور مؤقتة بعد التحقق الإداري من هوية صاحب الطلب. */
    public function process(Request $request, PasswordResetRequest $passwordResetRequest): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
            'reviewNote' => ['nullable', 'string', 'max:5000'],
        ]);

        $item = DB::transaction(function () use ($data, $passwordResetRequest, $request) {
            $item = PasswordResetRequest::whereKey($passwordResetRequest->id)->lockForUpdate()->firstOrFail();
            $this->ensurePending($item);
            $user = User::where('username', $item->account)
                ->orWhere('email', $item->account)
                ->first();
            if (! $user) {
                throw ValidationException::withMessages([
                    'account' => 'لا يوجد حساب مطابق لاسم المستخدم أو البريد المدخل.',
                ]);
            }

            $user->update([
                'password' => $data['password'],
                'must_change_password' => true,
            ]);
            $item->update([
                'status' => 'processed',
                'review_note' => $data['reviewNote'] ?? null,
                'user_id' => $user->id,
                'reviewed_by' => $request->user()->id,
            ]);
            $this->audit($request, 'password_reset_requests.processed', $item, [
                'user_id' => $user->id,
            ]);

            return $item;
        });

        return response()->json([
            'request' => $this->data($item->fresh()),
            'message' => 'تم تعيين كلمة مرور مؤقتة للحساب.',
        ]);
    }

    /** يحفظ رفض طلب استعادة الوصول مع الملاحظة التفسيرية. */
    public function reject(Request $request, PasswordResetRequest $passwordResetRequest): JsonResponse
    {
        $data = $request->validate([
            'reviewNote' => ['required', 'string', 'max:5000'],
        ]);

        $item = DB::transaction(function () use ($data, $passwordResetRequest, $request) {
            $item = PasswordResetRequest::whereKey($passwordResetRequest->id)->lockForUpdate()->firstOrFail();
            $this->ensurePending($item);
            $item->update([
                'status' => 'rejected',
                'review_note' => $data['reviewNote'],
                'reviewed_by' => $request->user()->id,
            ]);
            $this->audit($request, 'password_reset_requests.rejected', $item);

            return $item;
        });

        return response()->json([
            'request' => $this->data($item->fresh()),
            'message' => 'تم رفض طلب استعادة الوصول.',
        ]);
    }

    private function ensurePending(PasswordResetRequest $item): void
    {
        if ($item->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'تمت معالجة هذا الطلب مسبقًا.',
            ]);
        }
    }

    private function audit(Request $request, string $action, PasswordResetRequest $item, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'target_type' => PasswordResetRequest::class,
            'target_id' => $item->id,
            'metadata' => $metadata ?: null,
            'ip_address' => $request->ip(),
        ]);
    }

    private function data(PasswordResetRequest $item): array
    {
        return [
            'id' => $item->id,
            'account' => $item->account,
            'status' => match ($item->status) {
                'processed' => 'تمت المعالجة',
                'rejected' => 'مرفوض',
                default => 'بانتظار المراجعة',
            },
            'reviewNote' => $item->review_note,
            'createdAt' => $item->created_at?->toIso8601String(),
            'reviewedAt' => $item->status === 'pending' ? null : $item->updated_at?->toIso8601String(),
        ];
    }
}
