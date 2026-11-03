<?php

namespace App\Modules\Onboarding\Application;

use App\Models\User;
use App\Modules\Documents\Application\Letterhead;
use App\Modules\Documents\Domain\DocumentTemplate;
use App\Modules\FrontOffice\Domain\ChargeCode;
use App\Modules\FrontOffice\Domain\PaymentMethod;
use App\Modules\FrontOffice\Domain\TaxCode;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Copy master setup from one hotel to another hotel of the same group.
 * Records that already exist in the target (same code / number) are skipped,
 * never overwritten. No transactions, balances or guests are copied.
 */
final class CopySetup
{
    public const PARTS = ['room_types', 'rooms', 'rate_plans', 'outlets', 'tax_codes', 'charge_codes', 'payment_methods', 'templates', 'branding'];

    private const MODELS = [
        'room_types' => [RoomType::class, 'code'], 'rooms' => [Room::class, 'number'], 'rate_plans' => [RatePlan::class, 'code'],
        'outlets' => [Outlet::class, 'code'], 'tax_codes' => [TaxCode::class, 'code'], 'charge_codes' => [ChargeCode::class, 'code'],
        'payment_methods' => [PaymentMethod::class, 'code'], 'templates' => [DocumentTemplate::class, null],
    ];

    public function __construct(private TenantContext $ctx, private EventBus $bus) {}

    /** @return array<string, array{copied: int, skipped: int}> */
    public function copy(Hotel $source, Hotel $target, array $parts, User $user): array
    {
        abort_if($source->id === $target->id, 422, 'Source and target hotel must differ.');
        abort_unless($source->group_id === $target->group_id, 403);
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        $parts = array_values(array_intersect(self::PARTS, $parts));
        if (in_array('rooms', $parts, true) && ! in_array('room_types', $parts, true)) {
            $parts[] = 'room_types';
        }

        try {
            return DB::transaction(function () use ($source, $target, $parts, $user, $saved) {
                $as = fn (Hotel $h) => $this->ctx->set($h->group_id, $h->id, $saved[2], $saved[3]);
                $as($source);
                $rows = [];
                foreach (self::MODELS as $part => [$class]) {
                    if (in_array($part, $parts, true)) {
                        $rows[$part] = $class::query()->orderBy('id')->get();
                    }
                }
                $sourceTypes = RoomType::query()->pluck('code', 'id');
                $sourcePlans = RatePlan::query()->pluck('code', 'id');

                $as($target);
                $result = [];
                foreach (['room_types', 'rate_plans', 'rooms', 'outlets', 'tax_codes', 'charge_codes', 'payment_methods', 'templates'] as $part) {
                    if (! isset($rows[$part])) {
                        continue;
                    }
                    [$class, $key] = self::MODELS[$part];
                    $copied = $skipped = 0;
                    foreach ($rows[$part] as $m) {
                        $attrs = collect($m->getAttributes())->except(['id', 'group_id', 'hotel_id', 'created_at', 'updated_at'])->all();
                        $exists = $part === 'templates'
                            ? $class::where('kind', $attrs['kind'])->where('locale', $attrs['locale'])->exists()
                            : $class::where($key, $attrs[$key])->exists();
                        if ($part === 'rooms') {
                            $typeId = RoomType::where('code', $sourceTypes[$attrs['room_type_id']] ?? '')->value('id');
                            $exists = $exists || ! $typeId;
                            $attrs = array_merge($attrs, ['room_type_id' => $typeId, 'hk_status' => 'vacant_clean', 'occupied' => false]);
                        }
                        if ($part === 'rate_plans') {
                            $attrs['company_id'] = null;
                            $attrs['parent_rate_plan_id'] = ! empty($attrs['parent_rate_plan_id'])
                                ? RatePlan::where('code', $sourcePlans[$attrs['parent_rate_plan_id']] ?? '')->value('id') : null;
                        }
                        if ($part === 'templates') {
                            $attrs['updated_by'] = $user->id;
                        }
                        if ($exists) {
                            $skipped++;

                            continue;
                        }
                        /** @var Model $new */
                        $new = new $class;
                        $new->setRawAttributes($attrs);
                        $new->save();
                        $copied++;
                    }
                    $result[$part] = ['copied' => $copied, 'skipped' => $skipped];
                }

                if (in_array('branding', $parts, true)) {
                    $fields = ['brand_color', 'accent_color', 'document_footer', 'bank_details'];
                    $update = array_filter($source->only($fields), fn ($v) => $v !== null);
                    if ($source->logo_path && Storage::disk(Letterhead::LOGO_DISK)->exists($source->logo_path)) {
                        $path = "branding/{$target->id}/".basename($source->logo_path);
                        Storage::disk(Letterhead::LOGO_DISK)->copy($source->logo_path, $path);
                        $update['logo_path'] = $path;
                    }
                    $target->update($update);
                    $result['branding'] = ['copied' => count($update), 'skipped' => 0];
                }

                $this->bus->record('onboarding.setup.copied', ['source_hotel_id' => $source->id, 'target_hotel_id' => $target->id, 'result' => $result], 'hotel', $target->id);

                return $result;
            });
        } finally {
            $this->ctx->set(...$saved);
        }
    }
}
