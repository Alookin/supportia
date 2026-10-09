<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAttachment extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'ticket_comment_id',
        'filename',
        'original_name',
        'mime_type',
        'size',
        'path',
        'glpi_document_id',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    // Les pièces jointes sont immuables : pas de updated_at
    public const UPDATED_AT = null;

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(TicketComment::class, 'ticket_comment_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    // ─── Règles d'upload (source unique : config supportia.attachments) ──

    /** Taille max d'un fichier, en Ko. */
    public static function maxSizeKb(): int
    {
        return (int) config('supportia.attachments.max_size_kb', 10240);
    }

    /** « 10 Mo » (ou « 500 Ko »), pour l'interface. */
    public static function maxSizeLabel(): string
    {
        if (self::maxSizeKb() < 1024) {
            return self::maxSizeKb().' Ko';
        }

        $mb = self::maxSizeKb() / 1024;

        return (floor($mb) == $mb ? (int) $mb : number_format($mb, 1, ',', '')).' Mo';
    }

    /** @return list<string> */
    public static function allowedExtensions(): array
    {
        return config('supportia.attachments.allowed_extensions', []);
    }

    /**
     * Règles de validation d'un fichier : taille, extension du nom ET type MIME détecté sur le
     * contenu. La double règle bloque un fichier à extension trompeuse (.php renommé en .txt).
     */
    public static function rules(): array
    {
        return [
            'file',
            'max:'.self::maxSizeKb(),
            'extensions:'.implode(',', self::allowedExtensions()),
            'mimetypes:'.implode(',', config('supportia.attachments.allowed_mimetypes', [])),
        ];
    }

    /** Messages des règles ci-dessus, pour le champ $field (« attachments.* », « attachment »). */
    public static function messages(string $field): array
    {
        $formats = 'Formats acceptés : '.self::formatsLabel().'.';

        return [
            "{$field}.max"        => 'Fichier trop volumineux (maximum '.self::maxSizeLabel().').',
            "{$field}.extensions" => "Type de fichier non autorisé. {$formats}",
            "{$field}.mimetypes"  => "Type de fichier non autorisé. {$formats}",
            // PHP a refusé le fichier avant Laravel (upload_max_filesize dépassé, le plus souvent)
            "{$field}.uploaded"   => "Le fichier n'a pas pu être reçu : il dépasse probablement la taille acceptée par le serveur.",
        ];
    }

    /** Attribut accept des champs fichier : « .jpg,.jpeg,… ». */
    public static function acceptAttribute(): string
    {
        return implode(',', array_map(fn ($ext) => '.'.$ext, self::allowedExtensions()));
    }

    /** « images, PDF, CSV, TXT, LOG, Excel », d'après les extensions autorisées. */
    public static function formatsLabel(): string
    {
        $families = [
            'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'Excel'  => ['xls', 'xlsx'],
        ];

        $labels = [];
        foreach (self::allowedExtensions() as $ext) {
            $family = collect($families)->search(fn ($exts) => in_array($ext, $exts, true));
            $labels[] = $family ?: strtoupper($ext);
        }

        return implode(', ', array_unique($labels));
    }
}
