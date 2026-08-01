<?php
declare(strict_types=1);

namespace Tests;

/**
 * Minimal test harness — no PHPUnit dependency, so the suite runs on a bare
 * PHP install exactly like the rest of the platform.
 */
abstract class TestCase
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static int $skipped = 0;
    /** @var list<string> */
    public static array $failures = [];

    private string $currentTest = '';

    abstract public function name(): string;

    /** @return list<string> Method names to execute. */
    public function tests(): array
    {
        $methods = [];

        foreach (get_class_methods($this) as $method) {
            if (str_starts_with($method, 'test')) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function run(): void
    {
        echo PHP_EOL . '  ' . $this->name() . PHP_EOL;
        echo '  ' . str_repeat('-', max(10, strlen($this->name()))) . PHP_EOL;

        foreach ($this->tests() as $method) {
            $this->currentTest = $method;

            try {
                $this->setUp();
                $this->{$method}();
                $this->tearDown();
            } catch (SkipTest $e) {
                self::$skipped++;
                echo '  [skip] ' . $this->label($method) . ' — ' . $e->getMessage() . PHP_EOL;
                continue;
            } catch (\Throwable $e) {
                self::$failed++;
                $message = $this->label($method) . ' — ' . $e->getMessage();
                self::$failures[] = $message;
                echo '  [FAIL] ' . $message . PHP_EOL;

                if (!$e instanceof AssertionFailed) {
                    echo '         ' . $e::class . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL;
                }
                continue;
            }

            self::$passed++;
            echo '  [ ok ] ' . $this->label($method) . PHP_EOL;
        }
    }

    private function label(string $method): string
    {
        $label = preg_replace('/^test/', '', $method) ?? $method;
        $label = preg_replace('/(?<!^)[A-Z]/', ' $0', $label) ?? $label;

        return trim(strtolower($label));
    }

    // --- Assertions -------------------------------------------------------

    protected function assertTrue(mixed $value, string $message = ''): void
    {
        if ($value !== true) {
            throw new AssertionFailed($message !== '' ? $message : 'Expected true, got ' . $this->describe($value));
        }
    }

    protected function assertFalse(mixed $value, string $message = ''): void
    {
        if ($value !== false) {
            throw new AssertionFailed($message !== '' ? $message : 'Expected false, got ' . $this->describe($value));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ': ' : '')
                . 'expected ' . $this->describe($expected) . ', got ' . $this->describe($actual)
            );
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ': ' : '')
                . 'expected ' . $this->describe($expected) . ', got ' . $this->describe($actual)
            );
        }
    }

    protected function assertNotEmpty(mixed $value, string $message = ''): void
    {
        if (empty($value)) {
            throw new AssertionFailed($message !== '' ? $message : 'Expected a non-empty value.');
        }
    }

    protected function assertNull(mixed $value, string $message = ''): void
    {
        if ($value !== null) {
            throw new AssertionFailed($message !== '' ? $message : 'Expected null, got ' . $this->describe($value));
        }
    }

    protected function assertNotNull(mixed $value, string $message = ''): void
    {
        if ($value === null) {
            throw new AssertionFailed($message !== '' ? $message : 'Expected a value, got null.');
        }
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ': ' : '')
                . '"' . $needle . '" not found in: ' . mb_substr($haystack, 0, 300)
            );
        }
    }

    protected function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ': ' : '') . '"' . $needle . '" should not appear in the output.'
            );
        }
    }

    protected function assertCount(int $expected, array $actual, string $message = ''): void
    {
        if (count($actual) !== $expected) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ': ' : '') . 'expected ' . $expected . ' items, got ' . count($actual)
            );
        }
    }

    protected function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        if (!array_key_exists($key, $array)) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ': ' : '') . 'missing key "' . $key . '" — have: ' . implode(', ', array_keys($array))
            );
        }
    }

    protected function assertGreaterThan(int|float $expected, int|float $actual, string $message = ''): void
    {
        if ($actual <= $expected) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ': ' : '') . $actual . ' is not greater than ' . $expected
            );
        }
    }

    protected function assertThrows(string $expectedClass, callable $callback, string $message = ''): \Throwable
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if (!$e instanceof $expectedClass) {
                throw new AssertionFailed(
                    ($message !== '' ? $message . ': ' : '') . 'expected ' . $expectedClass . ', got ' . $e::class . ' (' . $e->getMessage() . ')'
                );
            }

            return $e;
        }

        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . 'expected ' . $expectedClass . ' to be thrown.');
    }

    protected function skip(string $reason): never
    {
        throw new SkipTest($reason);
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            is_bool($value)   => $value ? 'true' : 'false',
            is_null($value)   => 'null',
            is_array($value)  => 'array(' . count($value) . ')',
            is_object($value) => $value::class,
            is_string($value) => '"' . mb_substr($value, 0, 120) . '"',
            default           => (string) $value,
        };
    }
}

final class AssertionFailed extends \RuntimeException
{
}

final class SkipTest extends \RuntimeException
{
}
