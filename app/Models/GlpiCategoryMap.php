<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
