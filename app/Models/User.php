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

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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

    public function hasRole(AppRole|string $role): bool
    {
        $role = is_string($role) ? AppRole::tryFrom($role) : $role;

        if ($role === null) {
            return false;
        }

        return in_array($role, $this->assignedRoles(), true);
    }

    public function hasAnyRole(AppRole|string ...$roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
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
        $capabilities = [];

        foreach ($this->assignedRoles() as $role) {
            foreach ($role->capabilities() as $capability) {
                $capabilities[$capability->value] = $capability;
            }
        }

        return array_values($capabilities);
    }

    public function hasCapability(Capability|string $capability): bool
    {
        $capability = is_string($capability)
            ? Capability::tryFrom($capability)
            : $capability;

        if ($capability === null) {
            return false;
        }

        return in_array($capability, $this->capabilities(), true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            && (config('app.env') === 'local' || $this->assignedRoles() !== []);
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
