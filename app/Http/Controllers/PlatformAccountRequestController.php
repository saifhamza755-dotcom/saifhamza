<?php

namespace App\Http\Controllers;

use App\Models\AccountRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        return response()->json(['requests' => AccountRequest::latest()->get()->map(fn (AccountRequest $item) => $this->data($item))]);
    }

    private function data(AccountRequest $item): array
    {
        return ['id' => $item->id, 'name' => $item->name, 'email' => $item->email, 'role' => $item->role, 'note' => $item->note, 'status' => $item->status === 'pending' ? 'بانتظار المراجعة' : ($item->status === 'approved' ? 'مقبول' : 'مرفوض'), 'reviewNote' => $item->review_note, 'createdAt' => $item->created_at?->toIso8601String()];
    }
}
