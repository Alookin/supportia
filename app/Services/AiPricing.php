<?php

namespace App\Services;

/**
 * Coût estimé d'un appel IA, d'après les tarifs de config/supportia.php (ai_pricing).
 * Aucun tarif n'est codé ici.
 */
class AiPricing
{
    /**
     * Coût en dollars US, ou null si le modèle n'a pas de tarif renseigné ou si les tokens manquent.
     * $promptTokens inclut les $cachedTokens (facturés au tarif cached_input).
     */
    public static function estimate(?string $model, ?int $promptTokens, ?int $completionTokens, ?int $cachedTokens = null): ?float
    {
        // Pas de config("supportia.ai_pricing.$model") : les noms de modèles contiennent des points
        $prices = $model !== null ? (config('supportia.ai_pricing')[$model] ?? null) : null;

        if ($prices === null || $prices['input'] === null || $prices['output'] === null
            || $promptTokens === null || $completionTokens === null) {
            return null;
        }

        $cached      = min(max(0, (int) $cachedTokens), $promptTokens);
        $cachedPrice = $prices['cached_input'] ?? $prices['input'];

        return round((
            ($promptTokens - $cached) * (float) $prices['input']
            + $cached * (float) $cachedPrice
            + $completionTokens * (float) $prices['output']
        ) / 1_000_000, 10);
    }
}
