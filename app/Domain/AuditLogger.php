<?php

namespace App\Domain;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        ?int $shopId = null,
        ?int $productId = null,
        ?int $qty = null,
        ?array $before = null,
        ?array $after = null,
        ?string $note = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'occurred_at' => now(),
            'user_id' => auth()->id(),
            'action' => $action,
            'shop_id' => $shopId,
            'product_id' => $productId,
            'qty' => $qty,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'note' => $note,
        ]);
    }
}
