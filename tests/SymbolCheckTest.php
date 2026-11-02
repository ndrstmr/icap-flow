<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * This file is part of icap-flow.
 *
 * Licensed under the EUPL, Version 1.2 only (the "Licence");
 * you may not use this work except in compliance with the Licence.
 * You may obtain a copy of the Licence at:
 *
 *     https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the Licence is distributed on an "AS IS" basis,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 */

declare(strict_types=1);

namespace Ndrstmr\Icap\Tests;

/*
 * .github/list-symbols.php and .github/check-symbols.php in the order of CI. Both run as copies: the listing with the
 * parser of this repository and an autoloader that finds the namespace Autoloaded in the code under test. The check
 * refuses a development install, so it runs beside a vendor directory without packages: no polyfill answers for this PHP.
 */

const CONSUMER_AUTOLOAD = <<<'PHP'
<?php
namespace Composer {
    final class InstalledVersions { public static function getRootPackage(): array { return ['dev' => false]; } }
}
namespace Package {
    const VALUE = 1;
    function run(): void {}
    final class Thing {}
    interface Contract {}
    trait Shared {}
}
PHP;

/**
 * @return array{exit: int, output: list<string>}
 */
function runPhpScript(string ...$command): array
{
    $line = implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, '-d', 'display_errors=stderr', ...$command]));
    exec("{$line} 2>&1", $output, $exit);

    return ['exit' => $exit, 'output' => $output];
}

/**
 * @param array<string, string> $files code without the opening tag, by the name of its file
 *
 * @return array{exit: int, output: list<string>, missing: array<string, list<string>>} what is missing, by file
 */
function symbolCheck(array $files): array
{
    $repository = dirname(__DIR__);
    $root = sys_get_temp_dir() . '/icap-symbols-' . bin2hex(random_bytes(6));
    mkdir("{$root}/code", 0o700, true);
    mkdir("{$root}/.github");
    mkdir("{$root}/vendor");
    mkdir("{$root}/listing/.github", 0o700, true);
    mkdir("{$root}/listing/vendor");
    try {
        foreach ($files as $name => $code) {
            file_put_contents("{$root}/code/{$name}.php", "<?php\n{$code}\n");
        }
        file_put_contents("{$root}/vendor/autoload.php", CONSUMER_AUTOLOAD);
        copy("{$repository}/.github/check-symbols.php", "{$root}/.github/check-symbols.php");
        file_put_contents("{$root}/listing/vendor/autoload.php", <<<PHP
            <?php
            require '{$repository}/vendor/autoload.php';
            \$loader = new Composer\\Autoload\\ClassLoader();
            \$loader->addPsr4('Autoloaded\\\\', '{$root}/code');
            return \$loader;
            PHP);
        copy("{$repository}/.github/list-symbols.php", "{$root}/listing/.github/list-symbols.php");

        $listed = runPhpScript("{$root}/listing/.github/list-symbols.php", "{$root}/code");
        expect($listed['exit'])->toBe(0, implode("\n", $listed['output']));
        file_put_contents("{$root}/symbols.json", implode("\n", $listed['output']));

        $checked = runPhpScript("{$root}/.github/check-symbols.php", "{$root}/symbols.json");
    } finally {
        foreach (['code/*', '.github/*', 'vendor/*', 'listing/.github/*', 'listing/vendor/*', 'symbols.json'] as $pattern) {
            array_map(unlink(...), glob("{$root}/{$pattern}") ?: []);
        }
        array_map(rmdir(...), ["{$root}/code", "{$root}/.github", "{$root}/vendor", "{$root}/listing/.github", "{$root}/listing/vendor", "{$root}/listing", $root]);
    }

    $missing = [];
    foreach ($checked['output'] as $line) {
        if (preg_match('#/([\w-]+)\.php:\d+: (\w+) (\S+) does not exist#', $line, $found) === 1) {
            $missing[$found[1]][] = "{$found[2]} {$found[3]}";
        }
    }

    ksort($missing);

    return [...$checked, 'missing' => $missing];
}

test('a missing class is found wherever PHP resolves a name as one', function () {
    $cases = [
        'class-constant' => ['$a = NoSuch::Value;', 'NoSuch'],
        'static-call' => ['NoSuch::make();', 'NoSuch'],
        'static-property' => ['$a = NoSuch::$value;', 'NoSuch'],
        'class-name' => ['$a = NoSuch::class;', 'NoSuch'],
        'new' => ['$a = new NoSuch();', 'NoSuch'],
        'new-without-arguments' => ['$a = new NoSuch;', 'NoSuch'],
        'instanceof' => ['$a = $b instanceof NoSuch;', 'NoSuch'],
        'catch' => ['try {} catch (\LogicException | NoSuch) {}', 'NoSuch'],
        'extends' => ['final class A extends NoSuch {}', 'NoSuch'],
        'implements' => ['final class A implements \Countable, NoSuch {}', 'NoSuch'],
        'interface-extends' => ['interface A extends NoSuch {}', 'NoSuch'],
        'enum-implements' => ['enum A implements NoSuch {}', 'NoSuch'],
        'anonymous-class' => ['$a = new class () extends NoSuch {};', 'NoSuch'],
        'trait' => ['final class A { use NoSuch; }', 'NoSuch'],
        'parameter-type' => ['function a(NoSuch $b): void {}', 'NoSuch'],
        'return-type' => ['function a(): NoSuch {}', 'NoSuch'],
        'nullable-type' => ['function a(?NoSuch $b): void {}', 'NoSuch'],
        'union-type' => ['function a(int|NoSuch $b): void {}', 'NoSuch'],
        'intersection-type' => ['function a(\Countable&NoSuch $b): void {}', 'NoSuch'],
        'disjunctive-type' => ['function a((\Countable&NoSuch)|null $b): void {}', 'NoSuch'],
        'property-type' => ['final class A { private NoSuch $b; }', 'NoSuch'],
        'promoted-type' => ['final class A { public function __construct(private NoSuch $b) {} }', 'NoSuch'],
        'constant-type' => ['final class A { public const NoSuch B = null; }', 'NoSuch'],
        'closure-type' => ['$a = static function (): NoSuch {};', 'NoSuch'],
        'arrow-function-type' => ['$a = static fn (NoSuch $b) => 1;', 'NoSuch'],
        'attribute' => ['#[NoSuch] function a(): void {}', 'NoSuch'],
        'attribute-with-arguments' => ['#[NoSuch(1)] function a(): void {}', 'NoSuch'],
        'second-attribute' => ['#[\Attribute, NoSuch] final class A {}', 'NoSuch'],
        'parameter-attribute' => ['function a(#[NoSuch] $b): void {}', 'NoSuch'],
        'fully-qualified' => ['$a = \Other\NoSuch::Value;', 'Other\NoSuch'],
        'in-a-namespace' => ['namespace Probe; $a = NoSuch::Value;', 'Probe\NoSuch'],
        'qualified' => ['namespace Probe; $a = Sub\NoSuch::Value;', 'Probe\Sub\NoSuch'],
        'relative' => ['namespace Probe; $a = namespace\NoSuch::Value;', 'Probe\NoSuch'],
        'imported' => ['namespace Probe; use Other\NoSuch; $a = new NoSuch();', 'Other\NoSuch'],
        'aliased-in-another-case' => ['use Other\NoSuch as Alias; $a = new alias();', 'Other\NoSuch'],
        'in-a-list-of-imports' => ['use Other\A, Other\NoSuch; $a = new NoSuch();', 'Other\NoSuch'],
        'in-a-group-of-imports' => ['use Other\{A, NoSuch as B}; $a = new B();', 'Other\NoSuch'],
        'below-an-imported-namespace' => ['use Other\Sub as Net; $a = new Net\NoSuch();', 'Other\Sub\NoSuch'],
    ];

    $run = symbolCheck(array_map(static fn (array $case): string => $case[0], $cases));

    $expected = array_map(static fn (array $case): array => ["class {$case[1]}"], $cases);
    ksort($expected);
    expect($run['missing'])->toBe($expected)
        ->and($run['exit'])->toBe(1);
});

test('a missing function or constant is found however it is named', function () {
    $run = symbolCheck([
        'function' => 'no_such();',
        'fully-qualified-function' => '\no_such();',
        'function-as-callable' => '$a = no_such(...);',
        'function-in-a-namespace' => 'namespace Probe; no_such();',
        'relative-function' => 'namespace Probe; namespace\no_such();',
        'qualified-function' => 'namespace Probe; Sub\no_such();',
        'imported-function' => 'use function Other\no_such; no_such();',
        'aliased-function' => 'use function Other\no_such as alias; alias();',
        'function-below-an-imported-namespace' => 'use Other\Sub; Sub\no_such();',
        'constant' => 'echo NO_SUCH;',
        'lowercase-constant' => 'echo no_such;',
        'fully-qualified-constant' => 'echo \NO_SUCH;',
        'constant-in-a-namespace' => 'namespace Probe; echo NO_SUCH;',
        'relative-constant' => 'namespace Probe; echo namespace\NO_SUCH;',
        'imported-constant' => 'use const Other\NO_SUCH; echo NO_SUCH;',
        'constant-in-another-case' => 'const OWN = 1; echo own;',
    ]);

    $expected = [
        'function' => ['function no_such'],
        'fully-qualified-function' => ['function no_such'],
        'function-as-callable' => ['function no_such'],
        'function-in-a-namespace' => ['function no_such'],
        'relative-function' => ['function Probe\no_such'],
        'qualified-function' => ['function Probe\Sub\no_such'],
        'imported-function' => ['function Other\no_such'],
        'aliased-function' => ['function Other\no_such'],
        'function-below-an-imported-namespace' => ['function Other\Sub\no_such'],
        'constant' => ['constant NO_SUCH'],
        'lowercase-constant' => ['constant no_such'],
        'fully-qualified-constant' => ['constant NO_SUCH'],
        'constant-in-a-namespace' => ['constant NO_SUCH'],
        'relative-constant' => ['constant Probe\NO_SUCH'],
        'imported-constant' => ['constant Other\NO_SUCH'],
        'constant-in-another-case' => ['constant own'],
    ];
    ksort($expected);
    expect($run['missing'])->toBe($expected)
        ->and($run['exit'])->toBe(1);
});

test('what the code declares itself is not missing', function () {
    $run = symbolCheck([
        'function' => 'function own(): void {} own();',
        'function-in-another-case' => 'function ownCased(): void {} OWNCASED();',
        'function-declared-later' => 'ownLater(); function ownLater(): void {}',
        'function-declared-in-a-condition' => 'if (!function_exists("ownPolyfill")) { function ownPolyfill(): void {} } ownPolyfill();',
        'function-in-a-namespace' => 'namespace Probe; function own(): void {} own(); \Probe\own(); namespace\own();',
        'class' => 'final class OwnClass {} $a = new OwnClass(); $b = new ownclass();',
        'interface-trait-enum' => 'interface OwnI {} trait OwnT {} enum OwnE { case A; } final class OwnC implements OwnI { use OwnT; } $a = OwnE::A;',
        'class-in-a-namespace' => 'namespace Probe; final class OwnClass {} function a(OwnClass $b): \Probe\OwnClass { return $b; }',
        'constant' => 'const OWN_CONSTANT = 1; echo OWN_CONSTANT;',
        'constant-in-a-namespace' => 'namespace Probe; const OWN = 1; echo OWN, \Probe\OWN, namespace\OWN;',
        'defined-constant' => 'define("OWN_DEFINED", 1); echo OWN_DEFINED;',
    ]);

    expect($run['missing'])->toBe([])
        ->and($run['exit'])->toBe(0)
        ->and(end($run['output']))->toEndWith(', 0 missing');
});

test('a declaration does not reach other files', function () {
    $run = symbolCheck([
        'global-declarations' => 'function other_file(): void {} const OTHER_FILE = 1; define("OTHER_DEFINED", 1); final class OtherFile {}',
        'namespaced-declarations' => 'namespace Probe; function other_namespaced(): void {} const OTHER_NAMESPACED = 1; final class OtherNamespaced {}',
        'function' => 'other_file();',
        'function-with-fallback' => 'namespace Probe; other_file();',
        'namespaced-function' => 'namespace Probe; other_namespaced();',
        'constant' => 'echo OTHER_FILE;',
        'defined-constant' => 'echo OTHER_DEFINED;',
        'namespaced-constant' => 'namespace Probe; echo OTHER_NAMESPACED;',
        'class' => '$a = new OtherFile();',
        'namespaced-class' => 'namespace Probe; $a = new OtherNamespaced();',
    ]);

    $expected = [
        'function' => ['function other_file'],
        'function-with-fallback' => ['function other_file'],
        'namespaced-function' => ['function other_namespaced'],
        'constant' => ['constant OTHER_FILE'],
        'defined-constant' => ['constant OTHER_DEFINED'],
        'namespaced-constant' => ['constant OTHER_NAMESPACED'],
        'class' => ['class OtherFile'],
        'namespaced-class' => ['class Probe\OtherNamespaced'],
    ];
    ksort($expected);
    expect($run['missing'])->toBe($expected)
        ->and($run['exit'])->toBe(1);
});

test('only a class the autoloader finds where it is declared reaches other files', function () {
    $run = symbolCheck([
        'Found' => 'namespace Autoloaded; interface Found {} final class SecondClass {} function second_function(): void {} const SECOND_CONSTANT = 1;',
        'Hollow' => 'namespace Autoloaded; echo 1;',
        'AlsoAFunction' => 'namespace Autoloaded; function AlsoAFunction(): void {}',
        'declares-hollow' => 'namespace Autoloaded; final class Hollow {}',
        'names-the-found' => 'function a(\Autoloaded\Found $b): void {}',
        'names-the-found-in-another-case' => 'function a(\autoloaded\found $b): void {}',
        'names-the-found-as-a-function' => '\Autoloaded\Found();',
        'names-the-second-class' => '$a = new \Autoloaded\SecondClass();',
        'names-the-second-function' => '\Autoloaded\second_function();',
        'names-the-second-constant' => 'echo \Autoloaded\SECOND_CONSTANT;',
        'names-the-hollow' => '$a = new \Autoloaded\Hollow();',
        'names-the-function' => '\Autoloaded\AlsoAFunction();',
        'names-the-function-as-a-class' => '$a = new \Autoloaded\AlsoAFunction();',
    ]);

    $expected = [
        'names-the-found-in-another-case' => ['class autoloaded\found'],
        'names-the-found-as-a-function' => ['function Autoloaded\Found'],
        'names-the-second-class' => ['class Autoloaded\SecondClass'],
        'names-the-second-function' => ['function Autoloaded\second_function'],
        'names-the-second-constant' => ['constant Autoloaded\SECOND_CONSTANT'],
        'names-the-hollow' => ['class Autoloaded\Hollow'],
        'names-the-function' => ['function Autoloaded\AlsoAFunction'],
        'names-the-function-as-a-class' => ['class Autoloaded\AlsoAFunction'],
    ];
    ksort($expected);
    expect($run['missing'])->toBe($expected)
        ->and($run['exit'])->toBe(1);
});

test('what PHP and the install have and what is no symbol is not missing', function () {
    $run = symbolCheck([
        'installed-package' => 'use Package\{Thing, Contract, Shared}; use function Package\run; use const Package\VALUE; run(); echo VALUE; final class A implements Contract { use Shared; } $a = new Thing();',
        'built-in' => '$a = new \ArrayObject(); $b = \strlen("a") . \PHP_VERSION . strlen("a") . PHP_VERSION . new ArrayObject();',
        'global-fallback' => 'namespace Probe; echo strlen("a"), PHP_VERSION, true, NULL, False;',
        'imports-of-built-ins' => 'namespace Probe; use ArrayObject as Objects; use function strlen as length; use const PHP_VERSION as VERSION; echo length("a"), VERSION; $a = new Objects();',
        'list-and-group-of-imports' => 'use ArrayObject, Countable; use Random\{Randomizer, Engine\Secure}; $a = new ArrayObject(); $b = new Randomizer(new Secure()); $c = $a instanceof Countable;',
        'unused-import' => 'namespace Probe; use Other\NoSuch; use function Other\no_such; use const Other\NO_SUCH;',
        'suggested-packages' => 'function a(\Psr\Cache\CacheItemPoolInterface $b, \Psr\SimpleCache\CacheInterface $c): void {}',
        'pseudo-types' => 'class A { public function a(self $b, int|string|null $c, array $d, callable $e, iterable $f, mixed $g, object $h, bool $i, float $j): static { return new static(); } }',
        'parent' => 'final class B extends \ArrayObject { public function a(): parent { parent::count(); return new parent(); } }',
        'members' => 'final class C { public const NO_SUCH = 1; public int $no_such = 0; public function no_such(): int { $this->no_such(); self::no_such(); return $this?->no_such + self::NO_SUCH; } }',
        'enum-case' => 'enum D: string { case NoSuch = "a"; }',
        'trait-adaptation' => 'trait T { public function a(): void {} } trait U { public function a(): void {} } final class E { use T, U { T::a insteadof U; U::a as no_such; } }',
        'named-argument-and-label' => 'echo strlen(string: "a"); goto no_such; no_such: echo 1;',
        'string-offset' => 'echo "$a[no_such] $a->no_such";',
        'dynamic-names' => '$a = "no_such"; $a(); $b = new $a(); $a::no_such();',
    ]);

    expect($run['missing'])->toBe([])
        ->and($run['exit'])->toBe(0);
});

test('the check answers for the PHP it runs on', function () {
    $run = symbolCheck([
        'later-versions' => <<<'PHP'
            namespace Probe;

            use RoundingMode;
            use Dom\HTMLDocument;

            #[\Deprecated]
            function a(RoundingMode $b): ?HTMLDocument
            {
                echo __PROPERTY__, \PHP_VERSION;

                return array_find([], static fn () => false) ?? array_first([]);
            }
            PHP,
        'an-example-with-the-same-function' => 'function array_find(array $a, callable $b): mixed { return null; }',
    ]);

    $expected = [
        ...(PHP_VERSION_ID < 80400 ? ['class Deprecated', 'class Dom\HTMLDocument', 'class RoundingMode', 'constant __PROPERTY__', 'function array_find'] : []),
        ...(PHP_VERSION_ID < 80500 ? ['function array_first'] : []),
    ];
    $missing = $run['missing']['later-versions'] ?? [];
    sort($expected);
    sort($missing);

    expect($missing)->toBe($expected)
        ->and($run['exit'])->toBe($expected === [] ? 0 : 1);
});

test('the check fails if it has nothing to check or sees development dependencies', function () {
    $nothing = symbolCheck(['no-names' => 'echo 1;']);
    $development = runPhpScript(dirname(__DIR__) . '/.github/check-symbols.php', '/nonexistent');

    expect($nothing['exit'])->toBe(1)
        ->and($nothing['missing'])->toBe([])
        ->and($development['exit'])->toBe(2)
        ->and($development['output'])->toBe(['Development dependencies are installed, their polyfills would hide what is missing.']);
});
