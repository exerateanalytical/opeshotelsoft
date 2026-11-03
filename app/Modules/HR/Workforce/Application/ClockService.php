<?php

namespace App\Modules\HR\Workforce\Application;

use App\Modules\HR\Application\HrService;
use App\Modules\HR\Domain\Attendance;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\Shift;
use App\Modules\HR\Workforce\Domain\Anomaly;
use App\Modules\HR\Workforce\Domain\ClockEvent;
use App\Modules\HR\Workforce\Domain\Pin;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Clock in/out with geofence (phone) or PIN (hotel kiosk), optional photo proof and anomaly flags.
 * It drives the existing HrService clock so attendance, overtime and payroll keep one source of truth.
 */
class ClockService
{
    public function __construct(private HrService $hr, private RosterService $rosters, private EventBus $bus) {}

    public function setPin(Employee $e, string $pin): void
    {
        Pin::updateOrCreate(['employee_id' => $e->id], ['pin_hash' => Hash::make($pin), 'failed_attempts' => 0, 'locked_until' => null]);
    }

    /** Kiosk punch: employee number + PIN, from the hotel's own terminal (no geofence needed). */
    public function verifyPin(Employee $e, string $pin): void
    {
        $row = Pin::where('employee_id', $e->id)->first();
        if (! $row || ! $this->rosters->settings()->pin_enabled) {
            throw new DomainException('PIN clock-in is not available for this employee.', 'pin-disabled', 403);
        }
        if ($row->locked_until && $row->locked_until->isFuture()) {
            throw new DomainException('PIN locked. Try again later or ask HR.', 'pin-locked', 423);
        }
        if (! Hash::check($pin, $row->pin_hash)) {
            $fails = $row->failed_attempts + 1;
            $row->update(['failed_attempts' => $fails, 'locked_until' => $fails >= 5 ? now()->addMinutes(15) : null]);
            throw new DomainException('Wrong PIN.', 'pin-invalid', 403);
        }
        $row->update(['failed_attempts' => 0, 'locked_until' => null]);
    }

    /**
     * @param  array{lat?: ?float, lng?: ?float, accuracy?: ?int, photo?: ?string, device_id?: ?string, ip?: ?string}  $in
     * @return array{attendance: Attendance, event: ClockEvent, anomalies: array}
     */
    public function clock(Employee $e, string $direction, string $method, array $in = []): array
    {
        $s = $this->rosters->settings();
        $lat = isset($in['lat']) ? (float) $in['lat'] : null;
        $lng = isset($in['lng']) ? (float) $in['lng'] : null;
        $distance = null;
        $inside = null;
        if ($method !== 'pin' && $lat !== null && $lng !== null && $s->geo_lat !== null && $s->geo_lng !== null) {
            $distance = (int) round(self::distanceM($lat, $lng, (float) $s->geo_lat, (float) $s->geo_lng));
            $inside = $distance <= $s->geo_radius_m;
        }
        if ($method !== 'pin' && $s->geo_required && $s->geo_lat !== null && ($lat === null || $lng === null || $inside === false)) {
            throw new DomainException($inside === false ? "You are {$distance} m from the hotel (limit {$s->geo_radius_m} m)." : 'Location is required to clock.', 'outside-geofence');
        }
        if ($s->photo_required && empty($in['photo'])) {
            throw new DomainException('A photo is required to clock.', 'photo-required');
        }
        $attendance = $direction === 'in'
            ? $this->hr->clockIn($e, $method === 'pin' ? 'pin' : 'app')
            : $this->hr->clockOut($e);
        [$path, $hash] = $this->storePhoto($e, $in['photo'] ?? null);
        $event = ClockEvent::create([
            'employee_id' => $e->id, 'attendance_id' => $attendance->id, 'direction' => $direction, 'method' => $method, 'lat' => $lat, 'lng' => $lng,
            'accuracy_m' => $in['accuracy'] ?? null, 'distance_m' => $distance, 'inside_geofence' => $inside, 'photo_path' => $path, 'photo_hash' => $hash,
            'device_id' => $in['device_id'] ?? null, 'ip' => $in['ip'] ?? null, 'occurred_at' => now(),
        ]);
        $anomalies = $this->inspect($e, $event, $attendance, $s);

        return ['attendance' => $attendance, 'event' => $event, 'anomalies' => $anomalies];
    }

    private function storePhoto(Employee $e, ?string $dataUrl): array
    {
        if (! $dataUrl || ! preg_match('#^data:image/(png|jpe?g|webp);base64,(.+)$#', $dataUrl, $m)) {
            return [null, null];
        }
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > 2_000_000) {
            throw new DomainException('Invalid or oversized photo.', 'photo-invalid');
        }
        $path = "workforce/photos/{$e->hotel_id}/{$e->id}/".now()->format('YmdHis').'-'.bin2hex(random_bytes(4)).'.'.($m[1] === 'jpeg' ? 'jpg' : $m[1]);
        Storage::disk('local')->put($path, $bin);

        return [$path, hash('sha256', $bin)];
    }

    private function inspect(Employee $e, ClockEvent $ev, Attendance $a, $s): array
    {
        $found = [];
        $flag = function (string $kind, string $severity, array $detail) use ($e, $ev, $a, &$found) {
            $found[] = Anomaly::create(['employee_id' => $e->id, 'attendance_id' => $a->id, 'clock_event_id' => $ev->id, 'date' => $a->date, 'kind' => $kind, 'severity' => $severity, 'detail' => $detail]);
        };
        if ($ev->inside_geofence === false) {
            $flag('outside_geofence', 'high', ['distance_m' => $ev->distance_m, 'radius_m' => $s->geo_radius_m]);
        }
        if ($ev->direction === 'in') {
            $shift = Shift::where('employee_id', $e->id)->whereDate('date', $a->date)->orderBy('starts_at')->get()
                ->first(fn ($x) => $ev->occurred_at->lte(Carbon::parse($a->date->toDateString().' '.$x->ends_at)->addMinutes(1)));
            if ($shift) {
                $late = (int) Carbon::parse($a->date->toDateString().' '.$shift->starts_at)->diffInMinutes($ev->occurred_at, false);
                if ($late > $s->late_tolerance_min) {
                    $flag('late', 'warning', ['minutes_late' => $late, 'shift_starts_at' => substr($shift->starts_at, 0, 5)]);
                }
            }
        }
        // impossible travel: two GPS punches too far apart for the elapsed time
        if ($ev->lat !== null) {
            $prev = ClockEvent::where('employee_id', $e->id)->where('id', '<', $ev->id)->whereNotNull('lat')->where('occurred_at', '>=', now()->subHours(12))->orderByDesc('id')->first();
            if ($prev) {
                $km = self::distanceM($prev->lat, $prev->lng, $ev->lat, $ev->lng) / 1000;
                $hours = max(1 / 60, $prev->occurred_at->diffInSeconds($ev->occurred_at) / 3600);
                if ($km > 1 && $km / $hours > $s->max_speed_kmh) {
                    $flag('impossible_travel', 'high', ['km' => round($km, 1), 'minutes' => (int) round($hours * 60), 'kmh' => (int) round($km / $hours)]);
                }
            }
        }
        // buddy punching hints: the same phone used for two people, or the same photo reused by another person
        if ($ev->method !== 'pin' && $ev->device_id) {
            $other = ClockEvent::where('device_id', $ev->device_id)->where('employee_id', '!=', $e->id)->where('occurred_at', '>=', now()->subMinutes(10))->first();
            if ($other) {
                $flag('buddy_punch', 'high', ['reason' => 'same_device', 'other_employee_id' => $other->employee_id]);
            }
        }
        if ($ev->photo_hash) {
            $dup = ClockEvent::where('photo_hash', $ev->photo_hash)->where('employee_id', '!=', $e->id)->first();
            if ($dup) {
                $flag('buddy_punch', 'high', ['reason' => 'same_photo', 'other_employee_id' => $dup->employee_id]);
            }
        }
        foreach ($found as $f) {
            $this->bus->record('hr.attendance_anomaly', ['id' => $f->id, 'employee_id' => $e->id, 'kind' => $f->kind, 'severity' => $f->severity], 'attendance_anomaly', $f->id);
        }

        return $found;
    }

    /** Open attendances older than the configured limit become "missing clock-out" flags. Idempotent. */
    public function scanMissingClockOut(): int
    {
        $s = $this->rosters->settings();
        $n = 0;
        foreach (Attendance::whereNull('clock_out')->where('clock_in', '<', now()->subHours($s->missing_out_after_hours))->get() as $a) {
            if (Anomaly::where('attendance_id', $a->id)->where('kind', 'missing_clock_out')->exists()) {
                continue;
            }
            Anomaly::create(['employee_id' => $a->employee_id, 'attendance_id' => $a->id, 'date' => $a->date, 'kind' => 'missing_clock_out', 'severity' => 'warning', 'detail' => ['clock_in' => $a->clock_in->toIso8601String()]]);
            $n++;
        }

        return $n;
    }

    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
