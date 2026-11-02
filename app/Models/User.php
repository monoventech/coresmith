<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Ability;
use App\Enums\Edition;
use App\Enums\TeamRole;
use App\Models\Concerns\IgnoresJsonKeyOrder;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, IgnoresJsonKeyOrder, Notifiable, TwoFactorAuthenticatable;

    public static function normaliseEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    public static function findByEmail(string $email): ?self
    {
        $email = self::normaliseEmail($email);

        return self::query()->where('email', $email)->get()->first(fn(self $user): bool => $user->email === $email);
    }

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
            fn(Team $team): bool => TeamRole::tryFrom((string) $team->pivot->role)?->can($ability) ?? false,
        );
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('role')->withTimestamps();
    }

    public function managesInstance(): bool
    {
        if (Edition::current()->isCloud()) {
            return false;
        }

        return $this->teams()->wherePivot('role', TeamRole::Owner->value)->exists();
    }

    public function scopeInstanceOwners(Builder $query): void
    {
        if (Edition::current()->isCloud()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('teams', fn(Builder $teams) => $teams->where('team_user.role', TeamRole::Owner->value));
    }

    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'owner_id');
    }

    public function sshKeys(): HasMany
    {
        return $this->hasMany(SshKey::class);
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn(?string $value): ?string => $value === null ? null : self::normaliseEmail($value));
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
            'notification_preferences' => 'array',
        ];
    }
}
