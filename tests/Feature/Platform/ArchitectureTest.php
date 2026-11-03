<?php

namespace Tests\Feature\Platform;

use App\Platform\Tenancy\BelongsToGroup;
use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/** Certification checks on the architecture rules (SPEC §7). */
class ArchitectureTest extends TestCase
{
    /**
     * Child rows reached only through a tenant-scoped parent aggregate. Each
     * entry names the parent that guards access.
     */
    private const CHILD_ENTITIES = [
        'App\Modules\FrontOffice\Domain\FolioLine' => 'Folio',
        'App\Modules\Accounting\Domain\JournalLine' => 'JournalEntry',
        'App\Modules\POS\Domain\Payment' => 'Check',
        'App\Modules\POS\Domain\CheckLine' => 'Check',
        'App\Modules\Loyalty\Domain\Transaction' => 'Membership',
        'App\Modules\GuestPortal\Domain\OnlineCheckin' => 'Reservation',
    ];

    /** @return array<class-string<Model>> */
    private function domainModels(): array
    {
        $out = [];
        foreach ((new Finder)->files()->in(app_path('Modules'))->path('Domain')->name('*.php') as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getRealPath(), strlen(app_path()) + 1));
            $class = str_replace('\\\\', '\\', $class);
            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $out[] = $class;
            }
        }

        return $out;
    }

    public function test_every_tenant_table_model_is_tenant_scoped(): void
    {
        $violations = [];
        foreach ($this->domainModels() as $class) {
            $model = new $class;
            $traits = class_uses_recursive($class);
            $table = $model->getTable();
            $hasHotel = Schema::hasColumn($table, 'hotel_id');
            $hasGroup = Schema::hasColumn($table, 'group_id');
            $scoped = isset($traits[BelongsToHotel::class]) || isset($traits[BelongsToGroup::class])
                || method_exists($class, 'booted') && str_contains(file_get_contents((new ReflectionClass($class))->getFileName()), 'addGlobalScope');

            if (($hasHotel || $hasGroup) && ! $scoped && ! isset(self::CHILD_ENTITIES[$class])) {
                $violations[] = "$class ($table)";
            }
        }

        $this->assertSame([], $violations, 'Tenant tables without a tenant scope: '.implode(', ', $violations));
    }

    public function test_financial_records_have_no_delete_endpoints(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => in_array('DELETE', $r->methods(), true))->map->uri()->values()->all();
        foreach (['folios', 'invoices', 'accounting', 'payroll', 'payables', 'pos/checks', 'stock/movements'] as $financial) {
            foreach ($routes as $uri) {
                $this->assertStringNotContainsString($financial, $uri, "DELETE route on financial resource: $uri");
            }
        }
    }

    public function test_platform_layer_does_not_depend_on_feature_modules(): void
    {
        $allowed = ['App\Modules\Tenancy\Domain\Hotel', 'App\Modules\Platform\Application\QueueWebhookDeliveries'];
        foreach ((new Finder)->files()->in(app_path('Platform'))->name('*.php') as $file) {
            preg_match_all('/App\\\\Modules\\\\[\w\\\\]+/', $file->getContents(), $m);
            foreach (array_unique($m[0]) as $dep) {
                $this->assertContains($dep, $allowed, "{$file->getFilename()} depends on $dep");
            }
        }
    }

    public function test_no_module_imports_another_modules_http_layer(): void
    {
        foreach ((new Finder)->files()->in(app_path('Modules'))->name('*.php') as $file) {
            $own = explode(DIRECTORY_SEPARATOR, substr($file->getRealPath(), strlen(app_path('Modules')) + 1))[0];
            preg_match_all('/use App\\\\Modules\\\\(\w+)\\\\Http\\\\/', $file->getContents(), $m);
            foreach ($m[1] as $module) {
                $this->assertSame($own, $module, "{$file->getRelativePathname()} reaches into $module\\Http");
            }
        }
    }
}
