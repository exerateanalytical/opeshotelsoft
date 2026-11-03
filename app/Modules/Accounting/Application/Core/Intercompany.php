<?php

namespace App\Modules\Accounting\Application\Core;

use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\ChartOfAccounts as C;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Transactions between two hotels of the same group. Both sides are posted in one database transaction
 * with mirror entries, so the intercompany accounts always cancel at group level:
 *
 *   service    from: Dr 451100 / Cr revenue          to: Dr expense / Cr 451200
 *   funding    from: Dr 451100 / Cr treasury         to: Dr treasury / Cr 451200   (cash lent by `from` to `to`)
 *   settlement from: Dr treasury / Cr 451100         to: Dr 451200 / Cr treasury   (`to` pays back `from`)
 *
 * 451100 / 451200 are intra-group current accounts (SYSCOHADA class 45); to be validated by the chartered accountant.
 */
class Intercompany
{
    public const RECEIVABLE = '451100';

    public const PAYABLE = '451200';

    public function __construct(private Ledger $ledger, private TenantContext $ctx, private AuditTrail $audit) {}

    public function list(?string $from = null, ?string $to = null): array
    {
        return DB::table('intercompany_transactions as t')->join('hotels as f', 'f.id', '=', 't.from_hotel_id')->join('hotels as o', 'o.id', '=', 't.to_hotel_id')
            ->where('t.group_id', $this->ctx->groupId())
            ->when($from, fn ($q, $v) => $q->where('t.date', '>=', $v))->when($to, fn ($q, $v) => $q->where('t.date', '<=', $v))
            ->orderByDesc('t.date')->orderByDesc('t.id')->get(['t.*', 'f.code as from_code', 'o.code as to_code'])->all();
    }

    public function post(array $d): array
    {
        $actor = $this->ctx->actor();
        $hotels = Hotel::whereIn('id', [(int) $d['from_hotel_id'], (int) $d['to_hotel_id']])->get()->keyBy('id');
        if ((int) $d['from_hotel_id'] === (int) $d['to_hotel_id'] || $hotels->count() !== 2) {
            throw new DomainException('Intercompany needs two different hotels of the same group.', 'invalid-hotels');
        }
        $allowed = $this->ctx->allowedHotelIds();
        if ($allowed !== null && array_diff([(int) $d['from_hotel_id'], (int) $d['to_hotel_id']], $allowed)) {
            throw new DomainException('You do not have access to both hotels.', 'forbidden-hotel');
        }
        $kind = $d['kind'];
        $amount = (int) $d['amount'];
        if ($amount <= 0 || ! in_array($kind, ['service', 'funding', 'settlement'], true)) {
            throw new DomainException('Invalid intercompany transaction.', 'invalid-intercompany');
        }
        $from = $hotels[(int) $d['from_hotel_id']];
        $to = $hotels[(int) $d['to_hotel_id']];

        return DB::transaction(function () use ($d, $kind, $amount, $from, $to, $actor) {
            $id = DB::table('intercompany_transactions')->insertGetId([
                'group_id' => $this->ctx->groupId(), 'from_hotel_id' => $from->id, 'to_hotel_id' => $to->id, 'kind' => $kind, 'date' => $d['date'], 'amount' => $amount,
                'description' => $d['description'], 'revenue_account' => $kind === 'service' ? ($d['revenue_account'] ?? C::role('other_revenue')) : null,
                'expense_account' => $kind === 'service' ? ($d['expense_account'] ?? C::role('other_expense')) : null,
                'treasury_account' => $kind !== 'service' ? ($d['treasury_account'] ?? C::role('bank')) : null,
                'department' => $d['department'] ?? null, 'status' => 'posted', 'created_by' => $actor?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $t = (object) DB::table('intercompany_transactions')->find($id);
            $desc = "Intragroupe {$from->code}→{$to->code}: {$t->description}";
            $dept = ['department' => $t->department];

            $fromLines = match ($kind) {
                'service' => [[self::RECEIVABLE, $amount], [$t->revenue_account, -$amount, $dept]],
                'funding' => [[self::RECEIVABLE, $amount], [$t->treasury_account, -$amount]],
                'settlement' => [[$t->treasury_account, $amount], [self::RECEIVABLE, -$amount]],
            };
            $toLines = match ($kind) {
                'service' => [[$t->expense_account, $amount, $dept], [self::PAYABLE, -$amount]],
                'funding' => [[$t->treasury_account, $amount], [self::PAYABLE, -$amount]],
                'settlement' => [[self::PAYABLE, $amount], [$t->treasury_account, -$amount]],
            };
            $journal = $kind === 'service' ? 'OD' : 'BQ';
            $entries = [];
            foreach ([['from', $from, $fromLines], ['to', $to, $toLines]] as [$side, $hotel, $lines]) {
                $entries[$side] = HotelScope::in($hotel, function () use ($lines, $journal, $desc, $t, $side) {
                    $this->ensureAccounts();
                    $e = $this->ledger->post($journal, $t->date, $desc, $lines, ['source_type' => 'intercompany', 'source_id' => $t->id, 'source_event' => "intercompany:{$t->id}:$side"]);
                    app(AuditTrail::class)->record('intercompany_posted', 'intercompany', $t->id, ['side' => $side, 'entry' => $e?->number, 'amount' => $t->amount]);

                    return $e;
                });
            }
            DB::table('intercompany_transactions')->where('id', $id)->update(['from_entry_id' => $entries['from']?->id, 'to_entry_id' => $entries['to']?->id]);

            return (array) DB::table('intercompany_transactions')->find($id);
        });
    }

    /** Reverse both sides (never deleted). */
    public function reverse(int $id, string $reason): array
    {
        $t = DB::table('intercompany_transactions')->where(['id' => $id, 'group_id' => $this->ctx->groupId()])->first() ?? throw new DomainException('Transaction not found.', 'not-found');
        if ($t->status === 'reversed') {
            throw new DomainException('Already reversed.', 'already-reversed');
        }

        return DB::transaction(function () use ($t, $reason) {
            foreach ([[$t->from_hotel_id, $t->from_entry_id], [$t->to_hotel_id, $t->to_entry_id]] as [$hid, $eid]) {
                if (! $eid) {
                    continue;
                }
                HotelScope::in(Hotel::findOrFail($hid), function () use ($eid, $reason) {
                    $this->ledger->reverse(JournalEntry::findOrFail($eid), $reason);
                });
            }
            DB::table('intercompany_transactions')->where('id', $t->id)->update(['status' => 'reversed', 'updated_at' => now()]);

            return (array) DB::table('intercompany_transactions')->find($t->id);
        });
    }

    private function ensureAccounts(): void
    {
        foreach ([self::RECEIVABLE => ['Comptes courants - créances intragroupe', 'asset'], self::PAYABLE => ['Comptes courants - dettes intragroupe', 'liability']] as $code => [$name, $type]) {
            if (! Account::where('code', $code)->exists()) {
                Account::create(['code' => $code, 'name' => $name, 'class' => 4, 'type' => $type, 'postable' => true, 'active' => true]);
            }
        }
    }
}
