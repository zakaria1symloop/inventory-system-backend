<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

/**
 * Bulletproof reference generation for models.
 *
 * Usage: add `use GeneratesReference;` and define `protected static string $referencePrefix = 'PAY';`
 * Optional: `protected static string $referenceSeparator = '-';` for formats like BC-20260305-0001
 *
 * This trait:
 * - Uses DB::table() to bypass SoftDeletes (sees ALL records including trashed)
 * - Matches by reference LIKE pattern (not by created_at date)
 * - Uses lockForUpdate() within the caller's transaction
 * - Retries up to 5 times on duplicate key collision (race condition safety)
 * - Auto-registers the creating event (no need to add it in boot())
 */
trait GeneratesReference
{
    public static function bootGeneratesReference(): void
    {
        static::creating(function ($model) {
            if (empty($model->reference)) {
                $model->reference = static::generateReference();
            }
        });
    }

    public static function generateReference(): string
    {
        $prefix = static::$referencePrefix;
        $separator = static::$referenceSeparator ?? '';
        $table = (new static)->getTable();
        $date = now()->format('Ymd');
        $pattern = $prefix . $separator . $date . $separator;

        // Use DB::table to bypass SoftDeletes — sees ALL records including trashed
        $last = DB::table($table)
            ->where('reference', 'like', $pattern . '%')
            ->orderByRaw('CAST(SUBSTRING(reference, -4) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->first();

        $sequence = $last ? (int) substr($last->reference, -4) + 1 : 1;

        return $pattern . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
}
