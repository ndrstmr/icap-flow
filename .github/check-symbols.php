<?php

declare(strict_types=1);

// Usage, after composer install --no-dev: php .github/check-symbols.php <output of list-symbols.php>
// Fails if the code names a function, class or constant that a consumer on this PHP does not have. The development
// tree cannot show that: php-cs-fixer brings symfony/polyfill-php84 into the tests, and the PHAR of PHPStan defines
// polyfilled symbols for itself. Not covered: names in strings, members and parameters of built-in classes, and a fiber
// switch during a destructor.

require __DIR__ . '/../vendor/autoload.php';

if (Composer\InstalledVersions::getRootPackage()['dev']) {
    fwrite(STDERR, "Development dependencies are installed, their polyfills would hide what is missing.\n");
    exit(2);
}

function symbolExists(string $kind, string $name): bool
{
    return match ($kind) {
        'function' => function_exists($name),
        'constant' => defined($name),
        'class' => class_exists($name) || interface_exists($name) || trait_exists($name)
            // The PSR-6 and PSR-16 adapters need packages that composer.json only suggests.
            || preg_match('/^Psr\\\\(Cache|SimpleCache)\\\\/', $name) === 1,
    };
}

$named = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$missing = 0;
foreach ($named as [$where, $kind, $names]) {
    if (array_filter($names, static fn (string $name): bool => symbolExists($kind, $name)) === []) {
        printf("%s: %s %s does not exist on PHP %s\n", $where, $kind, end($names), PHP_VERSION);
        ++$missing;
    }
}

printf("%d names checked on PHP %s, %d missing\n", count($named), PHP_VERSION, $missing);
exit($missing > 0 || $named === [] ? 1 : 0);
