<?php

namespace App\Modules\Reservations\Experience\Application;

use App\Modules\Guests\Domain\Guest;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\RoomBlock;
use App\Modules\Reservations\Experience\Domain\RoomingImport;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Group rooming list import from CSV or Excel into a room block. Columns (English or French headers):
 * first_name/prenom, last_name/nom, email, phone/telephone, room_type/type (code), arrival/arrivee, departure/depart,
 * adults/adultes, children/enfants, notes. Blank dates default to the block dates.
 * validate() reports every row problem with its line number; import() creates the reservations from the block
 * (all or nothing, unless $skipInvalid).
 */
class RoomingImporter
{
    private const ALIASES = [
        'first_name' => ['first_name', 'firstname', 'prenom', 'prénom'], 'last_name' => ['last_name', 'lastname', 'nom'],
        'email' => ['email', 'e-mail', 'courriel'], 'phone' => ['phone', 'telephone', 'téléphone', 'tel'],
        'room_type' => ['room_type', 'type', 'type_chambre', 'room'], 'arrival' => ['arrival', 'arrivee', 'arrivée', 'check_in'],
        'departure' => ['departure', 'depart', 'départ', 'check_out'], 'adults' => ['adults', 'adultes'], 'children' => ['children', 'enfants'],
        'notes' => ['notes', 'remarques', 'special_requests'],
    ];

    public function __construct(private ReservationService $reservations, private TenantContext $ctx) {}

    public static function template(): string
    {
        return "first_name,last_name,email,phone,room_type,arrival,departure,adults,children,notes\nJean,Mballa,jean@example.cm,+237600000000,DLX,,,2,0,Late arrival\n";
    }

    /** @return array<int, array<string,mixed>> rows keyed by header, each with _line */
    public function read(string $path, string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') {
            $head = (string) fgets(fopen($path, 'r'));
            $o = new CsvOptions(FIELD_DELIMITER: substr_count($head, ";") > substr_count($head, ",") ? ";" : ",");
            $reader = new CsvReader($o);
        } elseif ($ext === 'xlsx') {
            $reader = new XlsxReader;
        } else {
            throw new DomainException('Upload a .csv or .xlsx file.', 'bad-file', 422);
        }
        $reader->open($path);
        $rows = [];
        $map = null;
        $line = 0;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $line++;
                $cells = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (is_string($v) ? trim($v) : $v), $row->toArray());
                if ($map === null) {
                    $map = $this->headerMap($cells);
                    if (! isset($map['last_name'], $map['room_type'])) {
                        throw new DomainException('The first row must contain the headers (at least last_name and room_type). Download the template.', 'bad-file', 422);
                    }

                    continue;
                }
                if (! array_filter($cells, fn ($v) => $v !== null && $v !== '')) {
                    continue;
                }
                $r = ['_line' => $line];
                foreach ($map as $key => $i) {
                    $r[$key] = $cells[$i] ?? null;
                }
                $rows[] = $r + array_fill_keys(array_keys(self::ALIASES), null);
            }
            break;
        }
        $reader->close();

        return $rows;
    }

    /** @return array{rows: array, errors: array<int, array{line:int, message:string}>} */
    public function validate(RoomBlock $block, array $rows): array
    {
        $types = RoomType::pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [strtoupper($code) => $id])->all();
        $errors = [];
        $good = [];
        $demand = [];
        $seen = [];
        foreach ($rows as $r) {
            $line = $r['_line'];
            $err = function (string $m) use (&$errors, $line) { $errors[] = ["line" => $line, "message" => $m]; };
            $before = count($errors);
            if (empty($r['last_name'])) {
                $err('Last name is missing.');
            }
            if (! empty($r['email']) && ! filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
                $err("Email '{$r['email']}' is not valid.");
            }
            $typeId = $types[strtoupper((string) ($r['room_type'] ?? ''))] ?? null;
            if (! $typeId) {
                $err("Room type '".($r['room_type'] ?? '')."' does not exist.");
            }
            $arr = $this->date($r['arrival'] ?? null) ?? $block->arrival->copy();
            $dep = $this->date($r['departure'] ?? null) ?? $block->departure->copy();
            if (($r['arrival'] ?? '') !== '' && ! $this->date($r['arrival'])) {
                $err("Arrival '{$r['arrival']}' is not a date (use YYYY-MM-DD).");
            }
            if (($r['departure'] ?? '') !== '' && ! $this->date($r['departure'])) {
                $err("Departure '{$r['departure']}' is not a date (use YYYY-MM-DD).");
            }
            if ($dep->lte($arr)) {
                $err('Departure must be after arrival.');
            } elseif ($arr->lt($block->arrival) || $dep->gt($block->departure)) {
                $err("Dates must fall inside the block ({$block->arrival->toDateString()} to {$block->departure->toDateString()}).");
            }
            $adults = (int) ($r['adults'] ?? 0 ?: 1);
            if ($adults < 1 || $adults > 8) {
                $err('Adults must be between 1 and 8.');
            }
            $key = strtolower(($r['first_name'] ?? '').'|'.$r['last_name'].'|'.($r['email'] ?? '')).'|'.$typeId.'|'.$arr->toDateString().'|'.$dep->toDateString();
            if (isset($seen[$key])) {
                $err("Duplicate of line {$seen[$key]}.");
            }
            $seen[$key] ??= $line;
            if (count($errors) === $before && $typeId) {
                for ($d = $arr->copy(); $d->lt($dep); $d->addDay()) {
                    $demand[$typeId][$d->toDateString()][] = $line;
                }
                $good[] = ['line' => $line, 'first_name' => $r['first_name'] ?? '', 'last_name' => $r['last_name'], 'email' => $r['email'] ?? null, 'phone' => $r['phone'] ?? null,
                    'room_type_id' => $typeId, 'arrival' => $arr->toDateString(), 'departure' => $dep->toDateString(), 'adults' => $adults, 'children' => (int) ($r['children'] ?? 0), 'notes' => $r['notes'] ?? null];
            }
        }
        // Block capacity: rooms left per type and night after counting every row of the file.
        foreach ($demand as $typeId => $days) {
            foreach ($days as $d => $lines) {
                $alloc = $block->allocations()->where('room_type_id', $typeId)->where('date', $d)->first();
                $left = $alloc ? $alloc->rooms - $alloc->picked_up : 0;
                if (count($lines) > $left) {
                    foreach (array_slice($lines, $left) as $line) {
                        $errors[] = ['line' => $line, 'message' => "The block has only $left room(s) of this type left on $d."];
                    }
                    $good = array_values(array_filter($good, fn ($g) => ! in_array($g['line'], array_slice($lines, $left), true)));
                }
            }
        }
        usort($errors, fn ($a, $b) => $a['line'] <=> $b['line']);

        return ['rows' => $good, 'errors' => $errors];
    }

    /** Validate (and, when $commit, create). @return array{status:string, rows_total:int, rows_ok:int, errors:array, created:array} */
    public function import(RoomBlock $block, string $path, string $filename, bool $commit, ?int $ratePlanId = null, bool $skipInvalid = false): array
    {
        $rows = $this->read($path, $filename);
        $v = $this->validate($block, $rows);
        $planId = $ratePlanId ?? $block->rate_plan_id ?? throw new DomainException('Choose a rate plan: the block has none.', 'rate-plan-required', 422);
        $created = [];
        $status = 'validated';
        if ($commit && $v['rows'] && ($skipInvalid || ! $v['errors'])) {
            DB::transaction(function () use ($v, $block, $planId, &$created) {
                foreach ($v['rows'] as $g) {
                    $guest = ($g['email'] ? Guest::where('email', $g['email'])->first() : null)
                        ?? Guest::create(['first_name' => $g['first_name'] ?: '-', 'last_name' => $g['last_name'], 'email' => $g['email'], 'phone' => $g['phone']]);
                    $r = $this->reservations->create([
                        'guest_id' => $guest->id, 'room_type_id' => $g['room_type_id'], 'rate_plan_id' => $planId, 'arrival' => $g['arrival'], 'departure' => $g['departure'],
                        'adults' => $g['adults'], 'children' => $g['children'], 'room_block_id' => $block->id, 'company_id' => $block->company_id,
                        'booking_ref' => $block->code, 'channel' => 'corporate', 'enforce_restrictions' => false, 'special_requests' => $g['notes'],
                    ]);
                    $created[] = ['line' => $g['line'], 'reservation_id' => $r->id, 'number' => $r->number];
                }
            });
            $status = 'imported';
        } elseif ($commit) {
            $status = 'failed';
        }
        RoomingImport::create([
            'room_block_id' => $block->id, 'filename' => $filename, 'status' => $status, 'rows_total' => count($rows), 'rows_ok' => $status === 'imported' ? count($created) : count($v['rows']),
            'errors' => $v['errors'], 'created_by' => $this->ctx->actor()?->id,
        ]);

        return ['status' => $status, 'rows_total' => count($rows), 'rows_ok' => count($v['rows']), 'errors' => $v['errors'], 'created' => $created];
    }

    private function headerMap(array $cells): array
    {
        $map = [];
        foreach ($cells as $i => $h) {
            $h = strtolower(trim((string) $h));
            foreach (self::ALIASES as $key => $names) {
                if (in_array($h, $names, true) && ! isset($map[$key])) {
                    $map[$key] = $i;
                }
            }
        }

        return $map;
    }

    private function date(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = trim((string) $v);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $f) {
            $d = rescue(fn () => Carbon::createFromFormat('!'.$f, $v), null, false);
            if ($d && $d->format($f) === $v) {
                return $d;
            }
        }

        return null;
    }
}
