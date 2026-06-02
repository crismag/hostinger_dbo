<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Applies an environment profile (dev/demo/prod) over the base security config.
 *
 * Profiles are opt-in: with no `APP_ENV` set, the written `config/security.php`
 * is used unchanged (backward compatible). When `APP_ENV` names a known profile,
 * its overrides are deep-merged on top — so a profile can flip policy knobs
 * (require_https, dev_mode, audit.mode, public_demo.enabled, …) without
 * restating the whole config.
 */
final class Profiles
{
    /**
     * @param array<string, mixed> $security base config from config/security.php
     * @param array<string, array<string, mixed>>|null $profiles map from config/profiles.php
     * @return array<string, mixed>
     */
    public static function apply(array $security, ?string $env, ?array $profiles): array
    {
        if ($env === null || $env === '' || $profiles === null) {
            return $security;
        }
        $override = $profiles[$env] ?? null;
        if (!is_array($override)) {
            return $security; // unknown profile is a no-op, never a hard failure
        }
        return self::merge($security, $override);
    }

    /**
     * Recursive merge: associative arrays merge by key; scalars and list arrays
     * replace. (A profile replacing `trusted_proxies` swaps the whole list.)
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && !array_is_list($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
