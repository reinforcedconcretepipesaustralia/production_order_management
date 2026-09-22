<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AppRole;
use App\Enums\Capability;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    protected static function booted(): void
    {
        static::saved(function (User $user): void {
            if (! $user->wasRecentlyCreated && ! $user->wasChanged('app_roles')) {
                return;
            }

            $user->syncShieldRolesFromAppRoles();
        });
    }

    /**
     * Mirror Entra app roles onto Filament Shield roles, leaving Shield-only
     * roles such as super_admin in place.
     */
    public function syncShieldRolesFromAppRoles(): void
    {
        $appRoleNames = array_map(
            fn (AppRole $role): string => $role->value,
            $this->assignedRoles(),
        );

        $preservedRoles = $this->roles()
            ->pluck('name')
            ->reject(fn (string $name): bool => in_array($name, array_column(AppRole::cases(), 'value'), true))
            ->all();

        $this->syncRoles([...$appRoleNames, ...$preservedRoles]);
    }

    /**
     * Copy Shield roles that match an application role back onto the Entra role list.
     */
    public function mirrorShieldRolesOntoAppRoles(): void
    {
        $this->app_roles = $this->roles()
            ->pluck('name')
            ->filter(fn (string $name): bool => AppRole::tryFrom($name) !== null)
            ->values()
            ->all();

        $this->saveQuietly();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'app_roles' => 'array',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return list<AppRole>
     */
    public function assignedRoles(): array
    {
        $roles = [];

        foreach ($this->app_roles ?? [] as $roleValue) {
            if (! is_string($roleValue)) {
                continue;
            }

            $role = AppRole::tryFrom($roleValue);

            if ($role !== null) {
                $roles[$role->value] = $role;
            }
        }

        return array_values($roles);
    }

    public function hasAppRole(AppRole|string $role): bool
    {
        $role = is_string($role) ? AppRole::tryFrom($role) : $role;

        if ($role === null) {
            return false;
        }

        return in_array($role, $this->assignedRoles(), true) || $this->hasRole($role->value);
    }

    public function hasAnyAppRole(AppRole|string ...$roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasAppRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        $granted = $this->getAllPermissions()->pluck('name');

        return array_values(array_filter(
            Capability::cases(),
            fn (Capability $capability): bool => $granted->contains($capability->shieldPermission()),
        ));
    }

    public function hasCapability(Capability|string $capability): bool
    {
        $capability = is_string($capability)
            ? Capability::tryFrom($capability)
            : $capability;

        if ($capability === null) {
            return false;
        }

        return $this->checkPermissionTo($capability->shieldPermission());
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            && (config('app.env') === 'local'
                || $this->assignedRoles() !== []
                || $this->roles()->exists());
    }

    public function hasCapabilityForDepot(
        Capability|string $capability,
        Depot|int|null $depot = null,
    ): bool {
        $capability = is_string($capability)
            ? Capability::tryFrom($capability)
            : $capability;

        if ($capability === null || ! $this->hasCapability($capability)) {
            return false;
        }

        if (! $capability->requiresDepotAssignment()) {
            return true;
        }

        return $depot !== null && $this->isAssignedToDepot($depot);
    }

    public function isAssignedToDepot(Depot|int $depot): bool
    {
        $depotId = $depot instanceof Depot ? $depot->getKey() : $depot;

        if ($this->relationLoaded('depots')) {
            return $this->depots->contains(
                fn (Depot $assignedDepot): bool => $assignedDepot->getKey() === $depotId,
            );
        }

        return $this->depots()->whereKey($depotId)->exists();
    }

    /**
     * @return BelongsToMany<Depot, $this>
     */
    public function depots(): BelongsToMany
    {
        return $this->belongsToMany(Depot::class)->withTimestamps();
    }

    public function dispatchNotificationAttempts(): HasMany
    {
        return $this->hasMany(DispatchNotificationAttempt::class, 'actor_user_id');
    }
}
