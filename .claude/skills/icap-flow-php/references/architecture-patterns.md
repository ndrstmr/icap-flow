# Architektur-Patterns & SOLID/TDD — icap-flow

Der verbindliche Engineering-Maßstab steht in [AGENTS.md](../../../../AGENTS.md#engineering-standard). Diese Referenz erläutert seine Anwendung auf die vorhandene ICAP-Client-Architektur.

## SOLID, kompakt am icap-flow-Code

### Single Responsibility
Jede Klasse hat eine klar abgegrenzte Verantwortung und einen fachlichen Grund, sich zu ändern — daran erkennt man die Schichtung im Repo:

| Klasse | Verantwortung |
|---|---|
| `RequestFormatter` | bytes-out: `IcapRequest` → iterable<string> RFC-3507-Wire |
| `ResponseParser` / `ResponseFrameReader` | bytes-in über denselben internen `ResponseDecoder`, mit DoS-Limits |
| `ChunkedBodyEncoder` | RFC-7230-Chunked-Encoding einer einzelnen Body-Quelle |
| `TransportInterface` | Socket-I/O, kein Protokollwissen |
| `AmpConnectionPool` | Verbindungsgrenzen und Keep-Alive-Sockets pro `host:port[:tls]`-Bucket |
| `PreviewStrategyInterface` | Entscheidung „weitermachen / abbrechen" nach Preview |
| `CicapVerdict` | verifizierter Modus: Antworten von c-icap `virus_scan` + ClamAV |
| `IcapClient` | Orchestrierung; Fail-Secure-Statuscode-Auswertung |
| `RetryingIcapClient` | Decorator: wiederholt nur `options()` nach 5xx (Full-Jitter-Backoff), eine Endzeit je Aufruf |

Trenne eine eigenständige Verantwortung ab, wenn sie einen anderen Änderungsgrund hat. Zusätzliche Methoden derselben Verantwortung dürfen in der bestehenden Klasse bleiben; eine neue Klasse braucht eine konkrete fachliche oder technische Grenze.

### Open/Closed
Erweiterung läuft über Interfaces, nicht Subklassen:

- Weiterer Virus-Header? `Config::withVirusFoundHeaders([...])`, kein Subclass-`IcapClient`.
- Eigene Preview-Interpretation? Eigene `PreviewStrategyInterface`-Impl, in den Konstruktor des `IcapClient` reichen (nicht mit dem verifizierten Modus und nicht mit `scanFileWithPreviewDiscardingBody()`).
- Anderes Caching-Backend? `Psr6OptionsCache`/`Psr16OptionsCache` oder eine eigene `OptionsCacheInterface`-Impl.
- Anderes Pool-Backend? `ConnectionPoolInterface`-Impl; `NullConnectionPool` öffnet je `acquire()` eine Verbindung und poolt nichts.

### Liskov Substitution
Alle `TransportInterface`-Implementierungen müssen denselben Wire-Vertrag erfüllen: Beide eingebauten Transporte rahmen Antworten mit demselben `ResponseFrameReader`, gleicher Server vorausgesetzt liefern sie dieselben Bytes.

### Interface Segregation
Optionale Fähigkeiten sind eigene Interfaces, keine Pflichtmethoden: `SessionAwareTransport` (Sessions für den Preview-Continue nach §4.5) und `DiscardingTransport` (Antworten ohne gespeicherten Body). Beide eingebauten Transporte implementieren beide. `IcapClient::scanFileWithPreview*()` verlangt `SessionAwareTransport` (`sessionTransport()`): ein Transport ohne Sessions ist am Aufruf eine `LogicException`, ein Fallback ist nicht vorgesehen.

### Dependency Inversion
`IcapClient` hängt an Interfaces; konkret sind nur die Werte `Config` und `CicapVerdict`:

```php
public function __construct(
    private Config $config,
    private TransportInterface $transport,
    private RequestFormatterInterface $formatter,
    private ResponseParserInterface $parser,
    ?PreviewStrategyInterface $previewStrategy = null,
    ?LoggerInterface $logger = null,
    private ?OptionsCacheInterface $optionsCache = null,
    private readonly ?CicapVerdict $verdict = null,
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
- `PreviewStrategyInterface` → `DefaultPreviewStrategy` (+ Cookbook 02 zeigt eine eigene Strategie).

### Decorator
- `RetryingIcapClient` wickelt einen `IcapClientInterface` und wiederholt **nur `options()`** nach 5xx, mit Exponential-Backoff und Full Jitter; alle anderen Operationen bekommen einen Versuch. Ein Aufruf hat eine Endzeit (`operationTimeout`, `OperationDeadline`), die der innere Client als Cancellation bekommt und nicht verlängern kann.
- **Niemals** Retry- oder Tracing-Code in `IcapClient` selbst einbauen — das wäre eine SRP-Verletzung.

Decorator-Regel: **gleiches Interface, kein erweitertes Interface**. Wer `RetryingIcapClient` einsetzt, muss kein Wissen über das Wrapping haben.

### Facade
- `SynchronousIcapClient` ist eine Facade auf `IcapClient`, die `await()` aufruft und Framework-lose Aufrufer von Revolt-EventLoop abschirmt.
- `IcapClient` selbst ist Facade für die Worker-Klassen `RequestFormatter`, `ResponseParser`, `TransportInterface`, `PreviewStrategyInterface`.

### Factory
- `IcapClient::create()`, `IcapClient::forServer($host, $port = 1344, ?CicapVerdict $verdict = null)`, `SynchronousIcapClient::create()` — Convenience-Factories mit Default-Verdrahtung; `create()` und `SynchronousIcapClient::create()` laufen mit den Standardregeln.

### Session-Object
- `TransportSession` (Interface) + `AmpTransportSession` (Impl) für Strict-§4.5-Preview-Continue.
- Lifecycle: `openSession()` → `write()` → `readResponse()` → `release()` (zurück in den Pool) nur nach geprüftem Austausch, sonst `close()`; Standard ist `close()`.

### Object-Pool
- `AmpConnectionPool` mit LIFO-Stack pro `host:port[:tls]`-Bucket; Caps zählen verbindende, aktive und idle Sockets (Endpoint = `host:port`); Idle-Sockets altern nach `maxIdleSeconds` aus.
- Pool-Key: `host:port` plus, mit TLS, ein nie wiederverwendetes Token je `ClientTlsContext`-Objekt (`\WeakMap`); kein `spl_object_hash()` als Identität (B07).

### Null-Object
- `NullConnectionPool` als expliziter Opt-Out: jede Anfrage eine eigene Verbindung, wie ohne Pool.

## Anti-Patterns, die hier nicht reinkommen

- **Service-Locator** — keine globale Registry. Alles per Constructor-DI.
- **Static State** in `src/` — auch nicht für Caches. `OptionsCacheInterface` ist ein Konstruktor-Argument.
- **Template-Method-Subclassing** — wir haben keine Abstract-Base-Klassen. Alles ist `final`.
- **God-Objects** — `IcapClient` orchestriert nur; Wire-Format, Transport, Pool, Cache und Verdikt liegen in eigenen Klassen.
- **Mutable DTOs** — `Config::withFoo()` muss eine neue Instanz liefern, nicht `$this` mutieren.
- **Exception-Mapping in der Mitte** — Exceptions werden dort geworfen, wo der semantische Kontext liegt (Wire-Layer wirft `IcapMalformedResponseException`, Transport-Layer `IcapConnectionException`). `IcapClient` mappt nur Statuscodes, nichts anderes.

## BC-Stabilität

Zur öffentlichen API (SemVer) gehören:

- `IcapClientInterface`, `DiscardingIcapClient`
- `IcapClient`, `SynchronousIcapClient`, `RetryingIcapClient` und ihre öffentlichen Methoden
- `Config` und ihre `with*()`-Methoden, `CicapVerdict`
- `TransportInterface`, `SessionAwareTransport`, `TransportSession`, `DiscardingTransport`, `DiscardingTransportSession`
- `PreviewStrategyInterface`, `PreviewDecision`
- `RequestFormatterInterface`, `ResponseParserInterface`
- `OptionsCacheInterface`, `ConnectionPoolInterface`
- Alle Exception-Klassen unter `Ndrstmr\Icap\Exception\` plus `IcapExceptionInterface`
- Alle DTOs unter `Ndrstmr\Icap\DTO\`

Klassen mit `@internal` gehören nicht dazu.

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

Ein ❌-Item geht nur in eine Major-Version, ist im CHANGELOG markiert und braucht eine ausdrückliche Freigabe im Auftrag (AGENTS.md).
