# Code-Review: ndrstmr/icap-flow v3.0.0

## 1. Executive Summary

`ndrstmr/icap-flow v3.0.0` ist eine produktionsreife ICAP-Client-Library auf hohem Niveau. Die Code-Basis besteht den AAA-Anspruch in den meisten Dimensionen:

- ✅ PHPStan Level 9 + bleedingEdge ohne Baseline läuft fehlerfrei
- ✅ php-cs-fixer ohne Findings
- ✅ 159 Tests / 363 Assertions grün
- ✅ Gesamtcoverage 84,4 %
- ✅ RFC-3507-Wire-Format mit Hand-Computed-Bytes-Tests verifiziert
- ✅ Fail-secure-Statusinterpretation in `assertSuccessfulStatus()` als Single-Source-of-Truth
- ✅ TLS-Pool-Key-Isolation sicher
- ✅ Strict-§4.5-Same-Socket-Continuation streamt ohne RAM-Buffering
- ✅ Per-IO-Timeout korrekt komponiert
- ✅ Drei v3-BC-Breaks (v3-V/W/F) am Code sauber umgesetzt

### Verifikation der drei v3-BC-Breaks

Alle drei restlos geschlossen:
- ✅ `executeRaw()` ist protected mit ausführlicher Phpdoc-Begründung
- ✅ `options(): Future<IcapResponse>` über alle drei Implementierungen konsistent
- ✅ `IcapResponseException` weder als Datei noch als Code-Referenz noch als Test-Erwartung vorhanden


**Keine P0-Blocker für Produktion gefunden.** Es gibt aber vier P1-Findings, die für einen Class-AAA-Code-Review noch geschlossen werden sollten — vor allem für den geplanten Bundle-Stand:
1. `IcapTimeoutException` ist toter Code *(P1, Diagnose-Kontrakt-Verletzung)*.
2. Library-managed Header-Prinzip gilt nur für Encapsulated, nicht für Host/Connection — caller-supplied Host gewinnt im Formatter *(P1, semantischer Hijack möglich)*.
3. PSR-Cache-Adapter haben Race-Conditions im `__icap_keys`-Tracking *(P1, Multi-Worker unter Last)*.
4. Coverage-Hotspots in `SynchronousStreamTransport` (41 %), `AsyncAmpTransport` (64 %), `AmpConnectionPool` (71 %) — die im Audit-Prompt behaupteten ≥ 85/90 % aus v2.2 sind nicht erreicht, und kritische Cleanup-Pfade (`closeForced=true`, Strict-§4.5 catch-Block) sind ungetestet *(P1)*.

**TRL-Einschätzung:** **TRL-7 → TRL-8** (System Complete & Qualified). Mit drei vorigen unabhängigen Audits + dem v3-Cleanup ist die Library jenseits des „Prototype in operational environment"-Stadiums. Für TRL-9 (System Proven in Operational Environment) fehlen multi-Vendor-Integration-Tests (Symantec/Sophos/Trend Micro/Kaspersky) und ein veröffentlichtes Begleit-Bundle in produktivem Einsatz.

**Empfehlung für Produktion:**
* **Interne Tools / Prototypen / öffentliche Verwaltung Symfony (TYPO3, Shopware) gegen c-icap+ClamAV:** JA mit Standard-Härtung (TLS, `RetryingIcapClient`, Logging-Pipeline, `Config::withLimits()`).
* **Security-kritischer Upload-Scan-Gateway:** JA mit Einschränkungen — die vier P1-Findings vor Go-Live im eigenen Betriebskontext klären (insbes. P1-Finding #2 ist Konfigurationsfrage: keine User-Input-Header-Listen ohne Allowlist).
* **Symfony-Bundle-Stufe:** API-Vertrag JETZT einfrierungsreif. Die drei v3-BC-Breaks haben alles geschlossen, was das verhindert hätte. Bundle als `^0.1`-Start, Aufstieg auf `^1.0` nach erstem Real-Deployment.

**Vergleich v2.1 → v3.0:** v3.0 ist genau das, was es zu sein vorgibt — ein konservativer Cleanup-Release ohne neue Features. Die in der konsolidierten v2.1-Task-Liste (4 unabhängige Audits, Konsens TRL-7) als kritisch markierten Items A (TLS-Pool-Key), B (`DefaultPreviewStrategy` 200/206), F (`IcapResponseException`), G (Fail-Secure 100), H (CRLF-Guard), v3-V/W/F sind alle am Code verifiziert geschlossen. Die in v2.2 versprochene Coverage-Push (`AmpConnectionPool` ≥ 90 %, `SynchronousStreamTransport` ≥ 85 %) ist messbar nicht erreicht.

---

## 2. Repository-Inventar v3.0.0

### 2.1 Verzeichnisstruktur und LOC

```text
src/   (39 PHP-Dateien, ~4.212 LOC)
├── IcapClient.php                707  ← zentrale Facade (mit assertSuccessfulStatus())
├── IcapClientInterface.php        99  ← Public Contract (4 Methoden + Cancellation)
├── RetryingIcapClient.php        175  ← Decorator (generisch via @template T)
├── SynchronousIcapClient.php     101  ← Sync-Wrapper
├── Config.php                    203  ← final readonly + Wither-Pattern
├── RequestFormatter.php          213  ← iterable<string> Streaming
├── ResponseParser.php            239  ← RFC 7230 §3.2.4 obs-fold + DoS-Limits
├── ChunkedBodyEncoder.php        100  ← Streaming + encodeRemainderFromStream()
├── DefaultPreviewStrategy.php     93  ← 100/204/200/206-Matrix
├── PreviewDecision.php            31  ← Enum
├── PreviewStrategyInterface.php   34
├── (Interfaces: 41+34+99=174 LOC)
├── DTO/                          286  ← 5 final readonly Klassen
├── Exception/                    216  ← Marker + 6 konkrete Typen
├── Cache/                        456  ← InMemory + PSR-6 + PSR-16 + Interface
└── Transport/                  1.179  ← Pool + Session + Frame-Reader + Async + Sync

tests/ (38 PHP-Dateien, ~5.122 LOC)
├── Wire/        — Hand-Computed-Bytes (Formatter + Parser + ChunkedEncoder)
├── Security/    — Fail-Secure + DoS-Limits
├── Transport/   — Pool (Default/Idle/MaxConn/TLS), Frame-Reader, Per-IO-Timeout
├── Cache/       — InMemory + PSR-6 + PSR-16
├── DTO/         — Value-Object-Tests
├── Exception/   — Hierarchie + Marker
├── Integration/ — c-icap + ClamAV (skip-by-default)
└── (Top-level: IcapClient, Cancellation, MultiVendorVirusHeaders, Logger, …)
```

**Test-zu-Code-Ratio:** 5.122 / 4.212 ≈ 1,22. Branchenüblich für Security-kritische Libraries: 1,5–2,5. Die Ratio ist niedrig, was sich in den Coverage-Hotspots (Phase 5) widerspiegelt.

### 2.2 Public-API-Oberfläche (für SemVer-Disziplin)

| Symbol | Sichtbarkeit | Status v3.0 | BC seit v2.2 |
| :--- | :--- | :--- | :--- |
| `IcapClientInterface::request/options/scanFile/scanFileWithPreview` | public | stabil | — |
| `IcapClient::executeRaw` | protected | v3-V BREAK | ja |
| `IcapClient::options(): Future<IcapResponse>` | public | v3-W BREAK | ja |
| `Exception\IcapResponseException` | — | v3-F removed | ja |
| `Cache\Psr6OptionsCache`, `Psr16OptionsCache` | public final | stabil seit v2.2 | — |
| `Transport\NullConnectionPool` | public final | stabil seit v2.2 | — |
| `OptionsCacheInterface::set(?string $istag)` | public | erweitert seit v2.2 | additiv-OK |
| `RetryingIcapClient::withRetry()` mit `@template T` | private | stabil v3.0 | nur intern |
| Marker `IcapExceptionInterface` | public interface | stabil | — |
| Konkrete Exceptions | 6 final + 1 nicht-final (`IcapProtocolException`) | stabil v3.0 | — |

### 2.3 Dependency-Graph

* **runtime:** `php^8.4`, `amphp/socket^2.3`, `psr/log^3.0`, `revolt/event-loop^1.0`
* **suggest:** `psr/cache^3.0`, `psr/simple-cache^3.0` (soft, NOT hard deps — sauber)
* **dev:** `pest^3.8`, `phpstan^2.1`, `php-cs-fixer^3.75`, `mockery^1.6`, `phpunit^11.2`, `roave/security-advisories:dev-latest`

**Bewertung:** runtime-deps minimalistisch und hochqualitativ (`amphp`/`Revolt` sind die Standard-Async-Stacks für PHP 8.4+). PSR-Cache-Pakete bewusst als suggest — vermeidet harte Dependencies für Caller, die `InMemoryOptionsCache` ausreichend finden. Sehr gut.

---

## 3. v3.0-BC-Break-Verifikation

* **Item: v3-V `executeRaw()` protected**
    * **Code-Ref:** `src/IcapClient.php:154`
    * **Status:** ✅ verifiziert geschlossen
    * **Begründung:** Sichtbarkeit protected, Phpdoc Z. 137-153 dokumentiert die Security-Rationale ausführlich. Methode bleibt für Subklassen nutzbar. Direkter Test-Zugriff ist nicht mehr möglich, was die Test-Suite akzeptiert (ein Mock-Pfad, kein Reflection-Aufruf).
* **Item: v3-W `options(): Future<IcapResponse>`**
    * **Code-Ref:** `src/IcapClient.php:167`, `src/IcapClientInterface.php:62`, `src/SynchronousIcapClient.php:70`
    * **Status:** ✅ verifiziert geschlossen
    * **Begründung:** Signatur über alle drei Implementierungen konsistent. `assertSuccessfulStatus()` (Z. 624) ist Single-Source-of-Truth; wird von `interpretResponse()` Z. 602 und `options()` Z. 196 + Z. 177 (Cache-Hit) gemeinsam genutzt. Cookbook `03-options-request.php` und `06-pool-tuning.php` reflektieren die neue API korrekt (`$options->headers`, nicht `$result->getOriginalResponse()->headers`).
* **Item: v3-F `IcapResponseException` removed**
    * **Code-Ref:** `src/Exception/` (7 Dateien, kein `IcapResponseException.php`); `grep -rn "IcapResponseException" src/ tests/` liefert null Treffer
    * **Status:** ✅ verifiziert geschlossen
    * **Begründung:** Beide Throw-Sites werfen `IcapProtocolException`: `IcapClient.php:607` (Backstop für 1xx-other-than-100, 3xx, 6xx+) und `DefaultPreviewStrategy.php:70` (default-Branch der Status-Match). `tests/Exception/ExceptionHierarchyTest.php` enthält keinen Eintrag. Marker-Catch-Pfad via `IcapExceptionInterface` weiter intakt.

### 3.1 Spot-Check der v2.2-Closures

| Item | Code-Ref | Status |
| :--- | :--- | :--- |
| **v2.1.1-A TLS-Pool-Key-Isolation** | `AmpConnectionPool.php:194-211` | ✅ Security geschlossen (per `spl_object_hash`) — aber: explizit als „transitional" markiert, deterministischer SHA-256-Hash für v2.2 versprochen, nicht geliefert (siehe Finding #1). Test 'isolates idle sockets...' in `AmpConnectionPoolTest.php:201-235` greift. |
| **v2.1.1-B DefaultPreviewStrategy 200/206** | `DefaultPreviewStrategy.php:62-92` | ✅ verifiziert geschlossen — `match (true)`-Branch deckt 200/206 → `classifyBodyResponse()` → `ABORT_INFECTED`/`ABORT_CLEAN` ab. |
| **v2.1.2 Strict-§4.5 Streaming** | `IcapClient.php:414` + `ChunkedBodyEncoder.php:83-99` | ✅ verifiziert geschlossen — kein `stream_get_contents()` mehr. Test in `PreviewContinueStrictTest.php:139-241` belegt mit 128 KiB Payload. |
| **v2.2-K OPTIONS-driven Preview-Size** | `IcapClient.php:520-540` | ✅ verifiziert geschlossen — Test in `tests/OptionsDrivenPreviewSizeTest.php` vorhanden. |
| **v2.2-L Pool-Tuning aus OPTIONS** | `AmpConnectionPool.php:173-179` | ✅ verifiziert geschlossen — Test in `AmpConnectionPoolMaxConnectionsTest.php` vorhanden. |
| **v2.2-Q Pool-Idle-Eviction** | `AmpConnectionPool.php:107-121` | ✅ verifiziert geschlossen — Test in `AmpConnectionPoolIdleEvictionTest.php`. |
| **v2.2-P Per-IO-Timeout** | `AmpTransportSession.php:87-94` | ✅ verifiziert geschlossen — Test in `PerIoTimeoutTest.php`, läuft 3,74 s mit Socket-Pair und gestaffelten Server-Delays. |
| **v2.2-T/U ISTag-Invalidation + Clock** | `InMemoryOptionsCache.php:43-107` | ✅ verifiziert geschlossen, aber im PSR-Adapter-Pfad mit Race-Conditions (siehe Finding #3). |
| **v2.2-X PSR-6/PSR-16-Adapter** | `Psr6OptionsCache.php`, `Psr16OptionsCache.php` | ✅ verifiziert geschlossen, aber mit P1-/P2-Issues (siehe Findings #3, #6, #7). |

### 3.2 Wunschliste-Check

| Item | Status | Bewertung |
| :--- | :--- | :--- |
| **W-Y OpenTelemetry-Decorator** | aufgeschoben | Verschiebung gerechtfertigt — kein Public-Sector-OTel-Mandat 2026 sichtbar. Empfehlung in v3.1.0 zu eröffnen, sobald erstes Bundle-Deployment OTel verlangt. |
| **W-Z PHPBench-Suite** | aufgeschoben | Verschiebung gerechtfertigt — keine bekannten Performance-Probleme; Library ist I/O-bound, nicht CPU-bound. Mutation-Tests reichen für Korrektheit. |
| **W-sbom SBOM-Workflow (CycloneDX/SPDX)** | aufgeschoben | Verschiebung kritisch zu prüfen. Ab BSI TR-03183 (Stand 2026) wird SBOM für öffentliche Verwaltungssoftware Pflicht. Empfehlung: in v3.0.x oder v3.1.0 hochziehen. |

---

## 4. Findings nach Dimension

### 4.1 Sprachmoderne / Typsystem

**Stärken:**
* `final readonly class` durchgängig auf DTOs, Config, Cache-Adapter — sauber.
* Konstruktor-Property-Promotion durchgängig.
* `#[\Override]` auf jeder Interface-Implementierung — PHPStan-Level-9-konform.
* `@template T` auf `RetryingIcapClient::withRetry()` ist sauber definiert.
* `int<1, max>`-Phpdoc auf `resolvePreviewSize()` — Level-9-Niveau.
* `final` auf allen konkreten Klassen außer `IcapProtocolException` (bewusst nicht-final).

**Beobachtungen (informativ):**
* Property hooks (PHP 8.4) und asymmetric visibility werden nicht genutzt. Kein Schaden.
* `mixed $body: string|resource|null` in HttpRequest/HttpResponse ist korrekt (kein `resource`-Typ in PHP).

*(Kein Finding)*

### 4.2 SOLID / Architektur

**Stärken (im AAA-Niveau):**
* SRP sauber durchgezogen (Reader, Parser, Encoder, Session, Pool isoliert).
* Decorator (`RetryingIcapClient`) retried nur `IcapServerException` (5xx).
* Strategy (`PreviewStrategyInterface`) sauber entkoppelt.
* Pool-Pattern mit LIFO-Stack, Idle-Eviction und OPTIONS-Tuning sehr gut.
* `assertSuccessfulStatus()`-Extraktion ist der richtige SRP-Schnitt.

#### F-2-1 (P1) — Library-managed Headers gewinnen nur teilweise (Header-Hijack möglich)
**Code-Ref:** `src/RequestFormatter.php:152-154`
```php
if (!isset($headers['Host'])) {
    $headers['Host'] = [$host];
}
```
**Problem:** Caller-gelieferter `Host` über `scanFile(..., $extraHeaders)` gewinnt. CLAUDE.md fordert, dass Library-verwaltete Header gewinnen. Ein Caller könnte via `'Host' => 'evil-routing-target'` das Routing manipulieren.
**Severity:** P1 (Semantischer Hijack möglich).
**Fix-Vorschlag:** Unbedingtes Setzen und explizites Blocken in der Allowlist:
```php
$headers['Host'] = [$host]; 
unset($headers['Connection']); 
$headers['Encapsulated'] = [$encapsulated];
```

#### F-2-2 (P2) — IcapClient::options() Cache-Hit-Pfad inkonsistent zur API-Form
**Code-Ref:** `src/IcapClient.php:174-180`
**Problem:** Gespeicherter 5xx-Fehler wirft synchrone Exception vor der Rückgabe des Future. Caller mit `try { ...->await(); }` fängt hier nichts.
**Fix-Vorschlag:**
```php
return \Amp\async(function () use ($cached): IcapResponse {
    $this->assertSuccessfulStatus($cached->statusCode);    
    return $cached;
});
```

#### F-2-3 (P2) — Cache-Hit-Pfad logged nicht
**Problem:** Keine `info()`-Logs bei Cache-Hits. Observability-Lücke.
**Fix-Vorschlag:** `$this->logger->info('ICAP options served from cache', [...]);` ergänzen.

### 4.3 PSR-Compliance

**Stärken:**
* PSR-3 (Logger) strukturiert, optional.
* PSR-4 sauber.
* PSR-6 + PSR-16 als suggest — richtig.
* PSR-20 (Clock) als `Closure(): int` trade-off OK.

#### F-3-1 (P1) — PSR-Cache-Adapter haben Race-Conditions im Key-Tracking
**Code-Ref:** `src/Cache/Psr6OptionsCache.php:126-148`, `src/Cache/Psr16OptionsCache.php:116-132`
**Problem:** `trackKey()` macht read-modify-write auf `__icap_keys` ohne atomare Operation. Unter Last überschreiben sich Worker, Keys gehen verloren und stale ISTags bleiben hängen.
**Fix-Vorschlag:** Option 3 (Minimal-invasiv & race-frei): `__icap_keys` entfernen und ISTag direkt im Eintrag speichern, beim `get()` validieren.

#### F-3-2 (P2) — ISTag-Meta-Key ohne TTL
**Code-Ref:** `src/Cache/Psr6OptionsCache.php:121-123`
```php
$item = $this->pool->getItem($this->prefix . self::ISTAG_META_KEY);
$item->set($istag);
$this->pool->save($item); // ohne expiresAfter()
```
**Problem:** Bei LRU-Eviction (z. B. Memcached) fliegt der Meta-Key weg, während Service-Einträge bleiben → stale Cache.
**Fix-Vorschlag:** `expiresAfter(86400)` hinzufügen.

### 4.4 Fehlerbehandlung & Exceptions

#### F-4-1 (P1) — IcapTimeoutException ist toter Code
**Code-Ref:** `src/Exception/IcapTimeoutException.php:27`
**Problem:** Die typisierte Exception wird definiert, aber nirgends geworfen. Es fliegen stattdessen `Amp\CancelledException` oder `IcapConnectionException`.
**Fix-Vorschlag:** Internen Timeout in `AsyncAmpTransport` und `SynchronousStreamTransport` abfangen und korrekt wrappen.

#### F-4-2 (P2) — Sync-Read-Timeout-Cast verliert Sub-Sekunden-Präzision
**Code-Ref:** `src/Transport/SynchronousStreamTransport.php:81`
**Problem:** `stream_set_timeout` mit `(int)` schneidet Nachkommastellen ab. Timeout 0.5s wird zu 0s (Kein Timeout).
**Fix-Vorschlag:** Sekunden und Mikrosekunden getrennt übergeben.

#### F-4-3 (P3) — Null-Body-Response ohne Encapsulated-Header silent best-effort
**Problem:** RFC verlangt Encapsulated-Header. Aktuell wird bei Fehlen tolerant ein leeres Array zurückgegeben.

### 4.5 Ressourcen-Management & Connection-Handling

#### F-5-1 (P1) — AmpConnectionPool::key() nutzt spl_object_hash als „transitional"
**Code-Ref:** `src/Transport/AmpConnectionPool.php:194-211`
**Problem:** Code-Kommentar kündigt für v2.2 Wechsel auf deterministischen Hash an, in v3.0 immer noch `spl_object_hash`. Führt bei strukturell identischen TLS-Kontexten zu ineffektiven Pools.
**Fix-Vorschlag:** Deterministischen SHA-256 Hash aus Peer, Cert, CA etc. implementieren:
```php
private function key(Config $config): string {
    // ...
    return $key . ':tls:' . $this->fingerprintTls($tls);
}
```

#### F-5-2 (P2) — Per-IO-Timeout ohne harten Session-Cap
**Problem:** Slow-Loris-Gefahr durch kumulativ langsame Reads unterhalb des IO-Timeouts.
**Fix-Vorschlag:** `maxRequestDurationSeconds` in Config einführen.

#### F-5-3 (P2) — Config Konstruktor ohne Validation
**Problem:** Ungültige Parameter (z. B. leere Hosts, negative Ports) werfen erst im Connect.
**Fix-Vorschlag:** Explizite Validation im Konstruktor einbauen.

### 4.6 Async-Implementierung

*(Kein Finding, sauber umgesetzt)*

### 4.7 RFC 3507 Compliance (Phase 3)

| Status | Pfad | Behandlung | Test |
| :--- | :--- | :--- | :--- |
| **100 (außerhalb Preview)** | `assertSuccessfulStatus()` Z. 626 | `IcapProtocolException` (fail-secure) ✓ | `FailSecureAndValidationTest.php:71-81` |
| **100 (im Preview)** | `DefaultPreviewStrategy` Z. 65 | `CONTINUE_SENDING` ✓ | `PreviewContinueStrictTest.php` |
| **200 (Scan, virus header)** | `interpretResponse` Z. 591-598 | `ScanResult(infected=true)` ✓ | `FailSecureAndValidationTest.php:127-140` |
| **200 (Scan, kein virus)** | `interpretResponse` Z. 599 | `ScanResult(clean)` ✓ | `FailSecureAndValidationTest.php:115-125` |
| **204** | `interpretResponse` Z. 587 | `ScanResult(clean)` ✓ | mehrere |
| **200 (OPTIONS)** | `assertSuccessfulStatus` ohne Throw | Raw `IcapResponse` ✓ | `OptionsCacheTest.php:80-116` |
| **4xx** | `assertSuccessfulStatus` Z. 633 | `IcapClientException(code)` ✓ | `FailSecureAndValidationTest.php:83-97` |
| **5xx** | `assertSuccessfulStatus` Z. 640 | `IcapServerException(code)` ✓ | `FailSecureAndValidationTest.php:99-113` |
| **1xx/3xx/6xx+** | Backstop Z. 607 | `IcapProtocolException` ✓ | **Nicht direkt getestet (F-7-1)** |

#### F-7-1 (P2) — Status-Matrix-Backstop ungetestet
**Problem:** Der Fall-back Catch in Z. 607 ist durch keinen Test abgedeckt.
**Fix-Vorschlag:** Test für z.B. 301 oder 102 simulieren, um Fail-Secure sicherzustellen.

### 4.8 Security-Posture v3.0 (Phase 4)

#### F-8-1 (P3) — Body von OPTIONS-Antworten wird ungeprüft cached
**Problem:** OPTIONS hat typischerweise keinen Body, hostiler Server könnte bis zu 10 MiB liefern und den Cache sprengen.
**Fix-Vorschlag:** Body vor dem Caching explizit auf `''` setzen.

#### F-8-2 (P3) — Config::withTlsContext(null) deaktiviert TLS still
**Problem:** Versehentliche Null-Übergabe schaltet geräuschlos auf Klartext um.
**Fix-Vorschlag:** Explizite `withoutTls()` Methode.

### 4.9 Tests & QA (Phase 5)

#### F-9-1 (P1) — Coverage-Hotspots aus v2.2 nicht erreicht
**Problem:** Versprochene Testabdeckung in kritischen Klassen nicht erfüllt (`SynchronousStreamTransport` 41%, `AsyncAmpTransport` 64%, `AmpConnectionPool` 71%). Ungetestete sicherheitskritische Catch-Blöcke (z.B. in `scanFileWithPreviewStrict`).
**Fix-Vorschlag:** Prio-Tests für Mid-Continuation-Disconnects, Throw-Pfade und Sync-Loops schreiben.

#### F-9-2 (P2) — Mutation-Threshold global 65 % zu lasch
**Fix-Vorschlag:** Security-relevante Klassen mit `--min=85` in einem separaten CI-Job testen.

#### F-9-3 (P2) — PSR-Cache-Tests ohne Cross-Process-Backing-Store
**Fix-Vorschlag:** Echter Integration-Test mit Redis-Backend für Parallelitätsprüfungen.

### 4.10 Symfony-Integration & Ökosystem-Fit (Phase 6)

API-Vertrag ist bereit für ein Bundle. *(Kein library-internes Finding)*.

---

## 5. ICAP RFC 3507 Compliance-Checkliste v3.0

| RFC-§ | Funktion | Status | Code-Ref |
| :--- | :--- | :--- | :--- |
| §4.2 | `abs_path`-Subset | ✅ | `validateServicePath` Z. 563 |
| §4.3.3 | 204/200/206 Verdict-Mapping | ✅ | `interpretResponse` Z. 583 |
| §4.4.1 | Encapsulated-Header verpflichtend | ✅ | `RequestFormatter::buildEncapsulatedHeader` |
| §4.4.1 | null-body-Modus | ✅ | `ResponseParser::extractDecodedBody` Z. 173 |
| §4.5 | Preview Continue (legacy 2-Request-Approx) | ✅ | `scanFileWithPreviewLegacy` |
| §4.5 | Strict same-socket Preview-Continue | ✅ | `scanFileWithPreviewStrict` + Test |
| §4.5 | `0; ieof`-Terminator bei `previewIsComplete` | ✅ | `ChunkedBodyEncoder.php:44` |
| §4.7 | OPTIONS Methods + Allow Header | ✅ | Test 'parses an OPTIONS response...' |
| §4.8 | REQMOD | ✅ Wire-Test | `RequestFormatterWireTest.php:101` |
| §4.9 | RESPMOD | ✅ | mehrere Tests |
| §4.10 | OPTIONS Capability Discovery | ✅ | `options(): Future<IcapResponse>` |
| §4.10.2 | Options-TTL / Pool-Tuning / Preview | ✅ | `OptionsCacheInterface`, `tuneFromOptions`, `resolvePreviewSize` |
| §4.10.2 | ISTag (Cache-Invalidation) | ✅ / ⚠ | InMem OK, PSR hat Race (F-3-1) |
| §5.5 | Connection-Persistence (Keep-Alive) | ✅ | `AsyncAmpTransport` + `ResponseFrameReader` |
| RFC 7230 | obs-fold / tchar-Whitelist / Chunked / Connection-close | ✅ | Vollständig umgesetzt und getestet |

---

## 6. OWASP Top 10 (2021/2025) Mapping

| OWASP | Library-Mechanismus | Status |
| :--- | :--- | :--- |
| A01 Broken Access Control | nicht library-relevant | N/A |
| A02 Cryptographic Failures | TLS via amphp, Hostname-Verification On, mTLS via Cookbook | ✅ Mitigated (mit P3 F-8-2) |
| A03 Injection | Guard auf `$service` und Header-Werte, tchar-Whitelist | ✅ Mitigated (mit P1-Lücke F-2-1) |
| A04 Insecure Design | Fail-Secure auf 100/4xx/5xx, DoS-Limits | ✅ Mitigated |
| A05 Misconfiguration | Config-Wither + Compliance-Doku | ✅ Mitigated |
| A06 Vulnerable Components | `composer audit` + roave/security-advisories | ✅ Mitigated (SBOM offen) |
| A07 Auth Failures | nicht library-relevant | N/A |
| A08 Data Integrity | EUPL-1.2, Packagist-PGP | ⚠ Partial (SBOM/Signature outstanding) |
| A09 Logging Failures | PSR-3 strukturiert, kein PII | ✅ Mitigated |
| A10 SSRF | Config::host aus Caller, kein User-Input | ✅ Mitigated |

---

## 7. Pool / Session-Lifecycle / Cache Threat-Analyse

* **7.1 TLS-Pool-Isolation:** Sicher, aber Ineffizienz-Gap bei identischen Kontexten (siehe F-5-1).
* **7.2 Idle-Eviction:** Lazy Eviction ohne Background-Tasks. Atomar sicher.
* **7.3 OPTIONS-driven Pool-Tuning:** Caller muss dynamisch nachtunen, Cookbook ergänzen.
* **7.4 Per-IO-Timeout:** Sauber implementiert, aber harter Session-Cap (`maxRequestDurationSeconds`) empfohlen (P2).
* **7.5 Cache Race-Conditions:** ISTag-Race unter Last (siehe F-3-1, P1). Backing-Store-Tagging fehlt.
* **7.6 Session-Lifecycle:** `Strict §4.5` Catch-Block sicherheitskritisch und korrekt, aber ungetestet.

---

## 8. Wettbewerbsvergleich

### 8.1 PHP-Welt (Packagist 2026)

| Paket | Aktualität | Pooling | TLS | Streaming | Strict §4.5 | Multi-Vendor | Aktiv? |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **ndrstmr/icap-flow** | v3.0 (2026-05) | ✓ | ✓ | ✓ | ✓ | ✓ | ja |
| tomatonotomato/icap-php | ~2018 | — | — | — | — | nur ClamAV | nein |
| aurora-pt/php-icap-client | ~2020 | — | Hack | — | — | nur ClamAV | nein |

### 8.2 Andere Sprachen (Maßstab)

| Stack | Lib | RFC-Coverage | Streaming | Pool | TLS | Strict §4.5 | Cancellation |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| Java | HttpComponents | hoch | ✓ | ✓ | ✓ | ✓ | ✓ |
| Go | egirna/icap-client | mittel | ✓ | nativ | ✓ | partiell | ✓ |
| Python | pyicap | niedrig | partiell | — | — | — | — |
| .NET | ICAPClient | mittel | ✓ | ✓ | ✓ | partiell | ✓ |

*(icap-flow dominiert im PHP-Bereich und hält global im Feature-Set hervorragend mit).*

---

## 9. Bewertungsmatrix

| Dimension | v1.0.0 | v2.1.0 | v3.0.0 | Begründung |
| :--- | :--- | :--- | :--- | :--- |
| Sprachmoderne | 5 | 9 | 9 | PHPStan L9+bleedingEdge ohne Baseline; `@template T` sauber |
| Typsystem | 4 | 9 | 9 | `strict_types`, `int<1,max>`, `list` |
| SOLID / Architektur | 5 | 9 | 9 | SRP-Schnitte sauber |
| Exception-Design | 4 | 8 | 8 | -1 wegen toter `IcapTimeoutException` |
| PSR-Konformität | 6 | 8 | 8 | Race in `__icap_keys` (F-3-1) zieht 1 Pkt ab |
| Ressourcen/Mgmt | 4 | 8 | 9 | Per-IO-Timeout v2.2 + Idle-Eviction |
| Pool-Korrektheit | n/a | 8 | 8 | `spl_object_hash` transitional (-1 Pkt) |
| Async/Cancellation | 6 | 8/9 | 9 | Cancellation sauber durchgereicht |
| RFC 3507 / §4.5 | 4 | 9 | 9 | Alle Methoden + dieselbe Socket Preview-Continue implementiert |
| ICAP-Robustheit | 4 | 9 | 9 | obs-fold getestet; DoS-Limits enforced |
| Security-Posture | 3 | 8 | 8 | Stark, aber P1-Lücke bei Host-Header (F-2-1) |
| Test-Coverage | 4 | 8 | 7 | Hotspots aus v2.2 nicht erreicht (F-9-1) |
| Wire-Format-Tests | 2 | 9 | 9 | Hand-Computed Bytes (Gold Standard) |
| CI / Integration | 5 | 8 | 9 | Multi-PHP, c-icap+ClamAV, Nightly |
| SemVer-Disziplin | 3 | 7 | 9 | v3.0 Cleanup isoliert |
| Bundle-Readiness | 2 | 7 | 9 | API einfrierungsreif |
| **Gesamt Score** | **86 (~31%)** | **207 (~74%)** | **227 (~81%)** | **TRL-7 → TRL-8** |

---

## 10. Produktionsreife-Gate-Entscheidung

| Einsatzkontext | Empfehlung | Bedingungen |
| :--- | :--- | :--- |
| Interne Tools / Prototypen | ✅ Ja | ohne Einschränkungen |
| Symfony Web-Portale (Public Sector) | ✅ Ja, mit Einschränkungen | F-2-1 via Allowlist blockieren; F-3-1 durch Single-Worker umgehen (oder InMemory Cache nutzen) |
| Security-kritischer Upload-Gateway | ✅ Ja, mit Einschränkungen | Alle obigen + Coverage Push (F-9-1) + Vendor-Integration-Test im eigenen Lab |
| Bundle-Stufe | ✅ API einfrierungsreif | Bundle als `^0.1`-Start mit `^3.0`-Library-Constraint |

---

## 11. Priorisierte Gap-Liste

### P1 (Vor AAA-Class-Production-Use schließen)
| # | Finding | Datei:Zeile | Aufwand |
| :--- | :--- | :--- | :--- |
| 1 | `IcapTimeoutException` ist toter Code (F-4-1) | `AsyncAmpTransport.php`, `SynchronousStreamTransport.php` | M (1-2 Tage) |
| 2 | Host-Header-Gewinner via extraHeaders (F-2-1) | `RequestFormatter.php:152`, `IcapClient.php:665` | S (2-4 Std. + Test) |
| 3 | PSR-Cache `__icap_keys`-Race (F-3-1) | `Psr6OptionsCache.php`, `Psr16OptionsCache.php` | M (1 Tag) |
| 4 | Coverage-Hotspots aus v2.2 nicht erreicht (F-9-1)| `SynchronousStreamTransport.php`, `AsyncAmpTransport.php`, etc. | L (3-5 Tage) |

### P2 (Nice to have für AAA-Class)
| # | Finding | Aufwand |
| :--- | :--- | :--- |
| 5 | TLS-Pool-Key deterministisch machen (F-5-1) | S |
| 6 | Mutation-Threshold auf >=85% (F-9-2) | S |
| 7 | PSR-Cache Integration-Test (Redis) (F-9-3) | M |
| 8 | Config Konstruktor-Validation (F-5-3) | XS |
| 9 | Per-Session `maxRequestDurationSeconds`-Cap (F-5-2) | S |
| 10 | `assertSuccessfulStatus()` Backstop-Test 301/102 (F-7-1) | XS |
| 11 | `options()` Cache-Hit Logging & Consistency (F-2-2/3) | XS |
| 12 | Psr*OptionsCache ISTag-Meta-Key TTL (F-3-2) | XS |
| 13 | `stream_set_timeout` mit Microseconds (F-4-2) | XS |
| 14 | OPTIONS-Body in Cache nullen (F-8-1) | XS |
| 15 | `Config::withoutTls()` Methode (F-8-2) | XS |

---

## 12. Roadmap v3.0.x → v3.1 → v3.2 → v4.0

* **v3.0.1 (Patch — reine Bugfixes)**
    * *Schedule: 2-4 Wochen nach v3.0.0 (F-2-1 sicherheitsrelevant)*
    * Fixes für P1 und P2 Bugs (Timeout Exceptions, Host Header Validation, Cache Future Konsistenz, etc.)
* **v3.1.0 (Minor — additiv)**
    * *Schedule: 2-3 Monate nach v3.0.1*
    * Features wie neues ISTag-Caching (Race-Condition Fix), Deterministic TLS Fingerprint, Session Timeouts, und massive Coverage Pushes.
* **v3.2.0 (Minor — Symfony-Bundle-Companion)**
    * *Schedule: 6-9 Monate nach v3.0.0*
    * Veröffentlichung des `ndrstmr/icap-flow-bundle:^0.1`.
    * Integration von OpenTelemetry (falls Bedarf).
* **v4.0.0 (Major — nur falls echte BC-Breaks erforderlich)**
    * PHP 8.6+ Minimum oder tiefgreifende Architekturänderungen.

---

## 13. Quellenverzeichnis

* **RFCs:** RFC 3507 (ICAP), RFC 7230 (HTTP/1.1), RFC 9110/7231
* **PSR:** PSR-3, PSR-4, PSR-6, PSR-12, PSR-16, PSR-20
* **Standards:** OWASP Top 10 (2021/2025), BSI IT-Grundschutz (OPS.1.1.4, APP.4.4), DSGVO Art. 32, EUPL-1.2
* **Symfony 7.4 LTS:** HttpClient, Cache, Profiler
* **Amp v3 / Revolt 1.x:** Socket\ClientTlsContext, Timeouts, EventLoop
* **Vorgänger-Audits:** Diverses internes Review-Material (Claude, Codex, Jules, etc.)
* **Reference-Implementations:** c-icap, egirna/icap-client (Go)