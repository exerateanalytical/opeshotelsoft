<?php

namespace App\Platform\I18n;

use Illuminate\Contracts\Translation\Loader;

/**
 * Decorates the file loader so JSON lookups (__('srv.x')) also see the
 * per-module fragments in lang/<locale>/*.json. lang/<locale>.json keeps priority.
 */
final class JsonFragmentLoader implements Loader
{
    public function __construct(private Loader $inner, private string $langPath) {}

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->inner->load($locale, $group, $namespace);
        if ($group !== '*' || $namespace !== '*' || ! preg_match('/^[A-Za-z_-]+$/', (string) $locale)) {
            return $lines;
        }
        foreach (glob($this->langPath.DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $lines += json_decode((string) file_get_contents($file), true) ?: [];
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint)
    {
        $this->inner->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->inner->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->inner->namespaces();
    }

    public function __call($method, $args)
    {
        return $this->inner->{$method}(...$args);
    }
}
