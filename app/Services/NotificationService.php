<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationService
{
    /** ينشئ إشعارًا منفصلًا لكل مستلم مع رابط اختياري للمعاملة المرتبطة. */
    public function send(User|Collection $recipients, string $title, string $body, ?string $routeName = null, array $parameters = []): void
    {
        $users = $recipients instanceof User ? collect([$recipients]) : $recipients;
        foreach ($users->unique('id') as $user) {
            AppNotification::create([
                'user_id' => $user->id, 'title' => $title, 'body' => $body,
                'route_name' => $routeName, 'route_parameters' => $parameters ?: null,
            ]);
        }
    }

    /** يعيد المستخدمين النشطين الذين يحملون دورًا محددًا ضمن جامعة أو كلية عند الحاجة. */
    public function usersWithRole(string $role, ?int $universityId = null, ?int $collegeId = null): Collection
    {
        return User::where('active', true)->whereHas('activeAssignments', function ($query) use ($role, $universityId, $collegeId) {
            $query->whereHas('role', fn ($roleQuery) => $roleQuery->where('key', $role));
            if ($universityId) {
                $query->where('university_id', $universityId);
            }
            if ($collegeId) {
                $query->where('college_id', $collegeId);
            }
        })->get();
    }
}
