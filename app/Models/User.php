<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'name',
        'email',
        'phone',
        'password',
        'active',
        'must_change_password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(UserAssignment::class);
    }

    public function activeAssignments(): HasMany
    {
        $assignments = $this->assignments()
            ->where('active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        if ($assignmentId = $this->selectedAssignmentId()) {
            $assignments->orderByRaw(
                'CASE WHEN user_assignments.id = ? THEN 0 ELSE 1 END',
                [$assignmentId]
            );
        }

        return $assignments;
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function hasRole(string ...$roles): bool
    {
        $assignments = $this->activeAssignments();
        if ($assignmentId = $this->ownedSelectedAssignmentId()) {
            $assignments->whereKey($assignmentId);
        }

        return $assignments
            ->whereHas('role', fn ($query) => $query->whereIn('key', $roles))
            ->exists();
    }

    public function hasPermission(string $permission): bool
    {
        $assignments = $this->activeAssignments();
        if ($assignmentId = $this->ownedSelectedAssignmentId()) {
            $assignments->whereKey($assignmentId);
        }

        return $assignments
            ->whereHas('role.permissions', fn ($query) => $query->where('key', $permission))
            ->exists();
    }

    private function ownedSelectedAssignmentId(): ?int
    {
        $assignmentId = $this->selectedAssignmentId();
        if (! $assignmentId) {
            return null;
        }

        return $this->activeAssignments()->whereKey($assignmentId)->exists()
            ? $assignmentId
            : null;
    }

    private function selectedAssignmentId(): ?int
    {
        if (! app()->bound('request') || ! request()->hasSession()) {
            return null;
        }

        $assignmentId = request()->session()->get('active_assignment_id');

        return is_numeric($assignmentId) ? (int) $assignmentId : null;
    }
}
