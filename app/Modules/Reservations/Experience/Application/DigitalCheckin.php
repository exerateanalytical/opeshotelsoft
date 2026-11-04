<?php

namespace App\Modules\Reservations\Experience\Application;

use App\Modules\GuestPortal\Domain\OnlineCheckin;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Digital check-in: the guest (on their phone) or the front desk (on a tablet) fills the registration card ahead of
 * arrival: ID details and photo, ETA, a drawn signature. The data is copied to the guest profile so the front desk
 * check-in finds the ID it needs. readiness() tells the front desk, per arrival, what is still missing.
 */
class DigitalCheckin
{
    public const MAX_SIGNATURE = 300_000; // characters of PNG data URL

    public const MAX_PHOTO = 2_500_000;

    public function __construct(private EventBus $bus, private TenantContext $ctx) {}

    /** @param array<string,mixed> $d validated fields; id_photo and signature are data URLs */
    public function submit(Reservation $r, array $d, string $source = 'guest'): OnlineCheckin
    {
        if (! in_array($r->status, ['tentative', 'confirmed'], true)) {
            throw new DomainException('Digital check-in is only open for upcoming bookings.', 'checkin-closed');
        }

        return DB::transaction(function () use ($r, $d, $source) {
            $row = OnlineCheckin::firstOrNew(['reservation_id' => $r->id]);
            $row->fill([
                'group_id' => $r->group_id, 'hotel_id' => $r->hotel_id,
                'id_type' => $d['id_type'] ?? $row->id_type ?? $r->guest->id_type ?? 'passport', 'id_number' => $d['id_number'] ?? $row->id_number ?? $r->guest->id_number ?? '',
                'nationality' => $d['nationality'] ?? $row->nationality, 'eta' => $d['eta'] ?? $row->eta ?? $r->eta, 'notes' => $d['notes'] ?? $row->notes,
                'id_expiry' => $d['id_expiry'] ?? $row->id_expiry, 'id_country' => $d['id_country'] ?? $row->id_country,
                'birth_date' => $d['birth_date'] ?? $row->birth_date, 'address' => $d['address'] ?? $row->address,
                'submitted_at' => now(),
            ]);
            if (! empty($d['id_photo'])) {
                $row->id_photo_path = $this->storePhoto($r, $d['id_photo']);
            }
            if (! empty($d['signature'])) {
                if (! str_starts_with($d['signature'], 'data:image/png;base64,') || strlen($d['signature']) > self::MAX_SIGNATURE) {
                    throw new DomainException('The signature must be a PNG drawing under 300 KB.', 'bad-signature');
                }
                $row->signature = $d['signature'];
                $row->signed_at = now();
            }
            if (array_key_exists('card_accepted', $d)) {
                $row->card_accepted = (bool) $d['card_accepted'];
            }
            $row->save();

            // The front desk check-in reads the guest profile: keep it in step with what was captured.
            $guest = $r->guest;
            $guest->update(array_filter([
                'id_type' => $row->id_type ?: null, 'id_number' => $row->id_number ?: null, 'id_expiry' => $row->id_expiry,
                'nationality' => $row->nationality, 'date_of_birth' => $row->birth_date, 'address' => $row->address,
            ], fn ($v) => $v !== null && $v !== ''));
            if ($row->eta) {
                $r->forceFill(['eta' => $row->eta])->save();
            }
            $this->bus->record('reservation.digital_checkin', ['reservation_id' => $r->id, 'source' => $source, 'signed' => (bool) $row->signed_at], 'reservation', $r->id);

            return $row->refresh();
        });
    }

    /** @return array{status:string, items:array<string,bool>, missing:string[]} */
    public function readiness(Reservation $r): array
    {
        $c = OnlineCheckin::where('reservation_id', $r->id)->first();
        $guest = $r->guest;
        $idOk = (bool) (($c?->id_number ?: $guest?->id_number)) && ! ($c?->id_expiry && $c->id_expiry->lt($r->departure));
        $items = [
            'id' => $idOk,
            'card' => (bool) ($c?->card_accepted && $c->signed_at),
            'eta' => (bool) ($c?->eta ?? $r->eta),
            'payment' => (int) $r->deposit_paid >= (int) $r->deposit_required,
            'room' => (bool) $r->room_id,
        ];
        $required = ['id', 'card', 'payment', 'room'];
        $missing = array_values(array_filter($required, fn ($k) => ! $items[$k]));
        $status = ! $missing ? 'ready' : (($items['id'] || $items['card']) ? 'partial' : 'missing');

        return ['status' => $status, 'items' => $items, 'missing' => $missing];
    }

    /** Registration card pre-filled from profile, booking and any digital check-in data. */
    public function card(Reservation $r): array
    {
        $r->loadMissing('guest', 'roomType', 'room');
        $c = OnlineCheckin::where('reservation_id', $r->id)->first();
        $g = $r->guest;

        return [
            'reservation' => ['id' => $r->id, 'number' => $r->number, 'arrival' => $r->arrival->toDateString(), 'departure' => $r->departure->toDateString(), 'adults' => $r->adults, 'children' => $r->children, 'room_type' => $r->roomType?->name, 'room' => $r->room?->number, 'eta' => $c?->eta ?? $r->eta],
            'guest' => [
                'first_name' => $g->first_name, 'last_name' => $g->last_name, 'email' => $g->email, 'phone' => $g->phone,
                'nationality' => $c?->nationality ?? $g->nationality, 'birth_date' => ($c?->birth_date ?? $g->date_of_birth)?->toDateString(),
                'id_type' => $c?->id_type ?? $g->id_type, 'id_number' => $c?->id_number ?? $g->id_number, 'id_expiry' => ($c?->id_expiry ?? $g->id_expiry)?->toDateString(),
                'id_country' => $c?->id_country, 'address' => $c?->address ?? $g->address,
            ],
            'checkin' => ['submitted_at' => $c?->submitted_at, 'signed_at' => $c?->signed_at, 'card_accepted' => (bool) $c?->card_accepted, 'has_photo' => (bool) $c?->id_photo_path, 'signature' => $c?->signature],
            'readiness' => $this->readiness($r),
        ];
    }

    private function storePhoto(Reservation $r, string $dataUrl): string
    {
        if (! preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $dataUrl, $m) || strlen($dataUrl) > self::MAX_PHOTO) {
            throw new DomainException('The ID photo must be a JPEG, PNG or WebP image under 2 MB.', 'bad-photo');
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false) {
            throw new DomainException('The ID photo could not be read.', 'bad-photo');
        }
        // Content check, as in FrontDeskExtrasController::store(): the declared data-URL type is not trusted; the bytes
        // must really be a JPEG, PNG or WebP image, and the stored extension follows the detected type.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if ($ext === null) {
            throw new DomainException('The ID photo must be a JPEG, PNG or WebP image under 2 MB.', 'bad-photo');
        }
        $path = "checkins/{$r->hotel_id}/{$r->id}-id.".$ext;
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }
}
