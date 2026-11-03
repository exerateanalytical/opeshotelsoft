<?php

namespace App\Modules\Admin\Http;

use App\Modules\Admin\Domain\Currency;
use App\Modules\Admin\Domain\ExchangeRate;
use App\Platform\Domain\DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CurrencyController
{
    public function index(Request $request)
    {
        $this->can($request, 'view');

        return Currency::orderByDesc('is_functional')->orderBy('code')->get()->map(fn (Currency $c) => [
            ...$c->toArray(),
            'latest_rate' => ExchangeRate::rateOn($c->code, now()->toDateString()),
        ]);
    }

    public function store(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'size:3', 'alpha'],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:8'],
            'decimals' => ['required', 'integer', 'between:0,4'],
            'active' => ['boolean'],
        ]);
        $data['code'] = strtoupper($data['code']);
        if (Currency::find($data['code'])) {
            throw new DomainException(__('admin.currency.exists'), 'currency-exists');
        }

        return response()->json(Currency::create($data), 201);
    }

    public function update(Request $request, string $code)
    {
        $this->can($request, 'manage');
        $currency = Currency::findOrFail(strtoupper($code));
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:8'],
            'decimals' => ['sometimes', 'integer', 'between:0,4'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if ($currency->is_functional && (($data['active'] ?? true) == false || (isset($data['decimals']) && (int) $data['decimals'] !== 0))) {
            throw new DomainException(__('admin.currency.functional_locked'), 'functional-currency');
        }
        $currency->update($data);

        return $currency;
    }

    public function destroy(Request $request, string $code)
    {
        $this->can($request, 'manage');
        $currency = Currency::findOrFail(strtoupper($code));
        if ($currency->is_functional) {
            throw new DomainException(__('admin.currency.functional_locked'), 'functional-currency');
        }
        ExchangeRate::where('from', $currency->code)->delete();
        $currency->delete();

        return response()->noContent();
    }

    public function rates(Request $request)
    {
        $this->can($request, 'view');

        return ExchangeRate::where('to', Currency::FUNCTIONAL)
            ->when($request->query('currency'), fn ($q, $v) => $q->where('from', strtoupper($v)))
            ->orderByDesc('date')->orderBy('from')
            ->paginate(min(max((int) $request->query('per_page', 50), 1), 200));
    }

    public function storeRate(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'date' => ['required', 'date'],
            'rate' => ['required', 'numeric', 'gt:0'],
        ]);
        $from = strtoupper($data['currency']);
        if ($from === Currency::FUNCTIONAL) {
            throw new DomainException(__('admin.currency.functional_rate'), 'functional-currency');
        }
        $rate = ExchangeRate::updateOrCreate(
            ['from' => $from, 'to' => Currency::FUNCTIONAL, 'date' => $data['date']],
            ['rate' => $data['rate']],
        );

        return response()->json($rate, 201);
    }

    public function destroyRate(Request $request, int $id)
    {
        $this->can($request, 'manage');
        ExchangeRate::where('to', Currency::FUNCTIONAL)->findOrFail($id)->delete();

        return response()->noContent();
    }

    /** Rate to XAF for a currency on a date (latest on or before it). */
    public function lookup(Request $request)
    {
        $this->can($request, 'view');
        $data = $request->validate(['currency' => ['required', 'string', 'size:3'], 'date' => ['nullable', 'date']]);
        $date = $data['date'] ?? now()->toDateString();
        $rate = ExchangeRate::rateOn($data['currency'], $date);
        if ($rate === null) {
            throw new DomainException(__('admin.currency.no_rate'), 'no-rate', 404);
        }

        return ['currency' => strtoupper($data['currency']), 'to' => Currency::FUNCTIONAL, 'date' => $date, 'rate' => $rate];
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('currencies.manage') || ($ability === 'view' && $u->can('currencies.view')), 403);
    }
}
