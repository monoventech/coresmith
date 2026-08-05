<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Ability;
use App\Enums\TeamRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable, TwoFactorAuthenticatable;

    public function roleIn(?Team $team): ?TeamRole
    {
        if ($team === null) {
            return null;
        }

        $membership = $this->teams->firstWhere('id', $team->getKey());

        if ($membership === null) {
            return null;
        }

        return TeamRole::tryFrom((string) $membership->pivot->role);
    }

    public function belongsToTeam(?Team $team): bool
    {
        return $this->roleIn($team) !== null;
    }

    public function hasTeamAbility(?Team $team, Ability $ability): bool
    {
        return $this->roleIn($team)?->can($ability) ?? false;
    }

    public function hasAbilityInAnyTeam(Ability $ability): bool
    {
        return $this->teams->contains(
            fn (Team $team): bool => TeamRole::tryFrom((string) $team->pivot->role)?->can($ability) ?? false,
        );
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('role')->withTimestamps();
    }

    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'owner_id');
    }

    public function sshKeys(): HasMany
    {
        return $this->hasMany(SshKey::class);
    }

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
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
