<?php

namespace App\Modules\People\Http;

use App\Modules\HR\Domain\Employee;
use App\Modules\People\Application\EmployeeDirectory;
use Illuminate\Http\Request;

trait Authorizes
{
    /** Abort 403 unless the user holds one of the permissions. */
    private function need(Request $request, string ...$permissions): void
    {
        if (! $this->has($request, ...$permissions)) {
            abort(403);
        }
    }

    private function has(Request $request, string ...$permissions): bool
    {
        foreach ($permissions as $p) {
            if ($request->user()->can($p)) {
                return true;
            }
        }

        return false;
    }

    private function me(Request $request): ?Employee
    {
        return app(EmployeeDirectory::class)->forUser($request->user()->id);
    }

    /** Attach employee summaries ({id, name, number, department}) to rows for each *_employee_id / employee_id column. */
    private function withNames($rows, string ...$columns)
    {
        $collection = collect($rows instanceof \Illuminate\Contracts\Pagination\Paginator ? $rows->items() : $rows);
        $ids = $collection->flatMap(fn ($r) => collect($columns)->map(fn ($c) => $r->{$c}))->filter()->all();
        $names = app(EmployeeDirectory::class)->summaries($ids);
        $collection->each(function ($r) use ($columns, $names) {
            foreach ($columns as $c) {
                $r->setAttribute(str_replace('_id', '', $c), $names[$r->{$c}] ?? null);
            }
        });

        return $rows;
    }
}
