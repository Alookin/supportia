<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'logo_path',
        'primary_color',
        'glpi_api_url',
        'glpi_app_token',
        'glpi_user_token',
        'claude_api_key',
        'is_active',
    ];

    protected $casts = [
        'glpi_app_token'  => 'encrypted',
        'glpi_user_token' => 'encrypted',
        'claude_api_key'  => 'encrypted',
        'is_active'       => 'boolean',
    ];

    protected $hidden = [
        'glpi_app_token',
        'glpi_user_token',
        'claude_api_key',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(GlpiCategoryMap::class);
    }

    public function activeCategories(): HasMany
    {
        return $this->categories()->where('is_active', true);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    /**
     * Retourne la clé Claude à utiliser :
     * celle de l'orga si définie, sinon la clé globale .env.
     */
    public function getClaudeApiKey(): string
    {
        return $this->claude_api_key ?: config('supportia.claude_api_key');
    }

    /**
     * Vérifie si la connexion GLPI est configurée.
     */
    public function hasGlpiConfig(): bool
    {
        return ! empty($this->glpi_api_url)
            && ! empty($this->glpi_app_token)
            && ! empty($this->glpi_user_token);
    }
}
