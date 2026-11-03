<?php

namespace App\Modules\Accounting\Http\Ab;

use App\Modules\Accounting\Close\CashForecast;
use App\Modules\Accounting\Close\MonthEndClose;
use App\Modules\Accounting\Close\StatementsPack;
use Illuminate\Http\Request;

/** A-B close endpoints under /api/v1/accounting3/close: statements pack, 13-week forecast, month-end checklist. */
class CloseController
{
    private function can(Request $r, string ...$perms): void
    {
        abort_unless(collect($perms)->contains(fn ($p) => $r->user()->can($p)), 403);
    }

    private function period(Request $r): array
    {
        $d = $r->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);

        return [$d['from'], $d['to']];
    }

    // ---- statements ----

    public function statements(Request $r, StatementsPack $s)
    {
        $this->can($r, 'accounting.statements');
        [$from, $to] = $this->period($r);
        $p = $s->build($from, $to);
        if ($r->query('format') === 'xlsx') {
            return response($s->excel($p), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"syscohada-$from-$to.xlsx\""]);
        }

        return $p;
    }

    public function savePack(Request $r, StatementsPack $s)
    {
        $this->can($r, 'accounting.statements');
        [$from, $to] = $this->period($r);

        return response()->json($s->save($from, $to), 201);
    }

    public function packs(Request $r, StatementsPack $s)
    {
        $this->can($r, 'accounting.statements');

        return $s->packs();
    }

    public function pack(Request $r, StatementsPack $s, int $id)
    {
        $this->can($r, 'accounting.statements');
        $p = $s->show($id);
        if ($r->query('format') === 'xlsx') {
            return response($s->excel($p['payload']), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"syscohada-pack-$id.xlsx\""]);
        }

        return $p;
    }

    public function finalizePack(Request $r, StatementsPack $s, int $id)
    {
        $this->can($r, 'accounting.statements');

        return $s->finalize($id);
    }

    // ---- forecast ----

    public function forecast(Request $r, CashForecast $f)
    {
        $this->can($r, 'accounting.forecast');
        $d = $r->validate(['start' => ['nullable', 'date'], 'weeks' => ['nullable', 'integer', 'min:1', 'max:26'], 'collection_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'include_pending' => ['nullable', 'boolean'], 'include_budget' => ['nullable', 'boolean']]);

        return $f->build($d['start'] ?? null, ['weeks' => $d['weeks'] ?? 13, 'collection_pct' => $d['collection_pct'] ?? 100,
            'include_pending' => $r->boolean('include_pending', true), 'include_budget' => $r->boolean('include_budget', false)]);
    }

    // ---- checklist ----

    public function templates(Request $r, MonthEndClose $c)
    {
        $this->can($r, 'accounting.close', 'accounting.close_manage');

        return ['templates' => $c->templates(), 'checks' => MonthEndClose::checks()];
    }

    public function saveTemplate(Request $r, MonthEndClose $c, ?int $id = null)
    {
        $this->can($r, 'accounting.close_manage');
        $d = $r->validate(['title' => ['required', 'string', 'max:200'], 'owner_role' => ['nullable', 'string', 'max:60'], 'due_offset_days' => ['integer', 'min:0', 'max:60'],
            'position' => ['integer', 'min:0'], 'required' => ['boolean'], 'auto_check' => ['nullable', 'string', 'max:40'], 'active' => ['boolean']]);

        return response()->json($c->saveTemplate($d, $id), $id ? 200 : 201);
    }

    public function runs(Request $r, MonthEndClose $c)
    {
        $this->can($r, 'accounting.close', 'accounting.close_manage');

        return $c->runs();
    }

    public function start(Request $r, MonthEndClose $c)
    {
        $this->can($r, 'accounting.close_manage');
        $d = $r->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100'], 'month' => ['required', 'integer', 'min:1', 'max:12'], 'owners' => ['nullable', 'array']]);

        return response()->json($c->start($d['year'], $d['month'], $d['owners'] ?? []), 201);
    }

    public function run(Request $r, MonthEndClose $c, int $year, int $month)
    {
        $this->can($r, 'accounting.close', 'accounting.close_manage');

        return $c->show($year, $month);
    }

    public function assign(Request $r, MonthEndClose $c, int $id)
    {
        $this->can($r, 'accounting.close_manage');
        $d = $r->validate(['owner_id' => ['nullable', 'integer', 'exists:users,id']]);

        return $c->assign($id, $d['owner_id'] ?? null);
    }

    public function check(Request $r, MonthEndClose $c, int $id)
    {
        $this->can($r, 'accounting.close', 'accounting.close_manage');

        return $c->check($id);
    }

    public function sign(Request $r, MonthEndClose $c, int $id)
    {
        $this->can($r, 'accounting.close', 'accounting.close_manage');
        $d = $r->validate(['note' => ['nullable', 'string', 'max:1000'], 'override_reason' => ['nullable', 'string', 'max:500']]);

        return $c->sign($id, $r->user(), $d['note'] ?? null, $d['override_reason'] ?? null);
    }
}
