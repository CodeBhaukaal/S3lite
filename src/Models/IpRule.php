<?php
declare(strict_types=1);

namespace App\Models;

final class IpRule extends Model
{
    protected static string $table = 'ip_rules';
    protected static bool $timestamps = false;

    /** CIDR / exact / wildcard match. */
    public static function matches(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);

        if ($cidr === '' || $cidr === '*') {
            return true;
        }

        if ($cidr === $ip) {
            return true;
        }

        if (str_contains($cidr, '*')) {
            $pattern = '/^' . str_replace('\*', '.*', preg_quote($cidr, '/')) . '$/';

            return preg_match($pattern, $ip) === 1;
        }

        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipIsV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $subnetIsV4 = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;

        if (!$ipIsV4 || !$subnetIsV4) {
            return self::matchesV6($ip, $subnet, $bits);
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private static function matchesV6(string $ip, string $subnet, int $bits): bool
    {
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xff;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /**
     * @return array{allowed:bool, rule:?array}
     */
    public static function evaluate(string $ip, string $scope = 'global'): array
    {
        $rules = self::where(['scope' => ['in', [$scope, 'global']]], 'id ASC', 500);

        $allowRules = array_filter($rules, static fn (array $r): bool => $r['type'] === 'allow');

        foreach ($rules as $rule) {
            if ($rule['type'] === 'block' && self::matches($ip, (string) $rule['cidr'])) {
                return ['allowed' => false, 'rule' => $rule];
            }
        }

        // An allowlist, once populated, becomes exclusive.
        if ($allowRules !== []) {
            foreach ($allowRules as $rule) {
                if (self::matches($ip, (string) $rule['cidr'])) {
                    return ['allowed' => true, 'rule' => $rule];
                }
            }

            return ['allowed' => false, 'rule' => null];
        }

        return ['allowed' => true, 'rule' => null];
    }
}
