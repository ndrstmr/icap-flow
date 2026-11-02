<?php

declare(strict_types=1);

// Usage, before composer install --no-dev: php .github/list-symbols.php <directory>... > symbols.json
// Lists the functions, classes and constants the code names, for check-symbols.php: the parser that resolves the names
// is a development dependency, and that check has to run without them. A consumer loads this code through the autoloader
// alone, so a declaration counts only in its own file, and elsewhere only for a class the autoloader finds in that file.
// Neither a condition around a declaration nor a require is evaluated: the one counts as declared, what the other loads
// as missing.

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

$loader = require __DIR__ . '/../vendor/autoload.php';

// PHP does not distinguish the case of functions and classes.
function symbolKey(string $kind, string $name): string
{
    return $kind . ' ' . ($kind === 'constant' ? $name : strtolower($name));
}

// The host version: on PHP 8.3 __PROPERTY__ is a constant, as it is for the code when it runs there.
$parser = (new ParserFactory())->createForHostVersion();
$finder = new NodeFinder();
$autoloaded = [];
$named = [];

foreach (array_slice($argv, 1) as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $traverser = new NodeTraverser(new NameResolver(), new ParentConnectingVisitor());
        $nodes = $traverser->traverse($parser->parse((string) file_get_contents($file->getPathname())) ?? []);
        $declared = [];
        $used = [];

        foreach ($finder->find($nodes, static fn (Node $node): bool => isset($node->namespacedName)) as $declaration) {
            $kind = match (true) {
                $declaration instanceof Node\Stmt\Function_ => 'function',
                $declaration instanceof Node\Const_ => 'constant',
                default => 'class',
            };
            $symbol = (string) $declaration->namespacedName;
            $declared[symbolKey($kind, $symbol)] = true;
            if ($kind === 'class' && realpath((string) $loader->findFile($symbol)) === $file->getRealPath()) {
                $autoloaded["class {$symbol}"] = true;
            }
        }

        foreach ($finder->findInstanceOf($nodes, Name::class) as $name) {
            $parent = $name->getAttribute('parent');
            $kind = match (true) {
                $parent instanceof FuncCall => 'function',
                $parent instanceof ConstFetch => 'constant',
                default => 'class',
            };
            $defined = $kind === 'function' && $name->toLowerString() === 'define' ? ($parent->args[0]->value ?? null) : null;
            if ($defined instanceof String_) {
                $declared[symbolKey('constant', $defined->value)] = true;
            }
            // Left unresolved are self, parent and static, and the names of namespace and use statements. An import
            // alone uses nothing, and it may name a namespace.
            if ($kind === 'class' && !$name->isFullyQualified()) {
                continue;
            }
            // Unqualified in a namespace, a function or constant falls back to the global one.
            $names = [(string) $name];
            if ($name->hasAttribute('namespacedName')) {
                array_unshift($names, (string) $name->getAttribute('namespacedName'));
            }
            $used[] = ["{$file}:{$name->getStartLine()}", $kind, $names];
        }

        foreach ($used as $entry) {
            $isDeclared = static fn (string $name): bool => isset($declared[symbolKey($entry[1], $name)]);
            if (array_filter($entry[2], $isDeclared) === []) {
                $named[] = $entry;
            }
        }
    }
}

// Only now: a class may be named before the file that declares it is read.
$listed = array_filter($named, static fn (array $entry): bool => !isset($autoloaded["{$entry[1]} {$entry[2][0]}"]));

echo json_encode(array_values($listed), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
