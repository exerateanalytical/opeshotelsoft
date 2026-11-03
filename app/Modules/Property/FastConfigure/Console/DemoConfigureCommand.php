<?php

namespace App\Modules\Property\FastConfigure\Console;

use App\Models\User;
use App\Modules\Property\FastConfigure\Application\FastConfigure;
use App\Modules\Property\FastConfigure\Application\Presets;
use App\Modules\Tenancy\Domain\Group;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** php artisan demo:configure resort-5-80 --hotel=KRI   (omit --hotel to create a new demo hotel) */
class DemoConfigureCommand extends Command
{
    protected $signature = 'demo:configure {preset : auberge-20, eco-3-40, business-4-120, resort-5-80} {--hotel= : existing hotel code or id (default: create a new hotel)} {--group= : group slug (default opes-demo, else the first group)} {--dry-run}';

    protected $description = 'Generate a whole room base (types, rooms, rate plans, outlets, stores) from a Fast Configure preset';

    public function handle(FastConfigure $svc, TenantContext $ctx): int
    {
        $key = (string) $this->argument('preset');
        if (! in_array($key, Presets::KEYS, true)) {
            $this->error('Unknown preset. Available: '.implode(', ', Presets::KEYS));

            return self::FAILURE;
        }
        $group = $ctx->withoutScope(fn () => $this->option('group') ? Group::where('slug', $this->option('group'))->first() : (Group::where('slug', 'opes-demo')->first() ?? Group::orderBy('id')->first()));
        if (! $group) {
            $this->error('No group found.');

            return self::FAILURE;
        }
        $actor = User::where('group_id', $group->id)->orderBy('id')->first();
        $ctx->set($group->id, null, null, $actor);
        $config = Presets::get($key);
        if ($h = $this->option('hotel')) {
            $hotel = $ctx->withoutScope(fn () => Hotel::where('group_id', $group->id)->where(fn ($q) => $q->where('code', $h)->orWhere('id', ctype_digit($h) ? (int) $h : 0))->first());
            if (! $hotel) {
                $this->error("Hotel $h not found in group {$group->slug}.");

                return self::FAILURE;
            }
            $config['hotel'] = ['id' => $hotel->id];
        }
        $r = $svc->apply($config, $actor, (bool) $this->option('dry-run'));
        $this->info(sprintf('%s %s (%s): rooms +%d, types +%d, rate plans +%d, outlets +%d, stores +%d%s', $r['dry_run'] ? 'DRY RUN' : 'Done', $r['hotel']['name'], $r['hotel']['code'], count($r['rooms']['created']), count($r['room_types']['created']), count($r['rate_plans']['created']), count($r['outlets']['created']), count($r['stores']['created']), $r['hotel']['created'] ? ' [new hotel]' : ''));

        return self::SUCCESS;
    }
}
