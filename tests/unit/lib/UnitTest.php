<?php
/**
 * UnitTest.php — tiny dependency-free unit test harness (pure PHP, no WHMCS,
 * no Composer). Test files register cases with test('name', fn) and assert
 * with the helpers below; run.php executes everything and reports.
 */

final class AssertionFailure extends \Exception
{
}

final class UnitTest
{
    /** @var array<int,array{name:string, fn:callable}> */
    private static array $tests = [];

    public static function add(string $name, callable $fn): void
    {
        self::$tests[] = ['name' => $name, 'fn' => $fn];
    }

    public static function run(): int
    {
        $pass = 0;
        $fail = 0;
        foreach (self::$tests as $test) {
            try {
                ($test['fn'])();
                echo "PASS {$test['name']}\n";
                $pass++;
            } catch (AssertionFailure $e) {
                echo "FAIL {$test['name']}: {$e->getMessage()}\n";
                $fail++;
            } catch (\Throwable $e) {
                echo "FAIL {$test['name']}: unexpected " . get_class($e) . ": {$e->getMessage()}\n";
                $fail++;
            }
        }
        echo "----\n$pass passed, $fail failed\n";
        return $fail > 0 ? 1 : 0;
    }
}

function test(string $name, callable $fn): void
{
    UnitTest::add($name, $fn);
}

function assertTrue(mixed $condition, string $message = 'expected true'): void
{
    if ($condition !== true) {
        throw new AssertionFailure($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $prefix = $message === '' ? '' : "$message — ";
        throw new AssertionFailure(
            $prefix . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

/**
 * Assert that $fn throws (optionally an instance of $class whose message
 * contains $messageNeedle). Returns the caught exception for extra checks.
 */
function assertThrows(callable $fn, string $class = \Throwable::class, string $messageNeedle = ''): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) {
            throw new AssertionFailure('threw ' . get_class($e) . ", expected $class: {$e->getMessage()}");
        }
        if ($messageNeedle !== '' && !str_contains($e->getMessage(), $messageNeedle)) {
            throw new AssertionFailure("exception message '{$e->getMessage()}' does not contain '$messageNeedle'");
        }
        return $e;
    }
    throw new AssertionFailure("expected $class, nothing thrown");
}
