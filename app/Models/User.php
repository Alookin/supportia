<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Models\Organization;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
        'name',
        'email',
        'password',
        'organization_id',
        'team_id',
        'role',
        'glpi_user_id',
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
            'password'          => 'hashed',
            'glpi_user_id'      => 'integer',
            'role'              => UserRole::class,
        ];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isTeamAdmin(): bool
    {
        return $this->role === UserRole::TeamAdmin && $this->team_id !== null;
    }

    /** Admin ou admin d'équipe : accès au dashboard et aux tickets d'autrui. */
    public function canSupervise(): bool
    {
        return $this->isAdmin() || $this->isTeamAdmin();
    }
}
