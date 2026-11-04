<?php

namespace App\Http\Controllers;

use App\Modules\Tenancy\Domain\Group;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public per-group pages: the booking website (routes/web/public.php) and the
 * careers site (routes/web/talent.php). A controller instead of closure
 * factories so `php artisan route:cache` serialises the routes correctly.
 */
class PublicGroupPageController
{
    public function hotels(Request $request, string $group): Response
    {
        return $this->booking($request, $group, 'Hotels');
    }

    public function search(Request $request, string $group): Response
    {
        return $this->booking($request, $group, 'Search');
    }

    public function details(Request $request, string $group): Response
    {
        return $this->booking($request, $group, 'Details');
    }

    public function confirmation(Request $request, string $group): Response
    {
        return $this->booking($request, $group, 'Confirmation');
    }

    public function manage(Request $request, string $group): Response
    {
        return $this->booking($request, $group, 'Manage');
    }

    public function careers(Request $request, string $group, ?string $post = null): Response
    {
        $g = $this->group($group);

        return Inertia::render('Careers/Index', [
            'group' => ['slug' => $g->slug, 'name' => $g->name],
            'postId' => $post ? (int) $post : null,
            'lang' => $this->lang($request),
        ]);
    }

    private function booking(Request $request, string $group, string $component): Response
    {
        $g = $this->group($group);

        return Inertia::render("Public/$component", [
            'group' => ['slug' => $g->slug, 'name' => $g->name],
            'lang' => $this->lang($request),
            'query' => $request->query(),
        ]);
    }

    private function group(string $slug): Group
    {
        return Group::where('slug', $slug)->where('status', 'active')->firstOrFail();
    }

    private function lang(Request $request): string
    {
        return in_array(request('lang'), ['fr', 'en'], true) ? request('lang') : 'fr';
    }
}
