<?php

namespace App\Modules\Documents\Operations;

use App\Modules\Documents\Domain\IssuedDocument;

/**
 * Versions of one letter. The first issue uses source_type = <type>; each regeneration uses "<type>#v<n>" with the same source id,
 * so the engine keeps one record per version and the verify page of an older one can say "replaced by <new number>".
 */
final class Versions
{
    /** @return array{0: string, 1: int, 2: ?IssuedDocument} current source_type, number of versions issued, latest record */
    public static function current(string $type, int $sourceId): array
    {
        $rows = IssuedDocument::where('type', $type)->where('source_id', $sourceId)
            ->where(fn ($q) => $q->where('source_type', $type)->orWhere('source_type', 'like', $type.'#v%'))->orderBy('id')->get();
        $last = $rows->last();

        return [$last?->source_type ?? $type, $rows->count(), $last];
    }

    /** Void every version of a letter (its source was withdrawn). Returns how many verify pages were flipped. */
    public static function voidAll(string $type, int $sourceId, string $reason): int
    {
        return IssuedDocument::withoutGlobalScopes()->where('type', $type)->where('source_id', $sourceId)->where('status', 'valid')
            ->where(fn ($q) => $q->where('source_type', $type)->orWhere('source_type', 'like', $type.'#v%'))
            ->update(['status' => 'voided', 'status_reason' => $reason ?: null, 'status_at' => now()]);
    }
}
