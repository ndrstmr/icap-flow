# Architektur-Patterns & SOLID/TDD — icap-flow

icap-flow ist eine Library, kein Framework. Die Architektur folgt strikt SOLID, ist test-getrieben und nutzt eine kleine, scharf geschnittene Menge an Design-Patterns. Diese Datei zeigt, **wie** sie eingesetzt werden — am konkreten Code, nicht an Lehrbuch-Beispielen.

## SOLID, kompakt am icap-flow-Code

### Single Responsibility
Jede Klasse hat genau eine Aufgabe — daran erkennt man die Schichtung im Repo:

| Klasse | Verantwortung |
|---|---|
| `RequestFormatter` | bytes-out: `IcapRequest` → iterable<string> RFC-3507-Wire |
| `ResponseParser` | bytes-in: `string` → `IcapResponse`, mit DoS-Limits |
| `ChunkedBodyEncoder` | RFC-7230-Chunked-Encoding einer einzelnen Body-Quelle |
| `TransportInterface` | Socket-I/O, kein Protokollwissen |
| `AmpConnectionPool` | Keep-Alive-Sockets pro `host:port[:tls]`-Key |
| `PreviewStrategyInterface` | Entscheidung „weitermachen / abbrechen" nach Preview |
| `IcapClient` | Orchestrierung; Fail-Secure-Statuscode-Auswertung |
| `RetryingIcapClient` | Decorator: Backoff-Retry für 5xx |

Wenn eine neue Aufgabe nicht in eine der bestehenden Klassen fällt, **mach eine neue** — kein Erweitern bestehender Klassen "weil die Datei schon offen war".

### Open/Closed
Erweiterung läuft über Interfaces, nicht Subklassen:

- Neuer Vendor-Header? `Config::withVirusFoundHeaders([...])`, kein Subclass-`IcapClient`.
- Vendor-spezifische Preview-Interpretation? Eigene `PreviewStrategyInterface`-Impl, in den Konstruktor des `IcapClient` reichen.
- Anderes Caching-Backend? Eigene `OptionsCacheInterface`-Impl (PSR-16-Adapter ist auf der v2.2-Roadmap).
- Anderes Pool-Backend? `ConnectionPoolInterface`-Impl. `NullConnectionPool` ist das v2.2-Item, das diesen Slot offiziell macht.

### Liskov Substitution
Alle `TransportInterface`-Implementierungen müssen denselben Wire-Vertrag erfüllen — was `AsyncAmpTransport` zurückgibt, muss byte-identisch `SynchronousStreamTransport` zurückgeben können (gleicher Server vorausgesetzt). Tests in `tests/Transport/TransportInterfaceTest.php` prüfen genau das.

### Interface Segregation
Beispiel: `SessionAwareTransport` ist ein zusätzliches Interface neben `TransportInterface`, nicht eine Methode, die jede Impl erfüllen müsste. Der Synchronous-Transport implementiert nur `TransportInterface` — er kann keine Sessions, also gibt's auch keinen No-Op-Stub. `IcapClient::scanFileWithPreview()` prüft `instanceof SessionAwareTransport` und wählt den Pfad.

```php
if ($this->transport instanceof SessionAwareTransport) {
    return $this->scanFileWithPreviewStrict(...);
}
return $this->scanFileWithPreviewLegacy(...);
```

### Dependency Inversion
`IcapClient` hängt **ausschließlich** an Interfaces:

```php
public function __construct(
    private Config $config,
    private TransportInterface $transport,
    private RequestFormatterInterface $formatter,
    private ResponseParserInterface $parser,
    ?PreviewStrategyInterface $previewStrategy = null,
    ?LoggerInterface $logger = null,
    private ?OptionsCacheInterface $optionsCache = null,
) { ... }
```

Die `static create()` / `static forServer()` Factories verdrahten konkrete Defaults — Konsumenten ohne DI-Container haben so einen einfachen Einstieg. Neue Konsumenten, die Tuning brauchen, instanziieren `IcapClient` direkt.

## TDD-Workflow in dieser Repo

1. **Test schreiben**, der heute fehlschlägt — in der passenden Suite:
   - `tests/Wire/` für RFC-3507-Byte-Format-Änderungen.
   - `tests/Transport/` für Socket-/Pool-Verhalten.
   - `tests/Security/` für Fail-Secure-, DoS- und Validator-Invarianten.
   - `tests/Integration/` für End-to-End-Smoke gegen echten ICAP-Server (env-gated).
   - Restliche Behavior-Tests bleiben in `tests/` Top-Level (`IcapClientTest.php`, `RetryingIcapClientTest.php`, …).
2. **Implementieren**, bis der Test grün ist — minimal, ohne Speculative Generality.
3. **Refactor mit Sicherheitsnetz** (alle vier Gates: `composer cs-check && composer stan && composer test && composer test:integration` falls Docker da ist).
4. **Mutation-Test auf neuen Hotspots** (`composer mutation`, `--min=65`). Wenn Mutation-Score unter Schwelle: schwacher Test, nicht "PHP-Quirk" — Test verschärfen.

Wire-Tests rechnen Byte-Streams **per Hand** vor. Beispiel-Muster aus `tests/Wire/RequestFormatterWireTest.php`:

```php
function wire(iterable $chunks): string
{
    $s = '';
    foreach ($chunks as $c) {
        $s .= $c;
    }
    return $s;
}

it('emits RFC 3507 §4.4.1 Encapsulated offsets for RESPMOD with body', function () {
    $request = new IcapRequest(/* ... */);
    $bytes = wire((new RequestFormatter())->format($request));

    expect($bytes)->toContain("Encapsulated: res-hdr=0, res-body=");
    expect($bytes)->toEndWith("0\r\n\r\n");
});
```

Nie `Mockery` für die Wire-Tests verwenden — die ganze Idee ist, dass **echte** Bytes aus dem **echten** Formatter kommen.

## Eingesetzte Patterns — und wo sie liegen

### Strategy
- `PreviewStrategyInterface` → `DefaultPreviewStrategy` (+ Cookbook 02 zeigt Vendor-Custom-Strategie).
- v2.1.1-Hotfix erweitert die Default-Strategie um den 200/206-Branch (Virus-Header → `ABORT_INFECTED`).

### Decorator
- `RetryingIcapClient` wickelt einen `IcapClientInterface` und retried 5xx mit Exponential-Backoff.
- Geplant für v2.2: `OtelTracingIcapClient` als weiterer Decorator (OpenTelemetry-Spans). **Niemals** den Retry- oder Tracing-Code in `IcapClient` selbst einbauen — das wäre eine SRP-Verletzung.

```php
final class RetryingIcapClient implements IcapClientInterface
{
    public function __construct(
        private IcapClientInterface $inner,
        private int $maxRetries = 3,
        private float $baseDelaySeconds = 0.5,
    ) {
    }

    #[\Override]
    public function request(IcapRequest $request, ?Cancellation $cancellation = null): Future
    {
        return \Amp\async(function () use ($request, $cancellation): ScanResult {
            $attempt = 0;
            while (true) {
                try {
                    return $this->inner->request($request, $cancellation)->await();
                } catch (IcapServerException $e) {
                    if (++$attempt > $this->maxRetries) {
                        throw $e;
                    }
                    delay($this->baseDelaySeconds * (2 ** ($attempt - 1)));
                }
            }
        });
    }
}
```

Decorator-Regel: **gleiches Interface, kein erweitertes Interface**. Wer `RetryingIcapClient` einsetzt, muss kein Wissen über das Wrapping haben.

### Facade
- `SynchronousIcapClient` ist eine Facade auf `IcapClient`, die `await()` aufruft und Framework-lose Aufrufer von Revolt-EventLoop abschirmt.
- `IcapClient` selbst ist Facade für die vier Worker-Klassen `RequestFormatter`, `ResponseParser`, `TransportInterface`, `PreviewStrategyInterface`.

### Factory
- `IcapClient::create()`, `IcapClient::forServer($host, $port = 1344)`, `SynchronousIcapClient::create()` — Convenience-Factories mit Default-Verdrahtung.
- Geplante v2.3.0-Bundle nutzt eine echte `IcapClientFactory` (siehe `references/symfony-bundle.md`).

### Session-Object
- `TransportSession` (Interface) + `AmpTransportSession` (Impl) für Strict-§4.5-Preview-Continue.
- Lifecycle: `openSession()` → `write()` → `readResponse()` → entweder `release()` (zurück in den Pool) oder `close()` (hartes Schließen bei Fehler).

### Object-Pool
- `AmpConnectionPool` mit LIFO-Stack pro `host:port[:tls]`-Key.
- Pool-Key-Bug ist **v2.1.1 P0**: heute fehlt der TLS-Context-Fingerprint, deshalb können sich Multi-Tenant-TLS-Configs gegenseitig den Socket klauen. Fix in `src/Transport/AmpConnectionPool.php:130-133` + Cross-TLS-Test.
- Geplant v2.2: `maxIdleAge` (Default 30 s) und Eviction-Sweep.

### Null-Object
- v2.2-Item: `NullConnectionPool` als expliziter Opt-Out. Heute reicht `null` — das soll explizit werden, wenn das Bundle-Repo den Slot in DI-Configs braucht.

## Anti-Patterns, die hier nicht reinkommen

- **Service-Locator** — keine globale Registry. Alles per Constructor-DI.
- **Static State** in `src/` — auch nicht für Caches. `OptionsCacheInterface` ist ein Konstruktor-Argument.
- **Template-Method-Subclassing** — wir haben keine Abstract-Base-Klassen. Alles ist `final`.
- **God-Objects** — `IcapClient` ist 638 Zeilen, ist aber Orchestrator. Wenn er größer als ~700 Zeilen wird, ein neues Helper-Object extrahieren (z. B. `PreviewExchange` für die Strict-§4.5-Logik).
- **Mutable DTOs** — `Config::withFoo()` muss eine neue Instanz liefern, nicht `$this` mutieren.
- **Exception-Mapping in der Mitte** — Exceptions werden dort geworfen, wo der semantische Kontext liegt (Wire-Layer wirft `IcapMalformedResponseException`, Transport-Layer `IcapConnectionException`). `IcapClient` mappt nur Statuscodes, nichts anderes.

## BC-Stabilität

Public-API-Liste, die v2-stabil ist und **bis v3.0.0** nicht angefasst wird:

- `IcapClientInterface`
- `IcapClient`-Public-Methoden inklusive `executeRaw()` (v3-Item: `protected` oder ins Interface heben)
- `SynchronousIcapClient`
- `Config` und ihre `with*()`-Methoden
- `TransportInterface`, `SessionAwareTransport`, `TransportSession`
- `PreviewStrategyInterface`, `PreviewDecision`
- `RequestFormatterInterface`, `ResponseParserInterface`
- `OptionsCacheInterface`, `ConnectionPoolInterface`
- Alle Exception-Klassen unter `Ndrstmr\Icap\Exception\` plus `IcapExceptionInterface`
- Alle DTOs unter `Ndrstmr\Icap\DTO\`

| Aktion | Erlaubt? |
|---|---|
| Neue Klasse in `src/` ergänzen | ✅ |
| Neuer optionaler Konstruktor-Param ans Ende einer `final`-Klasse | ✅ (Caller geben Named Args) |
| Neue Methode in einem **konkreten** `final class` (nicht im Interface) | ✅ |
| Neue Methode in einem `Interface` | ❌ Breaking |
| Neuer optionaler Param in einer Interface-Methode | ❌ Breaking |
| Exception-Message-Text ändern | ✅ |
| Geworfene Exception-Klasse ändern | ❌ Breaking |
| Public-Method entfernen | ❌ Breaking |
| Return-Type spezialisieren | ❌ Breaking |

Alle ❌-Items kommen in den **v3.0.0-Bucket der `consolidated_v2.1_task-list.md`**. Trigger v3.0.0 ist explizit: erst wenn mindestens zwei der drei dort gelisteten BC-Breaks durch User-Feedback erzwungen werden.
