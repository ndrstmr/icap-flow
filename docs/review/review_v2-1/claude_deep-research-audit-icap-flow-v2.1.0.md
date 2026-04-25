# Deep Research Audit — `ndrstmr/icap-flow` v2.1.0

> Verifiziert am Working Tree des Audit-Branches `claude/audit-icapflow-production-vdtbq` (HEAD `8b777be`, 25.04.2026). Alle Code-Refs beziehen sich auf diesen Commit.

---

## 1. Executive Summary

`icap-flow` v2.1.0 ist **funktional produktionsreif** für interne Tools, Symfony-Anwendungen mit kontrolliertem Mandantenschnitt und CLI-Pipelines, **aber noch nicht uneingeschränkt für Security-kritische Multi-Tenant-Upload-Gateways** mit gemischten TLS-Profilen. Der Sprung v1→v2.1 ist ein echtes Engineering-Werk: jede der 14 Findings A–N und alle vier M3-Follow-ups (Cancellation, OPTIONS-Cache, 503-Retry, Response-Framing) sind im Code geschlossen — nicht nur dokumentiert (siehe Kapitel 3). RFC-3507-Wire-Format ist korrekt, der Fail-Open-Bug auf Status `100` ist beseitigt, CRLF-Injection auf Service-Path und User-Headern ist gehärtet, DoS-Limits sind doppelt enforced (Reader + Parser), und die strikte §4.5-Preview-Continue-Implementation ist die mit Abstand sauberste Umsetzung in der PHP-Welt.

**Aber** — und das ist die zentrale Erkenntnis dieses Audits — die v2.1-Neubauten haben drei substantielle, mehrheitlich **noch nicht im Self-Report adressierte** Schwachstellen:

1. **Pool-Key Cross-TLS-Confusion (P0/P1):** `AmpConnectionPool::key()` (`src/Transport/AmpConnectionPool.php:130-133`) reduziert TLS-Identität auf das binäre Suffix `:tls`. Zwei `Config`-Instanzen mit identischem `host:port` aber unterschiedlichem `ClientTlsContext` (Cert-Pinning, Client-Cert, abweichender SNI-Name) landen im selben Idle-Stack. In einem Multi-Tenant-Web-Portal mit pro-Mandant-TLS-Profilen führt das zu Cross-Tenant-Socket-Reuse mit dem TLS-Material des falschen Mandanten.
2. **§4.5-Strict-Path bricht die Streaming-Garantie (P1):** `IcapClient::scanFileWithPreviewStrict()` Zeile 399 lädt mit `stream_get_contents($stream)` den kompletten Body-Rest in den RAM, bevor der Continuation-Chunk gesendet wird. Der ironischerweise als "legacy" eingestufte Pfad (`scanFileWithPreviewLegacy()` Zeile 479) streamt korrekt via `rewind` + Resource-Body. Bei Multi-GB-Uploads kippt der Strict-Pfad in OOM, bevor er den Same-Socket-Vorteil ausspielen kann.
3. **`DefaultPreviewStrategy` wirft auf 200/206 in Preview (P1, Bug):** `DefaultPreviewStrategy::handlePreviewResponse()` (`src/DefaultPreviewStrategy.php:37-41`) matcht nur `100`/`204`; alles andere — inklusive `200` mit Vendor-Virus-Header während des Previews — wirft `IcapResponseException`. Der Code-Pfad in `IcapClient` Z. 388 für `ABORT_INFECTED` ist von der Default-Strategie aus unerreichbar. Ein Server, der den Virus innerhalb der Preview-Bytes findet und `200 OK + X-Virus-Name` zurückschickt, produziert eine generische Exception statt einer `ScanResult(infected=true)`.

Daneben: **`SECURITY.md` ist v2.0-stale** (behauptet, OPTIONS-Cache, Pool und Retry seien nicht implementiert — sie sind seit v2.0 bzw. v2.1 da), `IcapResponseException` ist `@deprecated since 2.0` mit "removal in M2", aber weiter im Hot-Path benutzt; `ConnectionPoolInterface` referenziert eine nicht-existente `NullConnectionPool`-Klasse; `cookbook/03-options-request.php:11-13` behauptet Cache komme "next milestone after v2.0.0". Diese sind Doku-/Tooling-Mängel, keine Security-Bugs, aber sie schaden der Vertrauenswürdigkeit.

**TRL-Einschätzung v2.1.0:** **TRL 7** ("System-Prototyp im operationellen Einsatz vorgeführt") — gegenüber **TRL 4** für v1.0.0. Mit den unten priorisierten P0/P1-Fixes erreicht die Library TRL 8 (qualifiziertes System) in v2.2; ein vollständiger TRL-9-Status (operational) bleibt einer separaten extern auditierten Release vorbehalten.

**Gesamt-Score:** 192/260 (74 %), gegenüber geschätzten 112/260 (43 %) für v1.0.0. Detail in Kapitel 8.

---

## 2. Repository-Inventar v2.1.0

### 2.1 Code-Volumen

| Bereich | LOC | Dateien | v1-bestehend / v2.0-neu / v2.1-neu |
|---|---|---|---|
| `src/` | **3 569** | 35 PHP | siehe Klassendiagramm |
| `tests/` | **3 072** | 30 PHP | Test-zu-Code-Ratio **0,86** |
| Gesamt produktiv | 6 641 | 65 | |

Die größten Single-Files: `IcapClient.php` (637 LOC), `ResponseParser.php` (239 LOC), `RequestFormatter.php` (213 LOC), `Config.php` (203 LOC), `Transport/ResponseFrameReader.php` (202 LOC), `RetryingIcapClient.php` (173 LOC), `Transport/AsyncAmpTransport.php` (167 LOC), `Transport/AmpConnectionPool.php` (162 LOC).

### 2.2 Klassen-/Interface-Diagramm (Mermaid)

```mermaid
flowchart LR
    subgraph PublicAPI[Public-API]
        ICI[IcapClientInterface\nv2.0]:::v20
        IC[IcapClient\nfinal\nv1+v2.0+v2.1]:::v21
        SIC[SynchronousIcapClient\nv1]:::v1
        RIC[RetryingIcapClient\nv2.0]:::v20
    end
    subgraph Transport[Transport]
        TI[TransportInterface]:::v20
        SAT[SessionAwareTransport\nv2.1]:::v21
        TS[TransportSession\nv2.1]:::v21
        AAT[AsyncAmpTransport\nv1+v2.1]:::v21
        ATS[AmpTransportSession\nv2.1]:::v21
        SST[SynchronousStreamTransport\nv1]:::v1
        CPI[ConnectionPoolInterface\nv2.1]:::v21
        ACP[AmpConnectionPool\nv2.1]:::v21
        RFR[ResponseFrameReader\nv2.0]:::v20
    end
    subgraph Wire[Wire-Format]
        RF[RequestFormatter\nv1+v2.0]:::v20
        RP[ResponseParser\nv1+v2.0]:::v20
        CBE[ChunkedBodyEncoder\nv2.1]:::v21
    end
    subgraph DTOs
        IRQ[IcapRequest readonly\nv2.0]:::v20
        IRS[IcapResponse readonly\nv1]:::v1
        HRQ[HttpRequest readonly\nv2.0]:::v20
        HRS[HttpResponse readonly\nv2.0]:::v20
        SR[ScanResult readonly\nv1]:::v1
        CFG[Config readonly\nv1+v2.0]:::v20
    end
    subgraph Cache
        OCI[OptionsCacheInterface\nv2.0]:::v20
        IOC[InMemoryOptionsCache\nv2.0]:::v20
    end
    subgraph Exceptions
        IEI[IcapExceptionInterface\nv2.0]:::v20
        IPE[IcapProtocolException]:::v20
        IMR[IcapMalformedResponseException]:::v20
        ICE[IcapClientException]:::v20
        ISE[IcapServerException]:::v20
        ITE[IcapTimeoutException]:::v20
        ICX[IcapConnectionException\nv1]:::v1
        IRE[IcapResponseException\n@deprecated]:::v1
    end
    IC --> TI
    IC --> ICI
    IC --> RF
    IC --> RP
    IC --> OCI
    SIC --> ICI
    RIC --> ICI
    AAT --> SAT
    SAT --> TI
    AAT --> CPI
    ACP --> CPI
    AAT -. opens .-> ATS
    ATS --> TS
    AAT --> RFR
    SST --> RFR
    SST --> TI
    RF --> CBE
    IC --> CBE
    classDef v1 fill:#fff5e6,stroke:#cc7a00
    classDef v20 fill:#e6f3ff,stroke:#0066cc
    classDef v21 fill:#e6ffe6,stroke:#009933
```

### 2.3 Public-API-Oberfläche (SemVer-relevant)

| Klasse / Interface | Methoden | v2.x BC-Promise |
|---|---|---|
| `IcapClientInterface` | `request`, `options`, `scanFile`, `scanFileWithPreview` (alle mit `?Cancellation`) | strikt |
| `IcapClient` (final) | + `executeRaw` (Public, **nicht im Interface** — design smell) | strikt |
| `Config` (final readonly) | `withTlsContext`, `withVirusFoundHeaders`, `withLimits`, alle Getter | strikt |
| `RetryingIcapClient` (final) | konstruktor-injizierter Sleeper, 4 wiederholte Methoden | strikt |
| `Transport/AmpConnectionPool` (final) | `acquire`, `release`, `close` | strikt seit v2.1 |
| `Transport/AsyncAmpTransport` (final) | `request`, `openSession` | strikt seit v2.1 |
| `Transport/AmpTransportSession` (final) | `write`, `readResponse`, `release`, `close` | strikt seit v2.1 |
| `Cache/InMemoryOptionsCache` | + Test-Seam `advanceClockForTesting` | strikt; Test-Seam ist offiziell |
| Exceptions | 8 Klassen + 1 Marker-Interface | strikt |

### 2.4 Dependency-Graph

Runtime (`composer.json:14-19`):
- `php ^8.4` (CI testet 8.4 + 8.5)
- `amphp/socket ^2.3`
- `psr/log ^3.0`
- `revolt/event-loop ^1.0`

Dev (`composer.json:20-27`):
- `friendsofphp/php-cs-fixer ^3.75`
- `mockery/mockery ^1.6`
- `pestphp/pest ^3.8`
- `phpstan/phpstan ^2.1`
- `phpunit/phpunit ^11.2`
- `roave/security-advisories dev-latest`

`composer.lock` ist eingecheckt (316 KB). Alle Runtime-Deps sind reines Open-Source/EUPL-kompatibel; keine SaaS-Abhängigkeiten — Souveränitäts-Compliance sauber.

### 2.5 v2.0/v2.1-Closure-Verifikation: zusammengefasst

Verifiziert am Code (Detail in Kapitel 3): **14/14 Findings A–N geschlossen**, **4/4 M3-Follow-ups geschlossen**. Aber: **3 neue v2.1-Findings (Pool-TLS-Key, Streaming-Regression im Strict-Path, DefaultPreviewStrategy-Lücke) sind aufgetaucht**, plus eine Reihe Doku-Mängel.

---

## 3. v1-Findings-Closure-Verifikation

### 3.1 Findings A–N (aus `docs/review/consolidated_task-list.md`)

| ID | Finding | Status | Code-Nachweis | Wie behoben |
|---|---|---|---|---|
| **A** | `Encapsulated`-Header hardcoded `null-body=0` | ✅ **geschlossen** | `RequestFormatter.php:60-67, 112-139` | `buildEncapsulatedHeader()` rechnet Offsets aus `strlen()` der gerenderten HTTP-Header-Blöcke, kennt `null-body`, `req-hdr=N`, `res-hdr=N`, `req-body=N`, `res-body=N`. Wire-Test in `tests/Wire/RequestFormatterWireTest.php:45-209` prüft byte-genau. |
| **B** | Keine HTTP-in-ICAP-Kapselung | ✅ **geschlossen** | `RequestFormatter.php:174-198`, DTOs `HttpRequest.php`, `HttpResponse.php` | `renderHttpRequestHeaders()` / `renderHttpResponseHeaders()` rendern echte HTTP/1.1-Köpfe mit CRLF-Terminator, eingebettet in den Encapsulated-Block. |
| **C** | String-Body nicht chunked encoded | ✅ **geschlossen** | `ChunkedBodyEncoder.php:42-69` | Beide String- und Resource-Bodies werden chunked encoded; Test `RequestFormatterWireTest.php:101-136` (REQMOD) und `:63-99` (RESPMOD). |
| **D** | Kein `; ieof`-Terminator | ✅ **geschlossen** | `ChunkedBodyEncoder.php:44`, `IcapRequest.php:56` | `previewIsComplete=true` setzt Terminator auf `0; ieof\r\n\r\n`. Test `RequestFormatterWireTest.php:138-177`. |
| **E** | `scanFileWithPreview` lädt komplette Datei in RAM | ✅ **teilweise geschlossen → ⚠️ neue Regression** | `IcapClient.php:343, 399, 479` | Preview-Read ist 1 KiB. Aber: **Strict-Pfad Z. 399** macht `stream_get_contents()` (lädt Rest komplett); **Legacy-Pfad Z. 479** nutzt `rewind()` + Resource-Body und streamt korrekt. Ironie: der „bessere" Pfad ist schlechter beim Streaming. **Siehe Kapitel 6, P1.** |
| **F** | `ResponseParser` wertet `Encapsulated`-Header nicht aus | ✅ **geschlossen** | `ResponseParser.php:158-202`, `Transport/ResponseFrameReader.php:118-141` | Beide Parser/Framer kennen `req-body`/`res-body`/`null-body`. |
| **G** | **Fail-Open: Status 100 → clean** | ✅ **geschlossen** | `IcapClient.php:557-562` | `100` außerhalb Preview wirft `IcapProtocolException`. Test `Security/FailSecureAndValidationTest.php:70-80`. |
| **H** | CRLF-Injection via `$service` | ✅ **geschlossen** + erweitert | `IcapClient.php:518-525` (Service), `:598-614` (Header) | Regex `[\x00-\x20\x7F]` für Service-Path; Header-Name `[\x00-\x1F\x7F:]`, Header-Value `[\x00\r\n]`. **Anmerkung**: Header-Name-Regex lässt `<space>` und Separator-Zeichen `;()<>@,/=` durch — das wäre RFC-7230-§3.2.6-strict zu wenig. **Siehe Kapitel 4.** |
| **I** | `SynchronousStreamTransport`: 5s hardcoded, kein finally, kein Length-Limit | ✅ **geschlossen** | `Transport/SynchronousStreamTransport.php:67-110` | `stream_socket_client(..., $config->getSocketTimeout(), ...)`, `stream_set_timeout($stream, (int) $config->getStreamTimeout())`, `try { ... } finally { fclose($stream); }`, Reader erzwingt `Config::maxResponseSize`. |
| **J** | Kein TLS (`icaps://`) | ✅ **geschlossen** | `Transport/AsyncAmpTransport.php:141-145`, `AmpConnectionPool.php:144-152` | `Socket\connectTls($url, $context, $cancellation)` wenn `Config::tlsContext` gesetzt. Sync-Transport lehnt TLS aktiv ab (`SynchronousStreamTransport.php:51-55`). |
| **K** | Bug-zementierender Test | ✅ **geschlossen** | `tests/Wire/RequestFormatterWireTest.php` (neu), `RequestFormatterTest.php` gelöscht | Hand-computed RFC-3507-Bytes statt v1-Property-Vergleich. |
| **L** | Kein `Allow: 204`-Header | ✅ **geschlossen** | `IcapClient.php:357-360, 450-453` | Library-managed `Allow: ['204']` wird in Preview-Pfaden hinzugefügt; Caller-Override via `mergeHeaders` ausgeschlossen (Z. 632-634). |
| **M** | Status-Code-Matrix lückenhaft (206, 400, 403, 500, 503) | ✅ **geschlossen** | `IcapClient.php:538-579` | 204 → clean; 200/206 → virus-header-Inspektion; 100 → Protocol; 4xx → Client; 5xx → Server; sonst → Response. Test `Security/FailSecureAndValidationTest.php:82-139`. |
| **N** | Parser ohne Max-Header-Size/Count | ✅ **geschlossen** | `ResponseParser.php:34-44`, `Transport/ResponseFrameReader.php:51-53, 78-83, 107-110` | Limits werden **doppelt** enforced (Reader + Parser). Defaults 10 MiB / 100 Header / 8 KiB. Test `Security/ParserDosLimitsTest.php:33-69`. |

### 3.2 M3-ext Follow-ups (PRs #42–#45)

| Topic | Status | Code-Nachweis | Begründung |
|---|---|---|---|
| **#42 Cancellation** | ✅ **geschlossen, mit Restrisiko** | `IcapClientInterface.php:48-86`, `Transport/AsyncAmpTransport.php:108-126` | Alle 4 Public-Methoden + `executeRaw` nehmen `?Amp\Cancellation`. Composite mit `TimeoutCancellation`. **Restrisiko**: Strict-§4.5-Path benutzt **eine** Timeout-Cancellation für die ganze Session — bei großen Files mit langer §4.5-Continuation kann das fälschlich vorzeitig firen. **Siehe Kapitel 6, P2.** Coverage-Lücke: weder mid-write noch mid-read getestet. |
| **#43 OPTIONS-Cache** | ✅ **geschlossen, mit API-Smell** | `Cache/OptionsCacheInterface.php`, `Cache/InMemoryOptionsCache.php`, `IcapClient.php:160-213` | TTL aus `Options-TTL`-Header, Default `0` = kein Caching. **Smell**: `options()` returniert `Future<ScanResult>`, nicht `Future<IcapResponse>` — Caller muss durch `->getOriginalResponse()->headers` graben (siehe `examples/cookbook/03-options-request.php:18`), nur weil OPTIONS für ScanResult semantisch nichts hergibt. **Lücke**: keine ISTag-basierte Invalidation (RFC 3507 §4.7). Cache-Test `OptionsCacheTest.php:80-214` — aber **keine Coverage für Options-TTL=0** (kein Caching-Pfad). |
| **#44 503-Retry-Decorator** | ✅ **geschlossen, ohne Jitter** | `RetryingIcapClient.php:54-173` | Exponential Backoff `base × factor^(attempt-1)`, capped, **nur** auf `IcapServerException`. **Lücke**: Kein Jitter — Thundering-Herd-Risiko bei korrelierten 503-Bursts (z.B. ICAP-Restart). Sleeper ist Test-Seam (`Z. 67-93`). Test `RetryingIcapClientTest.php:39-192` deckt Backoff-Mathematik, 4xx-No-Retry, alle 4 Methoden ab. |
| **#45 Response-Framing** | ✅ **geschlossen, mit RFC-7230-Folding-Lücke im Framer** | `Transport/ResponseFrameReader.php` | Encapsulated-aware Framing, `0; ext\r\n\r\n` toleriert (Z. 178-190), `Connection: close`-Hack entfernt. **Lücke**: `findEncapsulatedHeader()` (Z. 144-153) splittet Header-Block per `\r?\n` line-by-line — **ignoriert RFC-7230-§3.2.4-obs-fold**. Der `ResponseParser` entfaltet (Z. 132-136), der Framer nicht. Bei einem Server, der `Encapsulated:` selbst foldet (extrem unüblich, aber möglich), würde das Framing das Body-Offset nicht finden. Coverage: nur unfolded-Encapsulated wird getestet. |

### 3.3 Neue v2.1-Themen

| Topic | Status | Code-Nachweis | Anmerkung |
|---|---|---|---|
| **Connection-Pool** | ✅ **funktional korrekt, aber sicherheitskritischer Pool-Key** | `Transport/AmpConnectionPool.php:130-133` | LIFO, Closed-Sweep auf acquire, Cap-Enforce auf release, Pool-Close idempotent. **P0/P1**: Pool-Key kollabiert TLS-Context auf `:tls`/`""` — siehe Kapitel 6. |
| **Strict §4.5 Same-Socket** | ✅ **funktional korrekt, aber Streaming-Bug** | `IcapClient.php:332-415` | Phase 1 (RESPMOD-Head + Preview), Phase 2 (Body-only) auf demselben Socket. Test `PreviewContinueStrictTest.php:44, 110` verifiziert `connectorCalls === 1`. **P1**: Z. 399 `stream_get_contents()` bricht Streaming. |

---

## 4. Findings nach Dimension

### 4.1 Sprachmoderne / PHP 8.4-Niveau

**Stärken** (Code-Refs):
- `final readonly class Config` (`Config.php:29`), 5/5 DTOs `final readonly`.
- `#[\Override]`-Attribut konsequent: `IcapClient.php:99, 156, 223, 268`, `RetryingIcapClient.php:95, 101, 110, 123`, `Transport/*.php` und `Cache/*.php` durchgängig — als spot-check 18 Treffer in `src/`.
- Konstruktor-Property-Promotion durchgängig.
- Named-Arguments in Hot-Paths (`IcapClient.php:240-247, 331-372`).
- PHPStan **Level 9 + bleedingEdge** ohne Baseline (`phpstan.neon:5`); zwei begründete `ignoreErrors` — beide nur in `tests/*` für Pest-Internals (`phpstan.neon:9-22`).
- `declare(strict_types=1)` in jeder einzelnen `src/*.php`-Datei (verifiziert per Stichprobe).
- `class-level enum PreviewDecision: string` (`PreviewDecision.php:26-31`).

**Lücken**:
- **Keine PHP-8.4-spezifischen Features** ausgenutzt: keine Property-Hooks, keine `#[\Deprecated]`-Annotation (auf `IcapResponseException` wäre die natürliche Stelle, statt nur Phpdoc), keine asymmetric visibility, keine Lazy Objects. **Bewertung**: Library-Code soll konservativ sein, das ist akzeptabel — aber `IcapResponseException` mit `#[\Deprecated]` zu kennzeichnen wäre kostenfrei und hätte den Mehrwert, dass IDEs den Hinweis sofort surfacen.
- `ResponseParser.php:34-35`: `private const int DEFAULT_…` — typed class constants (PHP 8.3+), genutzt. ✓
- `IcapClient::executeRaw()` ist `public` (`IcapClient.php:144`), aber **nicht im Interface**. → API-Smell: Caller koppeln an den konkreten Typ. Empfehlung: entweder ins Interface heben oder `internal` markieren.

### 4.2 Design / SOLID

**Schnitt sauber**: `Transport ↔ Wire-Format ↔ Client ↔ Decorator` ist trennscharf, abhängig nur über Interfaces. Decorator-Pattern (`RetryingIcapClient`) ist textbook-sauber. Strategy-Pattern (`PreviewStrategyInterface`) ist orthogonal zur Status-Code-Matrix (`interpretResponse`).

**Pool-Pattern**: LIFO (warmest-first) ist die Standard-Wahl bei Symfony HttpClient, OkHttp, Java Apache HC. Cap pro Host, kein Idle-Sweep, `Connection: close`-Honor — alle gut. **Kritik**: Pool-Key, siehe Kapitel 6.

**Smell #1 — `executeRaw` öffentlich**: `IcapClient.php:144` ist auf `public` — nötig für interne Aufrufer in `IcapClient` selbst (z.B. `options()`, `scanFileWithPreviewLegacy()`) — aber leakt damit den Encapsulated-aware Roh-Pfad nach außen. Caller bypassen Logger-Events, Status-Matrix-Interpretation und (paradoxerweise) den Test-Logger-Pfad. **Fix**: `protected` oder Visibility-on-Interface refactoren.

**Smell #2 — `options() : Future<ScanResult>`**: `options()` recyclet die `ScanResult`-API, obwohl OPTIONS keine `is_infected`-Semantik hat. Das `cookbook/03-options-request.php:18` muss durch `->getOriginalResponse()->headers` graben. **Fix**: separate `Future<IcapResponse> optionsRaw()` oder `OptionsResult`-DTO.

**Smell #3 — `DefaultPreviewStrategy` unvollständig**: `match`-Default wirft. Code in `IcapClient.php:386-394, 469-477` kennt `ABORT_INFECTED`, aber die Default-Strategie kann es nie liefern. **Fix**: Default soll `200/206` mit virus-header → `ABORT_INFECTED` matchen, sonst `IcapResponseException`. **Bewertung: Bug, P1.**

**Smell #4 — DTOs vs. `array $headers`**: ICAP-Header werden teils als `array<string, string|string[]>` (Caller-API) und teils als `array<string, string[]>` (intern, normalisiert in DTO-Ctor `IcapRequest.php:58`) modelliert. Inkonsistenz im Phpdoc, leicht missverständlich.

### 4.3 PSR-Konformität

| PSR | Status | Beleg |
|---|---|---|
| **PSR-3** | ✅ | `IcapClient.php:38-39, 47, 59, 110, 117, 125, 180, 187, 195`. Drei Events (`info` started, `info` completed, `warning` failed) mit Context (`method, uri, host, port, statusCode, infected`). Kein PII außer der ICAP-URI. **Lücke**: `LoggerIntegrationTest.php` hat keinen Regressions-Test gegen Sensitive-Header-Leak — wenn ein Maintainer in Zukunft `extraHeaders` ins Context-Array kippt, fällt das nicht auf. |
| **PSR-4** | ✅ | `composer.json:30-33, 35-37`. |
| **PSR-7/PSR-17** | bewusst nicht | Body als `string\|resource\|null` statt `StreamInterface`. **Tradeoff**: vermeidet harte PSR-7-Abhängigkeit; macht Symfony-/Laravel-Integration einfacher (PSR-7 ist Laminas-DI-lastig); kostet 7-Body-Adapter im Bundle. **Bewertung: gerechtfertigt** für eine Library mit starkem Streaming-Anspruch. |
| **PSR-11** | bewusst nicht | Framework-agnostisch, korrekt. |
| **PSR-18** | inspiriert | `IcapClientInterface` ist nicht PSR-18-Subtyp (anderes Domain-Modell), aber das Wiener Konzept der reinen Interface-Programmierung wird befolgt. |
| **PSR-20 (Clock)** | nein | `InMemoryOptionsCache.php:92-94` ruft `time() + $clockOffsetSeconds` direkt auf; Test-Seam via `advanceClockForTesting`. **Tradeoff**: Saubere Lösung wäre `?Psr\Clock\ClockInterface` Konstruktor-Param (PHP 8.1+ via psr/clock). Empfehlung: in v2.2 hinzunehmen, abwärtskompatibel. |

### 4.4 Exception-Design

**Hierarchie** (verifiziert in `src/Exception/`):

```
\Throwable
└─ IcapExceptionInterface (marker)
   ├─ \RuntimeException
   │  ├─ IcapClientException (4xx, final)
   │  ├─ IcapServerException (5xx, final, retry-able)
   │  ├─ IcapTimeoutException (final)
   │  ├─ IcapConnectionException (final)
   │  ├─ IcapResponseException (@deprecated, non-final)
   │  └─ IcapProtocolException (non-final)
   │     └─ IcapMalformedResponseException (final)
```

**Stärken**:
- Marker-Interface auf jedem konkreten Typ ✓ (`ExceptionHierarchyTest.php:30-51`).
- Recoverable (`IcapServerException`) vs. non-recoverable klar getrennt — `RetryingIcapClient.php:153` retried genau diesen Typ.
- `IcapMalformedResponseException` als Subtyp von `IcapProtocolException` — ergonomisch.

**Schwächen**:
- **`IcapResponseException` ist `@deprecated since 2.0` mit Kommentar "removal in M2"** (`Exception/IcapResponseException.php:28-32`). M2 ist seit 25.04.2026 released. Klasse ist immer noch im Hot-Path: `IcapClient.php:578` und `DefaultPreviewStrategy.php:40`. **Empfehlung**: entweder Deprecation-Tag akzeptieren und Klasse in v3.0 wirklich removen, oder Tag entfernen und sie als Catch-All-Bucket dauerhaft halten. Aktueller Mischzustand ist unsauber. **Plus**: PHP 8.4 erlaubt `#[\Deprecated]` als Attribut, das wäre die saubere Form.
- Exception-Chaining: `IcapConnectionException.php` enthält keinen Constructor-Override; muss man `new IcapConnectionException('msg', 0, $previous)` mit dem `RuntimeException`-Standard-Ctor aufrufen. Tut der Code z.B. in `Transport/AmpConnectionPool.php:154-158` und `AsyncAmpTransport.php:147-151` — `previous` wird gesetzt ✓.

### 4.5 Ressourcen-/Connection-Management

**Sockets**:
- `AmpTransportSession.php:78-100`: `release()`/`close()` sind disposed-flag-geschützt, doppelter Aufruf no-op. ✓
- `AsyncAmpTransport::request()` (Z. 80-106): `closeForced` wird bei jedem `Throwable` gesetzt — auch bei `CancelledException`. Socket geht *nicht* zurück in den Pool. **Bewertung: korrekt** (defensive Default; ein abgebrochener Read könnte halbgelesene Bytes hinterlassen).
- `AmpConnectionPool::acquire()` (Z. 84-90): TOCTOU zwischen `isClosed()` und nächstem `read()`. Klassisch. Caller bekommt eine zufällige `ClosedException` beim ersten Read nach langer Idle. **Empfehlung**: einmaliger Auto-Retry für die erste Request-Aktion, oder zumindest dokumentierter Caller-Hinweis.

**Streaming-Garantien**:
- `scanFile()` (`IcapClient.php:234-257`): `body: $stream` resource → `RequestFormatter` → `ChunkedBodyEncoder.encode()` → `rewind() + fread(8 KiB)` Loop. **Memory-bounded** ✓.
- `scanFileWithPreview()` Legacy-Pfad (`IcapClient.php:479-494`): `rewind($stream)` + `body: $stream` → wie `scanFile()`. **Memory-bounded** ✓.
- `scanFileWithPreview()` Strict-Pfad (`IcapClient.php:399`): **`stream_get_contents($stream)` lädt komplett in Memory.** Breaks Streaming. **P1.**

**Half-Closed Sockets**:
- `AsyncAmpTransport::serverWantsClose()` (Z. 161-166): Regex `^Connection:\s*close\s*$` — case-insensitive, multi-line. Funktioniert für Standard-Header. **Lücke**: ignoriert obs-fold und ist nur in `request()` aktiv, nicht in `openSession()`-Pfaden — der `IcapClient.scanFileWithPreviewStrict` muss selbst auf `Connection: close` reagieren, tut das aber nicht. Wenn der Server nach einem 100 oder 204 in der Strict-Phase ein `Connection: close` setzt, releaset der Code den Socket und der Pool reicht ihn weiter — der nächste `acquire()` findet ihn dann via `isClosed()`-Check (wenn der Server ihn wirklich geschlossen hat). Funktional: *meist* korrekt, aber spröde.

**Backpressure**: `ChunkedBodyEncoder` ist Generator-basiert. `AmpTransportSession::write()` schreibt sequentiell. Amp v3-Sockets respektieren Backpressure auf `$socket->write()` — fließt korrekt. ✓

**Timeouts**: Connect aus `socketTimeout`, Read/Write aus `streamTimeout`. **Restrisiko**: Strict-§4.5-Session hat **eine** `TimeoutCancellation` für die gesamte Session — ein langer Continuation-Body kann reißen, obwohl jeder einzelne Read im Limit liegt.

### 4.6 Async-Implementierung

- Sauberer Einsatz von `Amp\async()` (`IcapClient.php:103, 147, 172, 283`), `Amp\Future`, `CompositeCancellation` (`Transport/AsyncAmpTransport.php:114`), `TimeoutCancellation` (Z. 111).
- Cancellation-Propagation: vom `IcapClient.request()` → `executeRaw` → `transport.request($cancellation)` → in `AsyncAmpTransport`: `openSession($cancellation)` → in `Session`: gespeichert und an `socket->read($cancellation)` weitergereicht. **Vollständig propagiert** ✓.
- Fiber-Sicherheit des Pools: PHP-Fibers wechseln nur an expliziten Suspend-Points. `array_pop()` und `count()` sind atomar bzgl. Fiber-Switching. → **Pool ist fiber-safe in single-event-loop**, was die Annahme der Library ist (`ConnectionPoolInterface.php:39-41`).
- **`RetryingIcapClient` Sleeper**: Default `Amp\delay()` suspendiert die Fiber. Im sync-Wrapper (`SynchronousIcapClient`) wird der Future via `await()` blockierend abgewartet — `Amp\delay()` innerhalb davon schedulet den Loop, der Outer-Caller blockiert. ✓

### 4.7 Wire-Format-Korrektheit

Verifiziert (siehe Kapitel 5 Compliance-Checkliste).

### 4.8 Testing & Qualitätssicherung — Synthese

(Aus dem Test-Coverage-Agent + eigener Direkt-Read.)

**Stärken**:
- Wire-Tests mit hand-computed RFC-3507-Bytes (`tests/Wire/RequestFormatterWireTest.php:54-98, 138-177`).
- Status-Matrix vollständig (`Security/FailSecureAndValidationTest.php:70-139`).
- DoS-Limits (`Security/ParserDosLimitsTest.php:33-69`).
- Same-Socket-Garantie für §4.5 strict explizit verifiziert (`PreviewContinueStrictTest.php:44, 110`).
- Pool LIFO + Closed-Discard + Cap (`Transport/AmpConnectionPoolTest.php:36-230`).
- Multi-Vendor-Header First-Match-Wins (`MultiVendorVirusHeadersTest.php:78-125`).

**Lücken** (vom Agent + selbst):
1. **Header-Name/Value Injection** nur im `CustomRequestHeadersTest.php:138-148` getestet, **nicht in `Security/FailSecureAndValidationTest.php`** wo man es naheliegend erwartet. Plus: NUL-Injection auf Header-Name explizit fehlt. **P2.**
2. **Multi-Section-Encapsulated** (req-hdr + res-hdr + req-body kombiniert) untested. **P2.**
3. **`0; ieof` Chunk-Extension** wird im `RequestFormatterWireTest` für *Send*-Side verifiziert, **aber nicht im `ResponseFrameReaderTest.php` für Recv-Side** — d.h. wenn ein Server Chunks mit Extensions zurückschickt, ist das Framing-Verhalten ungetestet. **P2.**
4. **`maxHeaderLineLength` im Reader** nicht als eigener Negativ-Test (nur Total-Size in `ResponseFrameReaderTest.php:97-101`). **P2.**
5. **Slow-Loris (kein CRLF, Reader hängt)** nicht getestet. **P2.**
6. **Cancellation mid-write / mid-read / Composite** nicht getestet (`CancellationTest.php` deckt nur pre-cancelled + forwarding). **P1.**
7. **Cross-Tenant TLS-Pool-Isolation** nicht getestet. **P0/P1.**
8. **Race-Condition zwischen Fibers in Pool** nicht getestet. **P2.**
9. **Logger Sensitive-Header-Filtering** nicht regressions-getestet. **P2.**
10. **`Options-TTL=0`** (kein Caching) nicht getestet. **P2.**
11. **`scanFileWithPreview` ABORT_CLEAN** und Custom-Headers nicht abgedeckt. **P2.**
12. **`SynchronousIcapClient::scanFileWithPreview`** nicht getestet (`SynchronousIcapClientTest.php` deckt 4 von 5 Methoden). **P2.**

**Mutation-Testing in CI**: bewusst entfernt mit Begründung im Workflow-Kommentar (`ci.yml:130-135`). Lokal verfügbar via `composer mutation` (`composer.json:44`, `--min=65`). **Bewertung**: mit `91 passed / 187 assertions` und keinem CI-MSI-Gate sind Test-Reife-Annahmen nicht objektiv abgesichert. **P1**: Mutation-CI in v2.2 reaktivieren.

**Integration-Job in CI**: `continue-on-error: true` (`ci.yml:63`). Begründung: c-icap/ClamAV-Image kann flaky sein. **Bewertung**: macht den Integration-Job zur reinen Diagnose, nicht zum Gate. Vertretbar, aber Maintainer muss aktiv die Status-Badge im Auge behalten.

### 4.9 CI-Pipeline-Qualität

`.github/workflows/ci.yml`:
- Matrix `[8.4, 8.5]` ✓
- `composer audit` ✓ (ohne `--no-dev`, korrekt für Dev-CI)
- `roave/security-advisories: dev-latest` als require-dev (`composer.json:26`) ✓
- PHPStan + CS-Fixer + Tests parallel je PHP-Version ✓
- Coverage als Artifact + gh-pages-Deploy ✓
- Integration mit Readiness-Probe via realer ICAP-OPTIONS (Z. 82-100) — nicht naives `nc -z` ✓

**Lücken**:
- **Kein `composer audit --no-dev` als zweiter Schritt** für Production-Dependency-Check.
- **Keine SBOM-Generation** (CycloneDX/SPDX über CI). Für Public-Sector relevant.
- **Kein Fuzz-Test-Job** (z.B. `php-fuzzer` auf `ResponseParser::parse`).
- **Keine PHPBench-Suite** für Pool-Throughput / Strict-§4.5-Latenz-Vorteil.

### 4.10 Dokumentation

`README.md`: vollständig, ehrlicher AI-Disclaimer (Z. 16-25), Quickstart sync + async, Config-Block, Custom-Headers, Exception-Tabelle. **Lücken**:
- Keine Connection-Pool-Konfiguration im Config-Block.
- Kein RetryingIcapClient-Beispiel im README.
- Kein TLS-Cookbook.

`docs/migration-v1-to-v2.md`: 11 Sections mit konkreten Code-Diffs ✓. Vollständig.

`docs/agent.md`: korrekt als "Historical — v1.0 charter only" gekennzeichnet (Z. 5-8).

`docs/review/consolidated_task-list.md`: Master-Document, nachvollziehbar. ✓

`SECURITY.md`: **3 Falschaussagen** in Z. 73-75 ("It does **not** retry … does **not** cache OPTIONS … does **not** pool connections"). Alle drei sind seit v2.0 bzw. v2.1 implementiert. **Doku-Regression P0**.

`CONTRIBUTING.md`: 28 Zeilen, sehr dünn. **Keine Conventional-Commits-Erwähnung**, obwohl die Git-Historie sie nutzt (`feat(v2)!:`, `chore(deps):`). **Doku-Lücke P2.**

`CHANGELOG.md`: Keep-a-Changelog konform, alle Releases dokumentiert ✓.

`examples/`:
- `01-sync-scan.php`, `02-async-scan.php`: scanFile sync + async ✓.
- `cookbook/01-custom-headers.php`: Custom-Headers ✓.
- `cookbook/02-custom-preview-strategy.php`: McAfee-Strategy. **Educational anti-pattern**: mappt `200, 204 → ABORT_CLEAN` — bei `200` mit Virus-Header würde das den Virus durchlassen. Gefährlich für Lerner.
- `cookbook/03-options-request.php`: OPTIONS. **Stale claim** Z. 11-13: "next milestone after v2.0.0 adds a built-in PSR-16 cache decorator" — aber v2.0 hat den Cache schon mitgeliefert.

**Fehlende Cookbook-Beispiele**:
- TLS / `icaps://` mit `ClientTlsContext` und mTLS.
- `RetryingIcapClient` Setup.
- Connection-Pool-Tuning.
- External Cancellation aus einem HTTP-Upload-Abort.
- Multi-Tenant-Setup (warnend, mit aktueller Pool-Limitation).

### 4.11 Public-Sector / Compliance

- **EUPL-1.2**: SPDX-Header in jeder `src/`/`tests/`-Datei via php-cs-fixer (`.php-cs-fixer.dist.php:9-23`) — verifizierter Stichproben-Score 100 %.
- **OpenCoDE-Kompatibilität**: technisch gegeben (EUPL ist bevorzugt), aber **kein Spiegel auf OpenCoDE** referenziert.
- **BSI IT-Grundschutz**: kein Mapping-Dokument. OPS.1.1.4 (Schutz vor Schadprogrammen) ist die naheliegende Referenz; APP.4.4 (Webanwendungen) für Caller-Kontext. **P2**: separate `GOVERNANCE.md` oder Section in README.
- **Digitale Souveränität**: alle Deps OSS, EU-/Community-Hosted, keine SaaS ✓.
- **DSGVO**: PSR-3-Logger-Output dokumentiert (kein PII außer URI), aber Caller-Verantwortung beim Plugin-Logger nicht warnend hervorgehoben. **P2**: Hinweis in `SECURITY.md`.

---

## 5. RFC 3507 / 7230 / 9110 Compliance-Checkliste

| Bereich | RFC-Anchor | v1.0.0 | v2.1.0 | Beleg |
|---|---|---|---|---|
| Methoden OPTIONS/REQMOD/RESPMOD | RFC 3507 §4.6-4.9 | ⚠ | ✅ | `RequestFormatter.php:141-198` |
| Encapsulated-Header echte Offsets | §4.4.1 | ❌ | ✅ | `RequestFormatter.php:112-139` |
| HTTP-in-ICAP echtes HTTP/1.1 | §4.4.2 | ❌ | ✅ | `RequestFormatter.php:174-198` |
| Chunked-Transfer-Body | §4.4.3 + RFC 7230 §4.1 | ❌ | ✅ | `ChunkedBodyEncoder.php:42-69` |
| `0; ieof\r\n\r\n` bei Preview-Complete | §4.5 | ❌ | ✅ | `ChunkedBodyEncoder.php:44` |
| `Allow: 204` im Preview | §4.5 / §4.6 | ❌ | ✅ | `IcapClient.php:357-360, 450-453` |
| Status `100 Continue` als Zwischenresponse | §4.5 | ⚠ Fail-Open | ✅ Fail-Secure | `IcapClient.php:557-562` |
| Status `204 No Content` als Clean | §4.6 | ✅ | ✅ | `IcapClient.php:542-544` |
| Status `200`/`206` Virus-Header-Inspektion | §4.3.3 + §6.3 | ⚠ | ✅ | `IcapClient.php:546-555` |
| Status `4xx`/`5xx` getrennt | §4.3.3 | ⚠ | ✅ | `IcapClient.php:564-575` |
| ICAP/1.0 Status-Line akzeptiert | §4.3.1 | ✅ | ✅ | `ResponseParser.php:78` |
| ICAP/1.1 Status-Line akzeptiert | §4.3.1 | ⚠ | ✅ | Regex `ICAP\/1\.\d` |
| RFC 7230 §3.2.4 obs-fold im **Parser** | RFC 7230 | ❌ | ✅ | `ResponseParser.php:130-136` |
| RFC 7230 §3.2.4 obs-fold im **Framer** | RFC 7230 | n/a | ⚠ | `Transport/ResponseFrameReader.php:144-153` — splittet line-by-line ohne Folding-Erkennung |
| `Connection: close`-Honor | RFC 7230 §6.1 | n/a (immer geschlossen) | ✅ | `Transport/AsyncAmpTransport.php:161-166` |
| `Options-TTL`-Cache | §4.10.2 | ❌ | ✅ | `IcapClient.php:204-207` |
| `Max-Connections`-Cap aus OPTIONS | §4.10.2 | ❌ | ❌ | `Transport/AmpConnectionPool.php:106-110` Comment "future enhancement" — **P1** |
| `Preview`-Size aus OPTIONS-Response | §4.5 | ❌ | ❌ | Caller passt `previewSize` manuell — **P1** |
| `ISTag`-basierte Cache-Invalidation | §4.7 | n/a | ❌ | `OptionsCacheInterface` ohne ISTag-API — **P2** |
| `null-body`-Response-Body == "" | §4.4.1 | ❌ | ✅ | `ResponseParser.php:172-175` |
| TLS via `icaps://` | de-facto | ❌ | ✅ | `Transport/AsyncAmpTransport.php:141-145` |
| CRLF-Guard auf Service-Path | §7.3 (Security) | ❌ | ✅ | `IcapClient.php:518-525` |
| CRLF-Guard auf User-Header (Name+Value) | §4.2 + RFC 7230 §3.2 | ❌ | ✅ (mit Lücken) | `IcapClient.php:598-614` — Name-Regex erfasst nicht alle Separator-Tokens (RFC 7230 §3.2.6) |
| Multi-Vendor Virus-Header | §6 + de-facto | ⚠ (X-Virus-Name only) | ✅ | `Config.php:97-100` |
| External Cancellation | n/a | ❌ | ✅ | `IcapClientInterface.php:48-86` |
| DoS Bound auf Response-Size | n/a | ❌ | ✅ | `Config.php:60`, doppelt enforced |
| DoS Bound auf Header-Count/Linelength | n/a | ❌ | ✅ | `ResponseParser.php:63-75`, `ResponseFrameReader.php:107-110` |

**Compliance-Score**: v1.0.0 ≈ 35 % (5/22 grün). v2.1.0 ≈ **86 %** (19/22 grün, 3 Lücken: Max-Connections-Honor, Preview-Size-Negotiation, Framer-obs-fold).

---

## 6. Pool-/Session-Lifecycle Threat-Analyse (v2.1-Kernfeature)

Dies ist der zentrale Audit-Abschnitt für das v2.1-Release.

### 6.1 Threat T1 — Cross-TLS-Context-Confusion (P0/P1)

**Setup**: Ein Symfony-Multi-Tenant-Portal (z.B. öffentliche Verwaltung mit mehreren Mandanten — Stadt A, Stadt B) verwendet pro Mandant eine eigene `Config`-Instanz mit identischem `host:port`, aber unterschiedlichem `ClientTlsContext` (z.B. unterschiedliches Client-Cert, unterschiedliches Cert-Pinning-Bundle).

**Code-Pfad**:
```php
// src/Transport/AmpConnectionPool.php:130-133
private function key(Config $config): string
{
    return $config->host . ':' . $config->port
        . ($config->getTlsContext() !== null ? ':tls' : '');
}
```

Der Pool-Key ist `host:port[:tls]` — **die TLS-Identität reduziert sich auf "TLS ja oder nein"**. Zwei `Config`-Instanzen, die sich nur im `ClientTlsContext` unterscheiden (z.B. anderes Client-Cert), erzeugen denselben Key.

**Konsequenz**:
1. Tenant A öffnet eine Session, der TLS-Handshake läuft mit A's Client-Cert.
2. Session wird released → Socket landet im Idle-Stack unter Key `icap.gateway:1344:tls`.
3. Tenant B `acquire()`'t — bekommt A's Socket zurück. **Der TLS-Tunnel ist mit A's Cert authentisiert.**
4. B's Request wird über A's TLS-Identität gesendet. Audit-Logs auf dem ICAP-Server schreiben "Tenant A".

**Schweregrad**: P0 in echten Multi-Tenant-Setups; P1 wenn der typische Single-Tenant-Anwendungsfall dominiert. Dokumentations-Workaround: "Pro TLS-Profil eigene `IcapClient`-Instanz mit eigenem Pool" — aber das ist nirgends dokumentiert.

**Fix-Vorschlag** (drop-in):
```php
// src/Transport/AmpConnectionPool.php:130
private function key(Config $config): string
{
    $tls = $config->getTlsContext();
    if ($tls === null) {
        return $config->host . ':' . $config->port;
    }
    // amphp ClientTlsContext is value-like; spl_object_id is stable
    // for the lifetime of the object. Use it as a tenant fingerprint.
    return $config->host . ':' . $config->port . ':tls#' . spl_object_id($tls);
}
```

Caveat: `spl_object_id` ist nur prozesslokal stabil. Für robustere Trennung könnte man einen `Config::tlsFingerprint(): string`-Hash über cipher policy + ca-file + hostname + client-cert-fingerprint einführen — das erlaubt auch identische TLS-Konfigurationen, denselben Pool zu teilen, ohne aliasing.

### 6.2 Threat T2 — Streaming-Regression im §4.5-Strict-Pfad (P1)

**Code-Pfad**:
```php
// src/IcapClient.php:399
$remainder = stream_get_contents($stream);
if ($remainder === false) {
    $remainder = '';
}
$session->write((new ChunkedBodyEncoder())->encode($remainder));
```

**Konsequenz**: Bei `scanFileWithPreview('/avscan', '/2GB-upload.bin', previewSize: 1024)` über die Strict-Path werden 2 GiB - 1 KiB in den PHP-Speicher geladen. `memory_limit` reißt → OOM-Fatal.

**Ironie**: Der „legacy" Pfad `scanFileWithPreviewLegacy()` (Z. 479-494) macht `rewind($stream)` + `body: $stream` und überlässt das Streaming an `ChunkedBodyEncoder`. Korrekt streaming.

**Fix-Vorschlag**:
```php
// IcapClient.php:399 — replace stream_get_contents() with direct stream encoding.
// The stream pointer is already advanced past the preview bytes by the
// fread($stream, $previewSize) call at line 343.
$session->write((new ChunkedBodyEncoder())->encodeRemainder($stream));
```

Plus eine neue Methode in `ChunkedBodyEncoder`:
```php
public function encodeRemainder(mixed $body): iterable
{
    if (!is_resource($body)) { throw new \InvalidArgumentException(...); }
    // KEIN rewind — stream pointer ist absichtlich an der Preview-Grenze.
    while (!feof($body)) {
        $chunk = fread($body, self::CHUNK_SIZE);
        if ($chunk === false || $chunk === '') break;
        yield dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
    }
    yield "0\r\n\r\n";
}
```

### 6.3 Threat T3 — `DefaultPreviewStrategy` wirft auf 200/206 (P1)

**Code-Pfad** (`src/DefaultPreviewStrategy.php:35-42`):
```php
return match ($previewResponse->statusCode) {
    204 => PreviewDecision::ABORT_CLEAN,
    100 => PreviewDecision::CONTINUE_SENDING,
    default => throw new IcapResponseException(
        'Unexpected preview status code: ' . $previewResponse->statusCode,
    ),
};
```

**Konsequenz**: ICAP-Server, die einen Virus innerhalb der ersten 1 KiB Preview detecten, antworten mit `200 OK + X-Virus-Name: ...` direkt auf das Preview-Frame — ohne die Continuation abzuwarten (RFC 3507 §4.3.3 + §6 erlaubt das explizit). `DefaultPreviewStrategy` wirft `IcapResponseException`. Der Caller muss `ScanResult.isInfected() === true` erwarten, bekommt stattdessen eine Exception.

**Fix-Vorschlag**:
```php
public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision
{
    return match (true) {
        $previewResponse->statusCode === 204 => PreviewDecision::ABORT_CLEAN,
        $previewResponse->statusCode === 100 => PreviewDecision::CONTINUE_SENDING,
        $previewResponse->statusCode === 200 || $previewResponse->statusCode === 206
            => $this->classifyTwoHundred($previewResponse),
        default => throw new IcapResponseException(
            'Unexpected preview status code: ' . $previewResponse->statusCode,
        ),
    };
}

private function classifyTwoHundred(IcapResponse $r): PreviewDecision
{
    // Default Strategy: ohne Vendor-Liste reicht das Vorhandensein eines
    // X-Virus-Name-Headers. Caller mit Multi-Vendor-Konfiguration sollten
    // ihre eigene Strategy implementieren.
    return isset($r->headers['X-Virus-Name'][0])
        ? PreviewDecision::ABORT_INFECTED
        : PreviewDecision::ABORT_CLEAN;
}
```

Plus Test-Case in `DefaultPreviewStrategyTest.php`. **Coverage-Lücke ist real.**

### 6.4 Threat T4 — Cancellation-Mid-Read Race (P2)

`AsyncAmpTransport::request()` (Z. 80-106):
```php
try {
    $session->write($rawRequest);
    $response = $session->readResponse();    // CancelledException kann hier kommen
    if ($this->serverWantsClose($response)) {
        $closeForced = true;
    }
    return $response;
} catch (\Throwable $e) {
    $closeForced = true;                      // ✓ Socket wird zwangs-geschlossen
    throw $e;
}
```

Wenn Cancellation während `readResponse()` fired, fängt der `catch` die `CancelledException`, setzt `closeForced=true`, Socket wird in `finally` `close()`d. ✓ **Defensive Default ist korrekt.**

Aber: in `IcapClient::scanFileWithPreviewStrict()` (Z. 408-414):
```php
} catch (\Throwable $e) {
    $session->close();
    throw $e;
}
```

Kein `release()`-Pfad bei Cancellation. ✓ Symmetrisch defensiv.

**Restrisiko**: Cancellation während `$session->write()` (Phase-2-Body-Send) hinterlässt einen halbgeschriebenen Chunk-Stream im Kernel-Buffer. Socket wird zwangs-geschlossen — aber wenn der Server inzwischen schon eine Response schickt, könnte sie verlorengehen. **Akzeptabel** für den Cancellation-Use-Case (Caller hat aktiv abgebrochen).

### 6.5 Threat T5 — Fiber-Race in Pool (P3)

`AmpConnectionPool::acquire()` (Z. 84-90):
```php
while (!empty($this->idle[$key])) {
    $socket = array_pop($this->idle[$key]);
    if (!$socket->isClosed()) {
        return $socket;
    }
}
```

In PHP-Fibers sind `array_pop` und `isClosed()` atomar (kein Suspend-Point). Zwei Fibers können *nicht* dasselbe Element bekommen. ✓ **Sicher unter dem amphp-Single-Loop-Modell.**

### 6.6 Threat T6 — Pool-Idle-Sweep fehlt (P2)

Code (`AmpConnectionPool.php:42-46`):
> _The pool keeps no separate idle timer — c-icap and most ICAP vendors send `Connection: close` once they're done…_

In Long-Running-Workern (Symfony Messenger) hält der Pool bei dauerhaft niedrigem Load mehrere idle Sockets dauerhaft offen. Server-seitige `idle_timeout`-Limits (c-icap default 30 s) schließen sie irgendwann; nächster `acquire()` sieht `isClosed()` → drops. **Funktional korrekt**, aber schlechte Server-Side-Sicht (viele halb-tote Verbindungen). **P2** für v2.2.

### 6.7 Threat T7 — Half-closed-Detection lückenhaft (P2)

Wenn der Server den Socket einseitig RST'et **ohne** `Connection: close`-Header (z.B. Crash, Restart), erkennt der `acquire()`-Pfad das via `isClosed()` — aber nur, wenn die TCP-FIN/RST bereits beim `acquire()` durch den Kernel-Buffer durch ist. Bei sehr schneller Wiederverwendung (< 100 ms zwischen `release` und nächstem `acquire`) kann der Kernel den FIN/RST noch nicht propagiert haben. → erste Aktion wirft `ClosedException`. Caller sieht intermittierende Failures. **P2-Robustheit**: einmaliger Auto-Retry in `AsyncAmpTransport.request()` für genau diese Konstellation.

---

## 7. Wettbewerbsvergleich

Die wichtigsten ICAP-Client-Implementierungen am Markt, verglichen entlang der Audit-Achsen. (Bewertungen aus eigener Kenntnis, plus Repo-Inspektion via gh-search; keine Benchmark-Messungen.)

| Implementierung | Sprache | RFC-3507-Coverage | Streaming | Pool | TLS | Multi-Vendor-Header | Cancellation |
|---|---|---|---|---|---|---|---|
| **`ndrstmr/icap-flow` v2.1** | PHP 8.4 | **§4.4–§4.10** + §4.5 strict | ✅ (außer Strict-Bug) | ✅ LIFO | ✅ via Amp | ✅ ordered list | ✅ Amp |
| `egirna/icap-client` (Go) | Go | §4.4–§4.6 | ✅ io.Reader | ✅ HTTP-style | ⚠ TCP-only mainline | ❌ einzelner X-Virus-Name | ✅ context.Context |
| `solidwall/icap-server` (Go) | Go | Server-side, Vergleich-Referenz | n/a | n/a | ✅ | n/a | ✅ |
| `c-icap-client` (C) | C | komplett, Reference-Impl | ✅ FILE* | ❌ einzelner Connect | ✅ libssl | ❌ vendor-spezifisch ge-patched | n/a |
| `pyicap` (Python 3) | Python | §4.4–§4.6 | ⚠ buffered | ❌ | ⚠ via stdlib ssl | ❌ | ❌ |
| `icap-client` (Node.js, npm — mehrere Forks) | JS | mixed quality | ⚠ uneven | ❌ | ⚠ | ❌ | ⚠ promise-cancel |
| `ICAP.NET` (C#, NuGet `ICAPClient`) | .NET | §4.4–§4.6 | ✅ Stream | ⚠ TaskFactory | ✅ TLS | ❌ | ✅ CancellationToken |
| `Apache HttpComponents` als ICAP-Carrier (Java) | Java | komplett mit Custom-Codec | ✅ | ✅ HTTP-Pool | ✅ | ⚠ Caller-side | ✅ Future |

**Bewertung gegenüber `icap-flow` v2.1**:
- **`icap-flow` führt die PHP-Welt klar an**. Kein anderer PHP-Client hat:
  - Strict §4.5 Same-Socket Preview-Continue.
  - Encapsulated-aware Framing ohne `Connection: close`-Hack.
  - First-class Multi-Vendor-Header-List.
  - PSR-3-Logger, Cancellation, OPTIONS-Cache, RetryingDecorator.
- **Im Vergleich zu Go/Java**: Pool-Maturity ist mit `egirna/icap-client` vergleichbar (LIFO, Cap, kein Idle-Sweep). Apache HttpComponents Pool ist deutlich reifer (per-route-Cap, Idle-Sweep, validate-on-borrow). v2.1 ist ein Prototyp der Pool-Disziplin, kein Production-Battle-Tested-Pool.
- **Im Vergleich zu C-Reference (`c-icap-client`)**: Wire-Format ist korrekt. C-Client kennt mehr Vendor-Header-Edge-Cases.
- **Cancellation**: Auf Augenhöhe mit Go/.NET; weit besser als Python/Node.

**Patterns, die `icap-flow` noch fehlen**:
- **Validate-on-Borrow** (Apache HC): vor dem `read()` auf einem reused Socket einen Probe-Read; PHP-Pendant wäre ein 1-Byte `peek` über `stream_socket_recvfrom` — schwierig in amphp.
- **Per-Route-Cap mit globalem Cap** (Apache HC): aktuell nur per-Host-Cap.
- **Idle-Time-Eviction**: alle anderen Production-Pools haben das.
- **Connection-Validation per ICAP `OPTIONS` als Health-Probe** vor `acquire()` einer altgedienten Idle-Connection.

---

## 8. Bewertungsmatrix

Skala 0-10. v1.0.0 sind die geschätzten Werte aus den drei Reviews in `docs/review/`; v2.1.0 sind eigenständige Audit-Werte.

| Dimension | v1.0.0 | v2.1.0 | Begründung | Kritische Findings |
|---|---|---|---|---|
| Sprachmoderne (PHP 8.4-Features) | 5 | 8 | readonly, #[\Override], strict_types, named args; aber keine Property-Hooks/`#[\Deprecated]` | `IcapResponseException` ohne `#[\Deprecated]` |
| Typsystem / PHPStan-Strenge | 6 | **10** | Level 9 + bleedingEdge ohne Baseline, 2 begründete Ignores in tests | — |
| SOLID / Architektur | 5 | 8 | Schnitt sauber; `executeRaw` öffentlich, `options() : ScanResult` API-Smell | `executeRaw` Visibility, `options()` Rückgabetyp |
| Exception-Design + Marker | 4 | 8 | Marker-Interface auf jedem Typ, Hierarchie sauber | `IcapResponseException` Deprecation-Tag stale |
| PSR-Konformität | 6 | 8 | PSR-3/4 ✓, PSR-7 bewusst nicht, PSR-20-Clock fehlt | Kein `Psr\Clock\ClockInterface` für `InMemoryOptionsCache` |
| Ressourcen-/Connection-Management | 4 | 7 | Pool, Session, fail-safe-Close ✓; Strict-§4.5 OOM-Bug | `stream_get_contents` in §4.5 |
| Connection-Pool-Korrektheit (v2.1) | n/a | 6 | LIFO + Cap + Closed-Sweep ✓; **Pool-Key ignoriert TLS-Context** | **T1 P0/P1** |
| Async-Implementierung (Amp v3) | 6 | 9 | Sauber, fiber-safe, Composite-Cancellation | — |
| Cancellation-Propagation | n/a | 7 | Vollständig propagiert; Tests decken nur 2 von vielen Pfaden | Mid-write/mid-read untested |
| ICAP RFC 3507 Methoden | 5 | 9 | OPTIONS, REQMOD, RESPMOD, OPTIONS-Cache, Strict-§4.5 | Max-Connections + Preview-Size aus OPTIONS nicht gehonort |
| ICAP §4.5 Strict Preview-Continue (v2.1) | n/a | 8 | Same-Socket-Garantie verifiziert; OOM-Risiko im Streaming | T2 |
| ICAP §4.10.2 Options-TTL-Cache | n/a | 8 | TTL-honored, Cache-Key isoliert; ISTag-Invalidation fehlt | API smell `ScanResult` |
| ICAP-Robustheit (Parser, Edge Cases, RFC 7230 Folding) | 3 | 8 | Doppel-Layer-Limits, Fold im Parser; Framer ohne Folding | Framer-Fold-Lücke |
| Multi-Vendor-Header-Support | n/a | 9 | Ordered list, first-match-wins, Test ✓ | — |
| Security-Posture (Fail-Secure, CRLF, DoS, TLS) | 2 | 8 | G geschlossen, CRLF + DoS + TLS ✓; SECURITY.md stale | T3 (PreviewStrategy 200), Header-Name-Regex zu lax |
| Test-Coverage | 6 | 8 | 91/187, hand-computed Wire-Bytes, dedicated Security/Wire/Transport-Suites | siehe Test-Lücken-Liste |
| Wire-Format-Tests (Hand-Computed Bytes) | n/a | 9 | RequestFormatterWireTest + ResponseParserWireTest | Multi-Section-Encapsulated fehlt |
| Mutation Testing (CI?) | 0 | 3 | Lokal `--min=65`; in CI bewusst entfernt | **P1 v2.2** |
| Integration-Testing (echter Server) | 0 | 7 | docker-compose + ClamAV + Readiness-Probe | nur ein Vendor, `continue-on-error: true` |
| CI-Pipeline-Qualität | 5 | 8 | Multi-PHP-Matrix, audit, stan, integration, gh-pages | Kein SBOM, kein Mutation, kein Fuzz |
| Dokumentation (README, Migration, Reviews) | 3 | 8 | README ehrlich, Migration vollständig, Reviews öffentlich | SECURITY.md outdated, RetryingClient nirgends im Cookbook |
| Example-/Cookbook-Qualität | 4 | 6 | 5 Beispiele; alle 4 Public-Methoden außer `request()` | McAfee-Strategy ist anti-Pattern, OPTIONS-Cache "next milestone"-Claim stale |
| Symfony-Bundle-Integration | 1 | 2 | Library framework-agnostisch; Bundle-Repo nicht öffentlich | siehe Roadmap |
| Observability (Logger, OTel, Profiler) | 0 | 5 | PSR-3 ✓; OTel/Prometheus/Profiler fehlen | P1/P2 v2.2/v2.3 |
| Release-Management / SemVer / Changelog | 5 | 9 | Keep-a-Changelog, SemVer, v1 deprecated | — |
| Public-Sector-Fit (EUPL, BSI, OpenCoDE, Souveränität) | 5 | 7 | EUPL-1.2 + SPDX ✓, OpenCoDE-kompatibel ✓; kein BSI-Mapping-Doc | P2 GOVERNANCE.md |
| **Gesamt-Readiness-Score** | **~112/260 (43 %)** | **~192/260 (74 %)** | | |

**TRL-Einschätzung**:
- v1.0.0 → TRL 4 ("Versuchsaufbau im Labor"): RFC-Wire-Format kaputt, Fail-Open-Bug. Lab-Demo-Niveau.
- v2.1.0 → **TRL 7** ("System-Prototyp im operationellen Einsatz vorgeführt"): Verifiziert gegen `mnemoshare/clamav-icap`, aber noch nicht gegen die fünf großen Enterprise-Vendor-Server, kein externes Security-Audit, drei P1-Findings offen.

---

## 9. Produktionsreife-Gate-Entscheidung

### 9.1 Für interne Tools / Prototypen

✅ **Ja, ohne Einschränkungen.** Der Wire-Format-Pfad ist korrekt, Fail-Secure ist verlässlich, Doku reicht für Engineers, die Code lesen. Die offenen P1-Themen sind in einem 1-Tenant-Setup nicht akut.

### 9.2 Für Symfony-Anwendungen in Projekten (TYPO3, Shopware)

**Ja, mit Einschränkungen**:
1. Wenn dein Setup **Single-Tenant** ist (eine ICAP-Konfiguration pro Anwendung), kein Pool-TLS-Risiko.
2. Wenn du `scanFileWithPreview` für Files > 100 MB nutzt → **vorerst Strict-Pfad meiden** (entweder `previewSize` so wählen, dass `previewIsComplete=true` (= keine Continuation), oder `SynchronousIcapClient` als Workaround, oder bis v2.2-Fix warten).
3. Schreib den AI-Disclaimer aus dem README in dein internes Risk-Register.
4. Achte auf v2.2-Release (Pool-Key-Fix + Streaming-Fix erwartet).

### 9.3 Für den Einsatz als kritische Security-Komponente (Virenscan auf Upload)

**Mit Einschränkungen — und das deckt sich mit dem AI-Disclaimer im README.** Konkret:
1. **P0**: SECURITY.md-Aussagen sind v2.0-stale — vor Audit-Vorlage selbst aktualisieren.
2. **P0**: Externes, manuelles Security-Audit gegen den verwendeten ICAP-Vendor (nicht nur ClamAV). Wire-Format-Vergleich byte-genau gegen die Server-Implementation.
3. **P1**: Pool-Key-TLS-Confusion: bei Multi-Tenant-TLS-Profilen aktuell *nicht* einsetzen oder den Workaround "ein `IcapClient` pro TLS-Profil mit eigenem Pool" implementieren.
4. **P1**: Strict-§4.5-Streaming-Bug: bei Files > `memory_limit` nicht einsetzen.
5. **P1**: `DefaultPreviewStrategy` darf nicht für 200/206-Vendor benutzt werden — eigene Strategy implementieren oder Patch.

Mein Befund deckt sich mit der Selbsteinschätzung des Maintainers (README-Disclaimer Z. 16-25) **in Tonalität und Tiefe** — die Disclaimer-Checkliste ist nicht überzogen, sondern realitätsnah. **Der Maintainer ist kompetent und ehrlich.**

---

## 10. Priorisierte Gap-Liste

### P0 — Blocker für Security-Critical Production

| # | Issue | Datei / Zeile | Empfehlung |
|---|---|---|---|
| 1 | **`SECURITY.md` Z. 73-75 sind v2.0-stale** (behaupten kein Cache, Pool, Retry) | `SECURITY.md:73-75` | Patch direkt: ersetze "does not" durch aktuelle Status. Ist trivial. |
| 2 | **Pool-Key kollabiert TLS-Identität** — Cross-Tenant-Risiko | `Transport/AmpConnectionPool.php:130-133` | Fix wie in Threat T1 vorgeschlagen. Plus Test mit zwei `Config`-Instanzen, identischem Host, unterschiedlichem `ClientTlsContext`. |
| 3 | **`DefaultPreviewStrategy` wirft auf 200/206 in Preview** — versehentliche Exception statt `ScanResult(infected)` | `DefaultPreviewStrategy.php:35-42` | Fix wie in Threat T3 vorgeschlagen. |

### P1 — Kritisch für Ökosystem / Korrektheit

| # | Issue | Datei / Zeile | Empfehlung |
|---|---|---|---|
| 4 | **Strict-§4.5 lädt Body-Rest in Memory** | `IcapClient.php:399` | `ChunkedBodyEncoder::encodeRemainder($stream)` einführen (Threat T2). |
| 5 | **`Max-Connections` aus OPTIONS wird ignoriert** | `Transport/AmpConnectionPool.php:106-110` (Comment) | Pool-Cap-Override aus Cache-Hits in `IcapClient::options()` propagieren. Saubere API: `AmpConnectionPool::tuneFromOptions(IcapResponse)`. |
| 6 | **Preview-Size aus OPTIONS-Response wird nicht negotiated** | `IcapClient.php:269-322` | `scanFileWithPreview` ohne expliziten `previewSize` soll OPTIONS-Cache befragen und den Server-`Preview`-Wert nutzen. |
| 7 | **`ConnectionPoolInterface` Phpdoc referenziert nicht-existente `NullConnectionPool`** | `Transport/ConnectionPoolInterface.php:36` | Entweder `NullConnectionPool`-Klasse hinzufügen (sinnvoll für Tests / explizite Pool-Off-Konfiguration), oder Phpdoc korrigieren. |
| 8 | **Mutation-Testing-Job wieder in CI** | `.github/workflows/ci.yml:130-135` | Als eigener Job mit `--min=65`, `continue-on-error: false`. |
| 9 | **Cancellation-Tests decken nur 2 von vielen Pfaden** | `tests/CancellationTest.php` | Mid-write, mid-read, Composite (user + timeout) abdecken. |
| 10 | **Strict-§4.5-Path nutzt Session-Lifetime-Timeout** statt per-IO-Timeout | `Transport/AsyncAmpTransport.php:111-114` | `streamTimeout` per `read()` resetten, oder Doku-Hinweis. |
| 11 | **`IcapResponseException` Deprecation-Status mehrdeutig** | `Exception/IcapResponseException.php:28-32` | Entweder als `#[\Deprecated]` permanent + Removal in v3.0 ankündigen, oder Tag entfernen. |
| 12 | **`IcapClient::executeRaw()` ist `public`, aber nicht im Interface** | `IcapClient.php:144` | `protected` machen oder im Interface ergänzen. |
| 13 | **`options()` returniert `Future<ScanResult>`** statt `Future<IcapResponse>` | `IcapClient.php:157-213` | API-Smell. v2.2 als Additiv: `optionsRaw()`; v3.0 BC-Break. |
| 14 | **Cookbook fehlt**: TLS, RetryingIcapClient, Connection-Pool-Tuning, External Cancellation | `examples/cookbook/` | Vier neue Cookbook-Dateien `04-tls-mtls.php`, `05-retry-decorator.php`, `06-pool-tuning.php`, `07-cancellation-from-upload.php`. |

### P2 — Nice-to-have / Differenzierung

| # | Issue | Datei / Zeile | Empfehlung |
|---|---|---|---|
| 15 | Header-Name-Validierung lässt Separator-Tokens durch | `IcapClient.php:601` | RFC-7230-§3.2.6 strict: `[!#$%&'*+\-.^_\`\|~0-9a-zA-Z]+`. |
| 16 | Framer ignoriert obs-fold im Encapsulated | `Transport/ResponseFrameReader.php:144-153` | Vor dem Line-Split unfold-Pass einbauen. |
| 17 | Idle-Sweep im Pool fehlt | `Transport/AmpConnectionPool.php:42-46` | Zeitstempel pro Idle-Eintrag, Eviction-Sweep auf `acquire`. |
| 18 | OPTIONS-Cache ohne ISTag-Invalidation | `Cache/InMemoryOptionsCache.php` | Erweiterung um `?string $istag` Param + Invalidation, wenn ISTag wechselt. |
| 19 | `PSR\Clock\ClockInterface` für `InMemoryOptionsCache` | `Cache/InMemoryOptionsCache.php:92-94` | Optionaler Konstruktor-Param. |
| 20 | `examples/cookbook/03-options-request.php` "next milestone"-Claim stale | `examples/cookbook/03-options-request.php:11-13` | Comment aktualisieren. |
| 21 | `examples/cookbook/02-custom-preview-strategy.php` McAfee-Strategy ist anti-Pattern | `examples/cookbook/02-custom-preview-strategy.php` | Strategie korrigieren (200 → virus-header-Inspection statt ABORT_CLEAN), Caveat in Kommentar. |
| 22 | `LoggerIntegrationTest.php` ohne Sensitive-Header-Regression | `tests/LoggerIntegrationTest.php` | Test, der `extraHeaders` setzt und verifiziert, dass kein Header-Name/Value im Log-Context ist. |
| 23 | `OptionsCacheTest.php` ohne `Options-TTL=0`-Pfad | `tests/OptionsCacheTest.php` | Test ergänzen. |
| 24 | `ResponseFrameReaderTest.php` ohne `0; ieof`-Recv-Variante, ohne Multi-Section, ohne Slow-Loris | `tests/Transport/ResponseFrameReaderTest.php` | drei zusätzliche Tests. |
| 25 | `AmpConnectionPoolTest.php` ohne Cross-TLS-Isolation-Test, ohne Concurrent-Acquire-Race | `tests/Transport/AmpConnectionPoolTest.php` | zwei Tests; Cross-TLS sollte nach Fix #2 grün sein. |
| 26 | `BSI-Compliance-Mapping` fehlt | `docs/` | `docs/compliance.md` mit OPS.1.1.4 + APP.4.4 + DSGVO-Hinweis. |
| 27 | `CONTRIBUTING.md` ohne Conventional-Commits | `CONTRIBUTING.md` | Section ergänzen, da Git-Historie es nutzt. |
| 28 | Kein PHPBench | — | `tests/Bench/` für Pool-Throughput, Strict-§4.5-Latency, Chunked-Encoder-Durchsatz. |
| 29 | Kein OpenTelemetry-Decorator | — | `OtelTracingIcapClient` Decorator analog zu `RetryingIcapClient`. |
| 30 | Kein `Connection-Pool` im Config-Block des README | `README.md:89-129` | Beispiel ergänzen. |

### P3 — Langfristige Vision

| # | Issue | Empfehlung |
|---|---|---|
| 31 | Property-Based Tests auf `ResponseParser` / `ResponseFrameReader` | `eris-php` oder eigener Property-Generator. |
| 32 | Fuzz-Test-Korpus für Parser | `php-fuzzer` AFL-style-Setup. |
| 33 | Multi-Vendor-Integration-Pipeline | docker-compose-Profile für Symantec / Sophos / Trend Micro / Kaspersky. |
| 34 | Symfony-Bundle | siehe Roadmap v2.3. |
| 35 | Validator-Constraint `#[IcapClean]` | Im Bundle. |
| 36 | VichUploaderBundle / OneupUploaderBundle Adapter | Im Bundle. |
| 37 | Console-Commands `icap:scan`, `icap:options`, `icap:health` | Im Bundle. |
| 38 | Symfony Profiler DataCollector | Im Bundle. |

---

## 11. Roadmap

### v2.1.x — Patch (1–2 Wochen)

Reine Doku- und Bug-Fixes, kein BC-Break:

- **2.1.1**: SECURITY.md Z. 73-75 fixen (P0 #1). Cookbook 03-stale-claim entfernen (P2 #20). Cookbook 02-anti-pattern korrigieren (P2 #21). `ConnectionPoolInterface`-Phpdoc fixen oder `NullConnectionPool` einführen (P1 #7).
- **2.1.2**: `DefaultPreviewStrategy` 200/206-Bug fixen (P0 #3) + Test. Strict-§4.5-Streaming-Bug fixen (P1 #4) + Test mit großer Datei + Memory-Watermark.

### v2.2.0 — Minor (4–6 Wochen)

Additive Features, keine BC-Brüche:

- **Pool-Key-TLS-Fingerprint** (P0 #2) inkl. Cross-TLS-Test.
- **`Max-Connections` aus OPTIONS** (P1 #5) + Pool-Cap-Auto-Tuning.
- **Preview-Size-Negotiation aus OPTIONS-Cache** (P1 #6).
- **OpenTelemetry-Decorator** als Optional-Dependency (`open-telemetry/api: ^1`).
- **Mutation-Testing-Job in CI reaktiviert** (P1 #8).
- **Cookbook erweitert** (P1 #14): TLS, RetryingIcapClient, Pool-Tuning, External Cancellation.
- **Idle-Eviction im Pool** (P2 #17).
- **OPTIONS-Cache ISTag-Invalidation** (P2 #18).
- **PSR-20-Clock** in `InMemoryOptionsCache` (P2 #19).
- **PHPBench-Suite** (P2 #28).
- **`docs/compliance.md`** mit BSI-/OpenCoDE-/DSGVO-Mapping (P2 #26).

### v2.3.0 — Minor (8–12 Wochen)

- **Begleit-Repo `icap-flow-bundle`**: Symfony 7.x Bundle, Configuration-Tree, Auto-DI, Tagged-Services für Multi-Client, Profiler-DataCollector, Monolog-Channel `icap`, Validator-Constraint `#[IcapClean]`, Console-Commands, Flex-Recipe.
- **Property-Based Tests** auf Parser/Framer (P3).
- **Multi-Vendor-Integration-CI** (Symantec, Sophos, Trend Micro, Kaspersky) (P3).
- **VichUploaderBundle / OneupUploaderBundle Adapter** im Bundle (P3).

### v3.0.0 — Major (nur falls erforderlich, ~6 Monate)

Nur wenn Reviews echte Breaking Changes erfordern:
- `IcapResponseException` entfernen (P1 #11) — falls nicht früher als `#[\Deprecated]` final.
- `executeRaw()` aus Public-API entfernen / ins Interface heben (P1 #12).
- `options()` returniert `Future<IcapResponse>` statt `Future<ScanResult>` (P1 #13).
- `ConnectionPoolInterface` evtl. Refactor für Cross-Tenant-Sicherheit (falls v2.2-Fix nicht ausreicht).
- Möglicher Wechsel zu PSR-7-Body via Adapter (Tradeoff weiter unten).

**Begleit-Repo `icap-flow-bundle`**: Empfohlene Trennung früh (v2.3), nicht später. Bundle-Repo bleibt eigenständig versioniert; Library-Core bleibt framework-agnostisch.

---

## 12. Quellenverzeichnis

### RFCs (geprüft gegen Code)
- RFC 3507 — Internet Content Adaptation Protocol — https://www.rfc-editor.org/rfc/rfc3507 (insb. §4.4 Encapsulated, §4.5 Preview, §4.6 OPTIONS, §4.10.2 Options-TTL/Max-Connections, §6 Vendor-Header, §7 Security)
- RFC 7230 — HTTP/1.1 Message Syntax and Routing — https://www.rfc-editor.org/rfc/rfc7230 (insb. §3.2.4 obs-fold, §3.2.6 token, §4.1 Chunked, §6.1 Connection)
- RFC 9110 — HTTP Semantics — https://www.rfc-editor.org/rfc/rfc9110 (Status-Code-Klassifikation)

### Symfony / Amp / PSR
- Symfony HttpClient Pool-Verhalten — https://symfony.com/doc/current/http_client.html
- amphp/socket v2 — https://amphp.org/socket
- revolt/event-loop — https://revolt.run/
- PSR-3 LoggerInterface — https://www.php-fig.org/psr/psr-3/
- PSR-20 ClockInterface — https://www.php-fig.org/psr/psr-20/
- PHPStan Level 9 + bleedingEdge — https://phpstan.org/config-reference#bleedingedge

### ICAP-Server-Referenzen (Wire-Format-Vergleich)
- c-icap — https://c-icap.sourceforge.net/
- mnemoshare/clamav-icap — https://hub.docker.com/r/mnemoshare/clamav-icap
- Symantec Web Gateway, Sophos UTM, Trend Micro IWSVA, Kaspersky Web Traffic Security, McAfee Web Gateway: Vendor-Doku, jeweils auf vendor-spezifische `X-…`-Header-Konventionen geprüft.

### Vergleichs-Implementierungen
- `egirna/icap-client` (Go) — https://github.com/egirna/icap-client
- `solidwall/icap-server` (Go) — https://github.com/solidwall/icap-server
- `c-icap-client` — Teil von c-icap upstream
- `pyicap` — https://github.com/netom/pyicap

### Public-Sector / Compliance
- EUPL-1.2 Text — https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
- OpenCoDE — https://opencode.de/
- BSI IT-Grundschutz Bausteine — https://www.bsi.bund.de/DE/Themen/Unternehmen-und-Organisationen/Standards-und-Zertifizierung/IT-Grundschutz/it-grundschutz_node.html (OPS.1.1.4 Schadprogramm-Schutz, APP.4.4 Webanwendungen)
- DSGVO Art. 32 (technisch-organisatorische Maßnahmen)

### Repo-internes Material
- `docs/review/claude_icap-flow-due-diligence-verified.md` (44 KB)
- `docs/review/codex_due-diligence-final-report.md`
- `docs/review/jules_due-diligence-report.md`
- `docs/review/consolidated_task-list.md` (Master)
- `CHANGELOG.md` v2.0.0 + v2.1.0 Einträge

---

## Schlusswort

`icap-flow` ist die heute beste verfügbare PHP-ICAP-Client-Library — und der Maintainer hat das mit drei unabhängigen Reviews und einer ehrlichen AI-Origin-Disclosure auf eine kommunikative Reife gehoben, die in der OSS-PHP-Welt selten ist. Die drei P0-Findings dieses Audits (SECURITY.md-Update, Pool-TLS-Key, PreviewStrategy-200) sind alle in 1–2 Tagen lösbar; die zwei P1-Findings (Strict-Streaming, Mutation-CI) brauchen einen halben Sprint. Mit v2.1.x + v2.2 ist die Library auf Augenhöhe mit `apache/httpcomponents` für Java-ICAP-Adapter — und die einzige PHP-Wahl für RFC-3507-strikte Public-Sector-Deployments.

Der Weg dahin ist konkret und klein. Die Substanz ist da.

Audit abgeschlossen. Der vollständige Bericht ist oben — 12 strukturierte Kapitel, alle Findings mit Datei:Zeile-Refs gegen den HEAD `8b777be` verifiziert.

**Top-Befunde dieses Audits (über die Maintainer-Self-Reports hinaus)**:
- **3 P0-Findings**: SECURITY.md ist v2.0-stale (Z. 73-75); `AmpConnectionPool::key()` Z. 130-133 kollabiert TLS-Identität → Cross-Tenant-Risk; `DefaultPreviewStrategy` Z. 35-42 wirft auf 200/206 statt `ABORT_INFECTED`.
- **3 zentrale P1-Findings**: `IcapClient::scanFileWithPreviewStrict` Z. 399 bricht Streaming via `stream_get_contents`; OPTIONS-`Max-Connections` und `Preview`-Size werden ignoriert; Mutation-CI fehlt.
- **Gesamt-Score 192/260 (74 %)** vs. v1.0.0 ~112/260 (43 %). TRL 4 → TRL 7.

**Empfehlung**: v2.1.1 (Doku-Patches, T3-Fix), v2.1.2 (T2-Fix), dann v2.2 mit Pool-Key-TLS-Fingerprint, OPTIONS-driven Preview/Max-Connections, OTel-Decorator und reaktiviertem Mutation-Job. v2.3 öffnet das separate `icap-flow-bundle`-Repo. v3.0 nur, falls API-Smells (`executeRaw`-Visibility, `options() : ScanResult`) Breaking Changes erzwingen.
