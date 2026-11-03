<?php

namespace App\Modules\Documents\Operations;

use App\Models\User;
use App\Modules\Tenancy\Domain\Hotel;
use Illuminate\Support\Carbon;

/** Small shared helpers of the D-C sheet builders. Builders never write anything: they read sources and return a sheet. */
trait SheetHelpers
{
    protected function hotel(): ?Hotel
    {
        $id = app(\App\Platform\Tenancy\TenantContext::class)->hotelId();

        return $id ? Hotel::find($id) : null;
    }

    protected function userName(?int $id): ?string
    {
        return $id ? User::withoutGlobalScopes()->whereKey($id)->value('name') : null;
    }

    protected function date($v): string
    {
        return $v ? Carbon::parse($v)->format('d/m/Y') : '';
    }

    protected function dt($v): string
    {
        return $v ? Carbon::parse($v)->setTimezone($this->hotel()?->timezone ?: 'Africa/Douala')->format('d/m/Y H:i') : '';
    }

    protected function money($v): string
    {
        return number_format((int) $v, 0, ',', ' ');
    }

    /** Column definition: key, French, English, type (text|money|int|qty|date|datetime). */
    protected static function col(string $k, string $fr, string $en, string $t = 'text'): array
    {
        return ['k' => $k, 'fr' => $fr, 'en' => $en, 't' => $t];
    }

    /** Builder result for a sheet document. */
    protected function result(string $type, array $sheet, string $number, ?int $sourceId, array $more = []): array
    {
        return $more + [
            'type' => $type, 'view' => 'documents::operations.sheet', 'size' => 'a4', 'data' => ['sheet' => $sheet + ['number' => $number]],
            'number' => $number, 'source_type' => $type, 'source_id' => $sourceId, 'title' => $sheet['title'] ?? ['', ''],
        ];
    }
}
