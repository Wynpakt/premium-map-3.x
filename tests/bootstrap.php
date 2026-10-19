<?php

$failures = 0;

spl_autoload_register(function ($class) {
    $file = dirname(__DIR__) . '/app/lib/' . $class . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

function test($name, $fn)
{
    global $failures;
    try {
        $fn();
        echo "ok  $name\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL $name\n  " . $e->getMessage() . "\n";
    }
}

function assertSame($expected, $actual)
{
    if ($expected !== $actual) {
        throw new Exception(
            'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true)
        );
    }
}

function assertContains($needle, $haystack)
{
    if (strpos((string) $haystack, (string) $needle) === false) {
        throw new Exception('missing ' . $needle . ' in ' . $haystack);
    }
}

function assertNotContains($needle, $haystack)
{
    if (strpos((string) $haystack, (string) $needle) !== false) {
        throw new Exception('did not expect ' . $needle . ' in ' . $haystack);
    }
}

function finished()
{
    global $failures;
    if ($failures > 0) {
        echo $failures . " failed\n";
        exit(1);
    }
    echo "all passed\n";
}
