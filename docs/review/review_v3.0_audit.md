# Deep Review: ICapFlow v3.0.0 Production-Readiness & Technical Excellence Audit

## Executive Summary

ICapFlow v3.0.0 ist im Kern ein **konsequenter Cleanup-Major** ohne neue Feature-Fläche; die drei angekündigten BC-Breaks sind am Code nachvollziehbar umgesetzt: `executeRaw()` ist `protected`, `options()` liefert `IcapResponse`, und `IcapResponseException` ist entfernt. Die v2.2-Schließungen (Per-IO-Timeout, Pool-Idle-Eviction, OPTIONS-driven Pool-Tuning, ISTag-basierte Cache-Invalidation, PSR-6/16-Adapter) sind ebenfalls im Code und in dedizierten Tests sichtbar.

Aus Security-/Korrektheitssicht ist die zentrale Fail-Secure-Matrix stabil: `100` außerhalb Preview, `4xx` und `5xx` werden als Exceptions erzwungen; Parser/Framer haben DoS-Grenzen und RFC-7230-obs-fold-Handhabung. Für den Einsatz als Upload-Malware-Gate in Symfony/TYPO3/Shopware lautet mein Urteil: **produktionsreif mit überschaubaren P1/P2-Aufgaben im Ökosystem (Bundle/Observability) statt im Core**.

Haupt-Risiken liegen nicht mehr in offensichtlichen Protokollfehlern, sondern in Randbedingungen: (a) Cross-process-Race-Fenster der PSR-Cache-Meta-Key-Invalidation, (b) kein globales Hard-Deadline-Konzept über mehrere Per-IO-Zyklen, (c) fehlende First-Party-Symfony-Bundle-Schicht.

**TRL-Einschätzung v3.0.0: 8/9** (v2.1-Konsens lag bei 7). Der Sprung ergibt sich aus geschlossenem Audit-Backlog, stabiler Test-/Mutation-Governance und klarer SemVer-Bereinigung vor Bundle-Start.

## Repository-Inventar v3.0.0 (Phase 1)

- PHP-Dateien gesamt: 78 (`src`: 39, `tests`: 39).
- LOC: `src` 4212, `tests` 5122.
- Test-zu-Code-Ratio (LOC): ~1.22.
- Unit-Run lokal: 159 Tests, 363 Assertions.

## v3.0-BC-Break-Verifikation

| Item | Erwartung | Status | Evidenz |
|---|---|---|---|
| v3-V | `IcapClient::executeRaw()` ist `protected` inkl. Security-Rationale | Verifiziert geschlossen | `src/IcapClient.php` (Docblock + `protected function executeRaw`) |
| v3-W | `options(): Future<IcapResponse>` + gemeinsame Failure-Single-Source (`assertSuccessfulStatus`) | Verifiziert geschlossen | `src/IcapClient.php`, `src/IcapClientInterface.php`, `src/SynchronousIcapClient.php` |
| v3-F | `IcapResponseException` entfernt; Throw-Sites auf `IcapProtocolException` | Verifiziert geschlossen | `src/Exception/` ohne Datei, `src/DefaultPreviewStrategy.php`, `tests/Exception/ExceptionHierarchyTest.php` |

### Spot-Check v2.2-Closures

- Per-IO-Timeout umgesetzt in `AmpTransportSession::makeIoCancellation()` und per Test abgesichert.
- Idle-Eviction (`maxIdleSeconds`) aktiv in `AmpConnectionPool` + eigene Testdatei.
- OPTIONS-driven Pool-Tuning via `tuneFromOptions()` vorhanden + Tests für dynamische Updates.
- PSR-6/16-Caches mit ISTag-Meta-Keys (`__icap_istag`, `__icap_keys`) vorhanden + dedizierte Tests.

## Findings nach Dimension (Fakten vs Empfehlungen)

### P1 — Cross-process Cache Race (PSR-6/16 Meta-Key Invalidation)

**Fakt:** `Psr6OptionsCache` und `Psr16OptionsCache` implementieren ISTag-Flush über getrennte Writes auf Daten-Key + Meta-Keys (`__icap_istag`, `__icap_keys`), ohne atomare Transaktion.

**Risiko:** In Redis/Memcached-Clustern können konkurrierende Worker inkonsistente Zwischenstände sehen (TOCTOU), insbesondere bei hoher OPTIONS-Schreibfrequenz.

**Empfehlung:** Optionalen atomaren Backend-Mode ergänzen (z. B. CAS/Lua/transaction-capable adapter hook), mindestens dokumentierte Konsistenzgarantien + Lasttest-Szenario.

### P2 — Kein globales Request-Deadline-Konzept

**Fakt:** Per-IO-Timeout ist korrekt pro I/O neu zusammengesetzt; es existiert kein zusätzlicher harter Gesamt-Timeout über die komplette Session/Preview-Mehrphasen-Operation.

**Risiko:** Bei „dauerhaft knapp unter Timeout“-I/O kann ein Request praktisch unbegrenzt laufen.

**Empfehlung:** Optionalen `maxSessionDuration`/`hardDeadline` in `Config` ergänzen und in `AmpTransportSession` mit externer Cancellation kombinieren.

### P1 — Ökosystem-Lücke: fehlendes offizielles Symfony-Bundle

**Fakt:** Core ist DI-fähig, aber produktiver Symfony-Betrieb (multi-client config, cache wiring, retry policy, channelized logging, health-probes) erfordert derzeit Eigenbau.

**Empfehlung:** `ndrstmr/icap-flow-bundle` zeitnah als Begleit-Repo (`^3.0`) starten; Fokus auf Configuration tree, service aliases, PSR-6 wiring, optional DataCollector.

## Positiv verifiziert (erhalten)

- Fail-secure Status-Mapping bleibt strikt und zentralisiert.
- Strict preview-continue streamt Restdaten ohne Vollpufferung.
- TLS-kontextbasierte Pool-Isolation gegen Cross-Tenant-Reuse vorhanden.
- Tests decken Wire-Format, Pool-Lifecycle, Security-Guards und Cache-Adapter breit ab.

## Roadmap

- **v3.0.x:** Dokuhärtung zu Cache-Konsistenz + klare Guidance für OPTIONS-Cache in verteilten Setups.
- **v3.1.0:** Hard-deadline-Feature, OTel-Decorator, offizielles Symfony-Bundle v0.1/1.0.
- **v3.2.0:** Property-based Parser tests, optional fuzz harness, Symfony cookbook for `cache.app` + Messenger.
- **v4.0.0:** Nur bei tatsächlichen API-Zwängen (derzeit nicht erforderlich).
