# Symfony 7.4 Bundle — Vorbereitung für v2.3.0

> **Wichtig:** In **dieser** Repo (`ndrstmr/icap-flow`) gibt es **keine** Symfony-Komponenten. `src/` bleibt framework-neutral; einzige Optional-Dep ist `psr/log`. Die hier beschriebenen Patterns gehören in das **separate** Repo `ndrstmr/icap-flow-bundle`, das laut `consolidated_v2.1_task-list.md` Milestone v2.3.0 (8–12 Wochen nach v2.2.0) ist.
>
> Diese Datei lädt man, wenn man **das Bundle** entwirft oder einer Symfony-7.4-Anwendung zeigen will, **wie** sie icap-flow korrekt einbindet.

## Symfony 7.4 — Stand der Technik (April 2026)

- **Symfony 7.4 ist die aktuelle LTS-Vorbereitung** (LTS-Status zur 7.4-Release im November 2025). PHP-Minimum 8.4, identisch zu icap-flow.
- DI-Container-Konfiguration vorzugsweise in **PHP** (`config/services.php`) — nicht mehr in YAML/XML, sofern keine Bundle-Recipe-Vorgaben dagegen sprechen.
- Service-Definitionen mit **`autowire: true`**, **`autoconfigure: true`**, **`public: false`** als Default.
- Monolog-Channel pro Sub-System (`icap`).
- Console-Commands mit `#[AsCommand(...)]`, niemals mehr Class-Properties `protected static $defaultName`.
- Profiler-Datacollectors via `#[AutoconfigureTag(...)]`.

## Bundle-Skelett (`ndrstmr/icap-flow-bundle`)

```
ndrstmr/icap-flow-bundle/
├── src/
│   ├── IcapFlowBundle.php
│   ├── DependencyInjection/
│   │   ├── IcapFlowExtension.php
│   │   └── Configuration.php
│   ├── DataCollector/
│   │   └── IcapFlowDataCollector.php
│   ├── Command/
│   │   ├── ScanCommand.php           # icap:scan
│   │   ├── OptionsCommand.php        # icap:options
│   │   └── HealthCommand.php         # icap:health
│   ├── Validator/
│   │   ├── IcapClean.php             # #[IcapClean] Constraint
│   │   └── IcapCleanValidator.php
│   └── Resources/
│       ├── config/
│       │   └── services.php
│       └── views/
│           └── Collector/
│               └── icap_flow.html.twig
├── composer.json                     # require: ndrstmr/icap-flow:^2.2
├── phpunit.xml.dist
└── README.md
```

## composer.json (Bundle)

```json
{
  "name": "ndrstmr/icap-flow-bundle",
  "type": "symfony-bundle",
  "license": "EUPL-1.2",
  "require": {
    "php": "^8.4",
    "ndrstmr/icap-flow": "^2.2",
    "symfony/config": "^7.4",
    "symfony/dependency-injection": "^7.4",
    "symfony/http-kernel": "^7.4",
    "symfony/console": "^7.4",
    "symfony/validator": "^7.4 | *",
    "symfony/framework-bundle": "^7.4"
  }
}
```

`ndrstmr/icap-flow` selbst hat **niemals** ein `symfony/*`-Require.

## Bundle-Klasse (Symfony 6.1+ Stil)

```php
// [EUPL-Header]
declare(strict_types=1);

namespace Ndrstmr\IcapFlow\Bundle;

use Ndrstmr\IcapFlow\Bundle\DependencyInjection\IcapFlowExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class IcapFlowBundle extends Bundle
{
    #[\Override]
    public function getContainerExtension(): ?IcapFlowExtension
    {
        return $this->extension ??= new IcapFlowExtension();
    }
}
```

## Configuration-Tree

```php
// [EUPL-Header]
declare(strict_types=1);

namespace Ndrstmr\IcapFlow\Bundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('icap_flow');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('host')->isRequired()->cannotBeEmpty()->end()
                ->integerNode('port')->defaultValue(1344)->min(1)->max(65535)->end()
                ->floatNode('socket_timeout')->defaultValue(10.0)->end()
                ->floatNode('stream_timeout')->defaultValue(30.0)->end()
                ->arrayNode('virus_headers')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([
                        'X-Virus-Name', 'X-Infection-Found',
                        'X-Violations-Found', 'X-Virus-ID',
                    ])
                ->end()
                ->arrayNode('limits')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_response_size')->defaultValue(10 * 1024 * 1024)->end()
                        ->integerNode('max_header_count')->defaultValue(100)->end()
                        ->integerNode('max_header_line_length')->defaultValue(8192)->end()
                    ->end()
                ->end()
                ->arrayNode('tls')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('ca_file')->defaultNull()->end()
                        ->booleanNode('verify_peer')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('retry')
                    ->canBeEnabled()
                    ->children()
                        ->integerNode('max_attempts')->defaultValue(3)->min(1)->end()
                        ->floatNode('base_delay_seconds')->defaultValue(0.5)->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
```

## Extension — services.php als Loader

```php
// [EUPL-Header]
declare(strict_types=1);

namespace Ndrstmr\IcapFlow\Bundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

final class IcapFlowExtension extends Extension
{
    #[\Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new PhpFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config'),
        );
        $loader->load('services.php');

        $container->setParameter('icap_flow.config', $config);
    }
}
```

## services.php — DI mit Auto-Wiring & Decorators

```php
// [EUPL-Header]
declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Ndrstmr\Icap\Cache\InMemoryOptionsCache;
use Ndrstmr\Icap\Cache\OptionsCacheInterface;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\IcapClientInterface;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\RetryingIcapClient;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\Transport\ConnectionPoolInterface;
use Ndrstmr\Icap\Transport\TransportInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private()
    ;

    // Config kommt aus dem Configuration-Tree
    $services->set(Config::class)
        ->factory([Config::class, 'fromArray'])  // Helper, liest icap_flow.config
        ->arg('$config', '%icap_flow.config%')
    ;

    $services->set(ConnectionPoolInterface::class, AmpConnectionPool::class);
    $services->set(TransportInterface::class, AsyncAmpTransport::class);
    $services->set(RequestFormatter::class);
    $services->set(ResponseParser::class);
    $services->set(OptionsCacheInterface::class, InMemoryOptionsCache::class);

    // Inner-Client
    $services->set('icap_flow.inner_client', IcapClient::class);

    // Decorator: Retry on top, falls retry.enabled = true
    $services->set(IcapClientInterface::class, RetryingIcapClient::class)
        ->decorate('icap_flow.inner_client')
        ->arg('$inner', service('.inner'))
    ;
};
```

## Validator-Constraint `#[IcapClean]`

Die zugkräftige UX-Komponente: Symfony-Validator-Constraint, die ein hochgeladenes File via icap-flow scannt und bei Treffer eine Validation-Violation produziert.

```php
// [EUPL-Header]
declare(strict_types=1);

namespace Ndrstmr\IcapFlow\Bundle\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class IcapClean extends Constraint
{
    public function __construct(
        public string $service = '/avscan',
        public int $previewSize = 1024,
        public string $message = 'The uploaded file was flagged by virus scanner: {{ virus }}.',
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct([], $groups, $payload);
    }
}
```

```php
// [EUPL-Header]
declare(strict_types=1);

namespace Ndrstmr\IcapFlow\Bundle\Validator;

use Ndrstmr\Icap\IcapClientInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class IcapCleanValidator extends ConstraintValidator
{
    public function __construct(
        private readonly IcapClientInterface $icap,
    ) {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof IcapClean) {
            throw new UnexpectedTypeException($constraint, IcapClean::class);
        }
        if ($value === null) {
            return;
        }
        if (!$value instanceof File) {
            throw new UnexpectedValueException($value, File::class);
        }

        $result = $this->icap
            ->scanFileWithPreview($constraint->service, $value->getPathname(), $constraint->previewSize)
            ->await();

        if ($result->isInfected()) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ virus }}', $result->getVirusName() ?? 'unknown')
                ->addViolation();
        }
    }
}
```

## Console-Commands

```php
// [EUPL-Header]
declare(strict_types=1);

namespace Ndrstmr\IcapFlow\Bundle\Command;

use Ndrstmr\Icap\IcapClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'icap:scan', description: 'Scan a file via the configured ICAP service')]
final class ScanCommand extends Command
{
    public function __construct(
        private readonly IcapClientInterface $icap,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('service', InputArgument::REQUIRED, 'ICAP service path, e.g. /avscan')
            ->addArgument('file', InputArgument::REQUIRED, 'Local file path')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $service = (string) $input->getArgument('service');
        $file    = (string) $input->getArgument('file');

        $result = $this->icap->scanFile($service, $file)->await();

        if ($result->isInfected()) {
            $io->error(sprintf('Infected: %s', $result->getVirusName() ?? 'unknown'));
            return Command::FAILURE;
        }

        $io->success('File is clean.');
        return Command::SUCCESS;
    }
}
```

## DataCollector (Profiler-Panel)

```php
// [EUPL-Header]
declare(strict_types=1);

namespace Ndrstmr\IcapFlow\Bundle\DataCollector;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

final class IcapFlowDataCollector extends DataCollector
{
    #[\Override]
    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        // Daten kommen aus einem Tracing-Decorator, nicht direkt aus IcapClient
        // (siehe v2.2-Item #28 OtelTracingIcapClient — der DataCollector kann an dasselbe
        // Span-Hub andocken, wenn er existiert).
    }

    #[\Override]
    public function getName(): string
    {
        return 'icap_flow';
    }

    #[\Override]
    public function reset(): void
    {
        $this->data = [];
    }
}
```

## Multi-Client (Tagged-Services)

Wenn eine App gegen zwei ICAP-Server gleichzeitig läuft (z. B. ClamAV + Symantec):

```php
$services->set('icap_flow.client.clamav', IcapClient::class)
    ->args([service('icap_flow.config.clamav'), /* ... */])
    ->tag('icap_flow.client', ['name' => 'clamav']);

$services->set('icap_flow.client.symantec', IcapClient::class)
    ->args([service('icap_flow.config.symantec'), /* ... */])
    ->tag('icap_flow.client', ['name' => 'symantec']);
```

Konsumenten injecten dann via Service-Subscriber oder via `#[Autowire(service: 'icap_flow.client.clamav')]`.

## Was im Bundle **nicht** gehört

- **Wire-Format-Code, Validatoren, Strategy-Logik** — gehört in die Library `ndrstmr/icap-flow`. Das Bundle ist nur Verdrahtung.
- **Eigene Exception-Hierarchie** — die Bundle nutzt die Library-Exceptions, höchstens dünne Wrapper für Validator-Violation-Mapping.
- **Custom Async-Loop** — Symfony rennt synchron; das Bundle nutzt deshalb in der Regel `SynchronousIcapClient` oder ruft `await()` auf einem `IcapClientInterface`.

## Symfony 7.4 — was sich gegenüber 6.4 geändert hat (relevant für das Bundle)

- `#[AsCommand]`-Konstruktor erlaubt jetzt sauber DI ohne `parent::__construct()`-Boilerplate (war bereits 6.x).
- `Symfony\Bridge\PhpUnit` ist auf PHPUnit 11 gehoben — das Bundle nutzt aber Pest 3 wie die Library.
- Lazy-Service-Subscriber via Attributes (`#[SubscribedService]`) statt `getSubscribedServices()`.
- Asset-Mapper-Recipes ersetzen Webpack-Encore in neuen Setups — für ein backend-fokussiertes Validator-Bundle nicht relevant.

## Test-Strategie für das Bundle

```
tests/
├── Functional/
│   ├── BundleBootTest.php           # Container kompiliert, Services aufrufbar
│   ├── ConfigurationTest.php        # Tree-Builder gegen YAML/PHP-Snippets
│   └── ValidatorTest.php            # MockedIcap injecten, Constraint testen
└── Unit/
    └── DataCollectorTest.php
```

Functional-Tests bootstrappen einen Mini-Kernel, der nur das Bundle und ein Mock-`IcapClientInterface` lädt. Echte ICAP-Calls bleiben in der Library — das Bundle muss sie nicht erneut testen.

## Release-Choreographie (laut v2.1-Plan)

1. **v2.2.0** der Library (icap-flow) inkl. `NullConnectionPool`, OTel-Decorator, `Config::autoTunePoolFromOptions`, ISTag-Cache. Ohne diese Bausteine ist das Bundle unscharf.
2. **v0.1.0** des Bundle-Repos — startet bei Null, nicht synchron mit der Library-Version.
3. **Flex-Recipe** in `symfony/recipes-contrib` einreichen, sobald die Konfigurations-Form stabil ist.
4. **Adapter** für VichUploaderBundle / OneupUploaderBundle als optionale Sub-Pakete oder in einem separaten `icap-flow-uploader-adapter`-Repo.
