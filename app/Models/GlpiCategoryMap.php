<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GlpiCategoryMap extends Model
{
    protected $fillable = [
        'organization_id',
        'glpi_category_id',
        'slug',
        'label',
        'label_simple',
        'description',
        'keywords',
        'parent_slug',
        'glpi_entity_id',
        'is_active',
        'is_visible_to_users',
        'median_resolution_seconds',
        'resolution_sample_count',
        'resolution_stats_at',
    ];

    protected $casts = [
        'keywords'         => 'array',
        'is_active'            => 'boolean',
        'is_visible_to_users'  => 'boolean',
        'glpi_category_id' => 'integer',
        'glpi_entity_id'   => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Équipes qui utilisent cette catégorie. Aucune = catégorie commune à toutes les équipes. */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'glpi_category_map_team');
    }

    /**
     * Catégories utilisables par une équipe : les catégories communes
     * + celles rattachées à cette équipe. Sans équipe : catégories communes seulement.
     */
    public function scopeForTeam(Builder $query, ?int $teamId): Builder
    {
        return $query->where(function (Builder $q) use ($teamId) {
            $q->whereDoesntHave('teams');

            if ($teamId) {
                $q->orWhereHas('teams', fn (Builder $t) => $t->whereKey($teamId));
            }
        });
    }

    /**
     * Formate la catégorie pour injection dans le prompt LLM.
     */
    public function toPromptLine(): string
    {
        $keywords = implode(', ', $this->keywords ?? []);

        return sprintf(
            '- slug: "%s" | label: "%s" | description: %s | mots-clés: %s',
            $this->slug,
            $this->label,
            $this->description ?? '(aucune)',
            $keywords ?: '(aucun)'
        );
    }
}
