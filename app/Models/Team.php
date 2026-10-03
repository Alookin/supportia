<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Équipe au sein d'une organisation (ex. Commercial, Marketing).
 */
class Team extends Model
{
    protected $fillable = ['organization_id', 'name', 'slug'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(GlpiCategoryMap::class, 'glpi_category_map_team');
    }
}
