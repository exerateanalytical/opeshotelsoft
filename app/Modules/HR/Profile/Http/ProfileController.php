<?php

namespace App\Modules\HR\Profile\Http;

use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Profile\Application\ProfileService;
use Illuminate\Http\Request;

/** H-C: GET hr/employees/{id}/profile (header, permitted sections, actions; ?full=1 adds every section) and .../profile/{section}. */
class ProfileController
{
    public function __construct(private ProfileService $profile) {}

    public function show(Request $request, Employee $employee)
    {
        $u = $request->user();
        abort_unless($this->profile->canViewCore($u), 403);

        $out = $this->profile->header($employee, $u);
        if ($request->boolean('full')) {
            $out['data'] = [];
            foreach ($out['sections'] as $key) {
                $out['data'][$key] = $this->profile->section($key, $employee, $u);
            }
        }

        return $out;
    }

    public function section(Request $request, Employee $employee, string $section)
    {
        $u = $request->user();
        abort_unless($this->profile->canViewCore($u), 403);
        // A section the caller may not see is reported as hidden (404), never as a data leak or a server error.
        abort_unless(in_array($section, $this->profile->sectionsFor($u), true), 404);

        return $this->profile->section($section, $employee, $u);
    }
}
