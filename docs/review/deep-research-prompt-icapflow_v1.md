# Deep Research Prompt: ICapFlow Production-Readiness & Technical Excellence Audit

> **Einsatz:** In Claude Code (Opus 4.7), OpenAI Codex (GPT‑5-Codex), oder Google (Gemini 3.1 Pro) mit aktiviertem Deep Research / Web Search / Repository-Zugriff einfügen.
> **Ziel:** Vollständige, unabhängige technische Begutachtung des Repositories `ndrstmr/icap-flow` mit konkreten, umsetzbaren Empfehlungen.
> **Autor-Kontext:** Andreas (ndrstmr), Entwicklungsleiter im öffentlichen Sektor und Initiator der 'public sector dev crew'.

---

## 1. Rolle & Auftrag

Du bist **Principal PHP/Symfony Engineer** mit 15+ Jahren Erfahrung in der Entwicklung von Enterprise-Grade PHP-Bibliotheken, tiefer Kenntnis des Symfony-Ökosystems (HttpClient, Messenger, Lock, DependencyInjection), des asynchronen PHP-Ökosystems (Amphp v3, Revolt, ReactPHP, Swoole/OpenSwoole) sowie RFC-konformer Netzwerkprotokoll-Implementierungen. Zusätzlich bist du vertraut mit den Anforderungen des öffentlichen Sektors in Deutschland (BSI-Grundschutz, Digitale Souveränität, EUPL-Lizenzierung).

Dein Auftrag ist eine **ehrliche, gründliche, vollständig unabhängige Due-Diligence-Analyse** des Repositories `ndrstmr/icap-flow` (v1.0.0, Packagist: `ndrstmr/icap-flow`). Du bewertest, ob diese Bibliothek **produktionsreif** ist für den Einsatz in Symfony-basierten Web-Portalen und E-Commerce-Systemen (TYPO3, Shopware) der öffentlichen Verwaltung, und erarbeitest einen **konkreten Weg zum "perfekten" PHP/Symfony-ICAP-Client**.

**Wichtig:**
- Keine Gefälligkeitsbewertung. Finde echte Schwachstellen.
- Belege jede Aussage mit konkreten Datei-/Zeilen-Referenzen aus dem Repository.
- Zwischen Fakten (aus dem Code) und Empfehlungen (dein Urteil) klar trennen.
- Quellen (RFCs, Symfony-Docs, Fachartikel) explizit verlinken.

---

## 2. Repository-Kontext (bereits bekannt)

| Attribut | Wert |
|---|---|
| Repository | https://github.com/ndrstmr/icap-flow |
| Packagist | https://packagist.org/packages/ndrstmr/icap-flow |
| Sprache | PHP 8.3+ |
| Aktuelle Version | v1.0.0 (Release: Juni 2025) |
| Lizenz | EUPL-1.2 |
| Linting | PHPStan Level 9, PHP-CS-Fixer |
| Tests | PHPUnit |
| CI | GitHub Actions (`.github/workflows/ci.yml`) |
| Async-Stack | Revolt Event Loop (vermutlich Amphp v3) |
| Bekannte Klassen | `IcapClient` (async), `SynchronousIcapClient`, `Config`, `PreviewStrategyInterface` |
| Namespace | `Ndrstmr\Icap` |

Das Repository enthält die Top-Level-Verzeichnisse: `.github/workflows`, `docs`, `examples`, `src`, `tests` sowie Konfigurationsdateien (`composer.json`, `phpstan.neon`, `phpunit.xml.dist`, `.php-cs-fixer.dist.php`).

---

## 3. Scope & Abgrenzung

### 3.1 In Scope — das GESAMTE Repository

Du untersuchst **jede Datei**, nicht nur README und Docs:

1. **`src/`** — Vollständige Inspektion jeder Klasse, jedes Interfaces, jedes Traits, jedes Enums. Namenswahl, Sichtbarkeiten, Type-Deklarationen, Method-Signaturen, Attribute, Docblocks.
2. **`tests/`** — Test-Abdeckung, Test-Qualität, Mocking-Strategien, Datenprovider, Testhierarchie (Unit/Integration/Functional), Integration gegen echte ICAP-Server (c-icap, Squid, Kaspersky, Symantec).
3. **`examples/` inkl. `examples/cookbook/`** — Realitätsnähe, Vollständigkeit, Didaktik, Ausführbarkeit.
4. **`.github/workflows/`** — CI-Stufen, Matrix (PHP-Versionen), Caching, Security-Scans, Release-Automation, Dependabot/Renovate.
5. **`docs/`** — Vollständigkeit, API-Doku-Generierung (phpDocumentor/Doctum?), Architecture Decision Records, Mission-Charter (`docs/agent.md`).
6. **Root-Konfiguration** — `composer.json` (Dependencies, Autoload, Suggest, Conflicts, PHP-Constraints, funding, Scripts), `composer.lock`, `phpstan.neon` (Level, Baseline, Ignored Errors, Extensions), `phpunit.xml.dist` (Coverage-Setup, Test-Suites, env), `.php-cs-fixer.dist.php` (Regelset, Risky Rules), `.gitignore`, `CHANGELOG.md` (Keep-a-Changelog? Semver-Disziplin), `CONTRIBUTING.md`, `LICENSE`.
7. **Git-Historie & Issues/PRs** — Commit-Qualität (Conventional Commits?), Release-Kadenz, offene Issues, Security-Advisories.

### 3.2 Out of Scope

- ICAP-Server-Implementierungen (außer zum Vergleich/Testing).
- Generische PHP-Einführung.

---

## 4. Analyse-Phasen

Führe die Analyse in **sechs aufeinander aufbauenden Phasen** durch. Dokumentiere nach jeder Phase Zwischenergebnisse, bevor du zur nächsten wechselst.

### Phase 1 — Repository-Inventar & erste Einordnung

**Output:**
- Vollständige Dateiliste mit Zeilenumfängen (LOC `src/`, LOC `tests/`).
- Test-zu-Code-Ratio.
- Klassen-/Interface-/Enum-Diagramm (textuell oder Mermaid) inkl. Abhängigkeiten zwischen Klassen.
- Dependency-Graph aus `composer.json` (runtime + dev), inklusive transitiver Abhängigkeiten aus `composer.lock` für sicherheitsrelevante Pakete.
- Öffentliche API-Oberfläche (`public`-Klassen/Methoden, die durch Semver geschützt werden müssen).

### Phase 2 — Code- & Architektur-Analyse

Bewerte systematisch entlang dieser Dimensionen:

#### 2.1 Sprachmoderne & Typsystem
- Konsequente Nutzung von PHP 8.3+ Features: `readonly` Properties/Classes, `enum` (inkl. Backed Enums), `#[Attribute]`s, First-class callable syntax, `never`-Return-Typen, `Override`-Attribut, Asymmetric Visibility (8.4 — ggf. vorausschauend), `json_validate()`.
- Vollständige Type-Deklarationen (keine `mixed` ohne Grund, Generics via PHPStan-Docblocks `@template`, `@param-out`, `@phpstan-impure`).
- PHPStan Level 9 — sauber oder Baseline mit Altlasten?
- Strikte Vergleiche, `declare(strict_types=1)` überall.

#### 2.2 Design, Pattern & SOLID
- SRP auf Klassenebene, Interface-Segregation, Dependency Inversion.
- Verwendete Patterns: Strategy (Preview — bereits erwähnt), Factory (`::create()` Methoden), Builder, Decorator, möglicherweise Circuit Breaker/Retry.
- Verhältnis Abstraktion ↔ Implementierung (`IcapClient` vs. `SynchronousIcapClient` — ist das eine saubere Dekoration oder Duplikation?).
- DTO-Design (`Config`, `Result`/`IcapResponse`): `readonly`? Immutable? Fluent Wither-Pattern?
- Fehlt ein klarer "Hexagonal"/"Clean Architecture"-Schnitt (Ports/Adapters)?

#### 2.3 PSR-Compliance
Prüfe gezielt auf:
- **PSR-3** (LoggerInterface) — Logging pluggbar?
- **PSR-4** — Autoload korrekt?
- **PSR-7 / PSR-17** (HTTP Messages) — für ICAP bewusst umgangen oder angelehnt?
- **PSR-11** (Container) — Konstruktor-Injection sauber?
- **PSR-18** (HTTP Client) — Philosophisch verwandt; Inspirationsquelle?
- **PSR-20** (Clock) — für Retries/Timeouts?
- Symfony-Contracts: `EventDispatcherInterface`, `HttpClientInterface`-Stil?

#### 2.4 Fehlerbehandlung & Exception-Design
- Exception-Hierarchie (Basis-Interface + spezialisierte Klassen für Connection/Protocol/Timeout/Auth?).
- Recoverable vs. non-recoverable Errors klar getrennt?
- Exception-Chaining (`previous`), Context-Properties (ICAP-Statuscode, Server-Response).
- Sprechende Fehlermeldungen ohne Leakage sensibler Daten.

#### 2.5 Ressourcen-Management & Connection-Handling
- Explizites Schließen von Sockets (`finally`, Destruktor, `close()`).
- Connection Pooling / Keep-Alive (ICAP erlaubt persistente Verbindungen — wird das genutzt?).
- Backpressure bei Streaming großer Dateien.
- Memory-Footprint bei großen Uploads (Streaming vs. Buffering).
- Timeouts granular (Connect vs. Socket vs. Stream) — gemäß `Config` vorhanden, aber werden sie durchgängig respektiert?

#### 2.6 Async-Implementierung
- Sauberer Einsatz von Revolt `EventLoop` / Amphp `Future` / `Cancellation`.
- Cancellation-Tokens durchgereicht?
- Fiber-sicher? Interaktion mit Symfony's neuem Fiber-Support?
- Backward-Kompatibilität der sync-Variante zu non-fiber-Kontexten.

### Phase 3 — ICAP-Protokoll-Compliance (RFC 3507)

Das ist das **inhaltliche Kernkriterium**. ICAP wird durch **RFC 3507** (Internet Content Adaptation Protocol, April 2003) spezifiziert, ergänzt durch **draft-stecher-icap-subid-00** und verbreitete De-facto-Standards.

Prüfe Punkt für Punkt im Code:

#### 3.1 Methoden
- **OPTIONS** — Capability Discovery: Werden `Methods`, `ISTag`, `Max-Connections`, `Options-TTL`, `Preview`, `Transfer-Preview`, `Transfer-Ignore`, `Transfer-Complete` korrekt ausgewertet und gecacht?
- **REQMOD** — Request Modification.
- **RESPMOD** — Response Modification.
- Vollständige Umsetzung aller drei? Nur Scan-Use-Case?

#### 3.2 Nachrichten-Format
- ICAP-Startzeile (`METHOD icap://... ICAP/1.0`).
- ICAP-Header inkl. Encapsulated-Header (kritisch! Offsets korrekt berechnet?).
- HTTP-in-ICAP (gekapselte Request-/Response-Header).
- Chunked-Transfer-Encoding für den Body.
- Korrekte Behandlung von `ieof` beim letzten Chunk.

#### 3.3 Preview-Feature
- Korrekte Preview-Size-Aushandlung via OPTIONS.
- `Allow: 204` — "No Content"-Optimierung bei sauberen Dateien.
- `100 Continue` korrekt verarbeitet.
- Wird eine Preview-Strategy-Abstraktion sinnvoll genutzt oder nur Beiwerk?

#### 3.4 Statuscodes
- Alle relevanten ICAP-Codes behandelt: 100, 200, 204, 206 (Partial Content — selten, aber definiert), 400, 403, 404, 405, 408, 500, 501, 502, 503, 505?
- Semantische Unterscheidung (z.B. `204 No Adaptation` vs. `200 OK with modified response`).

#### 3.5 Robustheit
- Malformed Headers → klare Exception statt Panic.
- Große ICAP-Header (Line-Length-Limits).
- Header-Injection-Vektoren (CRLF in Dateinamen/Filenames).
- Server, die `X-Virus-Name` oder andere vendor-spezifische Header nutzen (Kaspersky, Symantec, Sophos, ClamAV/c-icap, McAfee).

#### 3.6 Kompatibilitätstests
Prüfe, ob gegen diese Server getestet wird (oder dokumentiert getestet wurde):
- **c-icap** (Referenz-Open-Source-Implementierung)
- **Squid** mit ICAP-Client-Seite
- **ClamAV** über `c-icap` + `squidclamav`
- **Kaspersky Scan Engine / Anti-Virus for Linux File Server**
- **Symantec Protection Engine**
- **Sophos AV Dynamic Interface** (hat ICAP-Variante)
- **F-Secure**
- **TrendMicro IWSVA**

### Phase 4 — Security & Compliance-Assessment

Da Andreas im öffentlichen Sektor arbeitet, ist dieser Teil besonders wichtig.

#### 4.1 Security
- Input Validation für ICAP-Antworten (Server kann bösartig sein → Library muss robust parsen).
- Integer-Overflow bei Content-Length/Encapsulated-Offsets.
- DoS-Resistenz (unbegrenzte Header, Slowloris-ähnliche Angriffe, Zip-Bomben im zu scannenden Content).
- TLS-Support (`icaps://` — RFC 3507 deckt das nicht direkt ab, aber de facto verbreitet). Wie gehandhabt? Cert-Validation?
- Credentials-Handling (wenn Auth-Header gesetzt wird — nicht in Logs leaken).
- Dependency-Security: `composer audit` Ergebnis, GitHub Security Advisories, Roave Security Advisories.

#### 4.2 Öffentliche Verwaltung & Compliance
- **EUPL-1.2** Lizenz — bereits gesetzt ✓. Header in Source-Dateien vorhanden?
- **BSI IT-Grundschutz** Relevanz: Wenn für Virenscan genutzt, ist das Teil von Baustein CON.6/APP.4. Ist das im README dokumentiert?
- **Digitale Souveränität**: Sind alle Dependencies Open Source & in der EU/Europa gehostet? Keine SaaS-Abhängigkeiten.
- **GDPR / DSGVO**: Werden gescannte Daten geloggt? Welche Header werden protokolliert?
- **OpenCoDE** Kompatibilität (Open Source für die öffentliche Verwaltung in DE).

### Phase 5 — Testing & Qualitätssicherung

- **Unit-Test-Coverage** (lines, branches, methods) — Ziel 90%+ für Library-Code.
- **Mutation Testing** mit Infection. Läuft das? MSI-Score?
- **Integration-Tests** gegen echten ICAP-Server (c-icap in Docker?).
- **Property-Based Testing** (Eris/phpunit-generator) für Parser-Robustheit.
- **Benchmark-Tests** (phpbench) für Performance-Regression.
- **Fuzzing** — gibt es ein Setup?
- Test-Architektur: klare Trennung Unit/Integration/Functional?
- Mocks vs. Fakes vs. In-Memory-Implementierungen.
- Determinismus (keine flaky Tests durch Timing).
- `phpunit.xml.dist` — strikte Warnings, Deprecation-Tracking, failOnRisky?

### Phase 6 — Symfony-Integration & Ökosystem-Fit

Hier ist die **Schlüsselfrage**: Was fehlt zum perfekten **Symfony-ICAP-Client**?

#### 6.1 Bundle-Frage
- Existiert ein `IcapFlowBundle`? Wenn nein: sollte die Kern-Library framework-agnostisch bleiben und ein separates Bundle-Repo entstehen?
- **Empfohlenes Muster** (siehe auch Symfony HttpClient / MercureBundle): Core-Library pur + schlankes Bundle mit `Configuration`, `Extension`, Service-Definitionen.
- DI-Integration: Tagged Services, Autowiring-Aliase, Bundle-Configuration-Tree.

#### 6.2 Framework-Features
- **Symfony Profiler** Datensammler (DataCollector) für ICAP-Aufrufe.
- **Monolog Channel** `icap`.
- **Messenger-Integration**: Async-Scanning via Message-Queue.
- **Console-Command**: `icap:scan`, `icap:options` für CLI-Debugging.
- **Flex-Recipe** (auf symfony/recipes-contrib oder recipes-private).
- **Environment-Variablen** via `%env()%`-Prozessoren.
- **Serializer-Integration** für Result-DTOs.
- **Validator**: Constraint `#[IcapClean]` für File-Upload-Validation.
- **VichUploaderBundle / OneupUploaderBundle Integration** für Virus-Scan bei Upload.

#### 6.3 Observability
- **OpenTelemetry** Instrumentation (Traces, Metrics).
- **Prometheus** Exporter-Hooks.
- Health-Check-Endpoint-Komponente (`Symfony\Component\HttpKernel\...` oder `liip/monitor-bundle`).

### Phase 7 — Benchmarking gegen andere ICAP-Clients

Führe einen **ehrlichen Vergleich** durch:

#### 7.1 PHP-Welt
Suche und bewerte (Aktualität, Feature-Set, Popularität, Code-Qualität):
- Andere Packagist-Packages mit `icap` im Namen.
- Historische Libs (z.B. `m4c/icap-client`, `wiseacre/icap` — falls existent).

#### 7.2 Andere Sprachen (als Maßstab)
- **Java**: `c-icap-client`, Apache-Commons-ICAP-Client (falls es den gibt), `greasyspoon`-Client.
- **Python**: `pyicap`, `icap-client`.
- **Go**: `go-icap`, `icap-client`.
- **Node.js**: `node-icap-client`.
- **.NET**: `ICAPClient`, relevante NuGet-Pakete.

Welche Features haben diese, die ICapFlow (noch) nicht hat? Welche Patterns wurden dort etabliert?

#### 7.3 Referenzimplementierung
- **c-icap** (https://sourceforge.net/projects/c-icap/) — als De-facto-Referenz für Protokoll-Verhalten.

---

## 5. Bewertungsmatrix (quantitativ)

Erstelle am Ende eine Scoring-Tabelle. Jede Dimension 0–10 Punkte, mit kurzer Begründung und konkreten Referenzen.

| Dimension | Score (0-10) | Begründung | Kritische Findings |
|---|---|---|---|
| Sprachmoderne (PHP 8.3+ Features) | | | |
| Typsystem / PHPStan-Strenge | | | |
| SOLID / Architektur-Klarheit | | | |
| Exception-Design | | | |
| PSR-Konformität | | | |
| Ressourcen-/Connection-Management | | | |
| Async-Implementierung (Revolt/Amphp) | | | |
| ICAP RFC 3507 Methoden-Vollständigkeit | | | |
| ICAP Preview / 204-Optimierung | | | |
| ICAP Robustheit (Parser, Edge Cases) | | | |
| Security-Posture | | | |
| Test-Coverage (Lines/Branches) | | | |
| Mutation Testing | | | |
| Integration-Testing gegen echte Server | | | |
| CI-Pipeline-Qualität | | | |
| Dokumentation (README, docs/, Docblocks) | | | |
| Example-/Cookbook-Qualität | | | |
| Symfony-Bundle-Integration | | | |
| Observability / Profiler / Logging | | | |
| Release-Management / Semver / Changelog | | | |
| Public-Sector-Fit (EUPL, BSI, Souveränität) | | | |
| **Gesamt-Readiness-Score** | **/210** | | |

Zusätzlich: **TRL-Einschätzung** (Technology Readiness Level 1–9) mit Begründung.

---

## 6. Produktionsreife-Gate

Beantworte die drei Kernfragen **ungeschönt**:

### 6.1 Ist die Bibliothek heute produktionsreif?
- **Für interne Tools / Prototypen**: Ja / Mit Einschränkungen / Nein — Begründung.
- **Für Symfony-Applikationen in projekten des öffentlichen Sektor (TYPO3, Shopware, Portale)**: Ja / Mit Einschränkungen / Nein — Begründung.
- **Für den Einsatz als kritische Security-Komponente (Virenscan auf Upload)**: Ja / Mit Einschränkungen / Nein — Begründung.

### 6.2 Was fehlt zum "technisch perfekten" State?
Priorisierte Liste der Gaps:
- **P0 (Blocker für Produktion)** — Dinge, die vor Einsatz zwingend zu fixen sind.
- **P1 (Kritisch für Ökosystem-Fit)** — Dinge, die ICapFlow zum De-facto-Standard machen.
- **P2 (Nice-to-have / Differenzierung)** — Features, die über das Minimum hinausgehen.
- **P3 (Langfristige Vision)** — strategische Ausrichtung.

### 6.3 Konkrete Roadmap

Schlage eine **versionsbasierte Roadmap** vor:
- **v1.1.x** (sofort, reine Bugfixes/Security/DX) — Liste von konkreten PRs.
- **v1.2.0** (Minor, neue Features ohne Breaking Changes).
- **v2.0.0** (Major, mit dokumentierten Breaking Changes).
- Begleit-Repo: `icap-flow-bundle` für Symfony-Integration — wann sinnvoll?

---

## 7. Output-Format & Deliverables

Liefere am Ende **eine strukturierte Antwort** mit folgenden Abschnitten (in dieser Reihenfolge):

1. **Executive Summary** (max. 400 Wörter) — Kernbefund, Empfehlung, TRL-Score.
2. **Repository-Inventar** (Phase 1 Ergebnisse kompakt).
3. **Findings nach Dimension** (Phase 2–6 detailliert, mit Code-Referenzen im Format `src/Pfad/Datei.php:ZeileX-Y`).
4. **ICAP RFC 3507 Compliance-Checkliste** (Phase 3 als Pro/Contra-Liste, tabellarisch).
5. **Wettbewerbsvergleich** (Phase 7, tabellarisch).
6. **Bewertungsmatrix** (Kapitel 5).
7. **Produktionsreife-Gate-Entscheidung** (Kapitel 6.1).
8. **Priorisierte Gap-Liste** (Kapitel 6.2).
9. **Roadmap** (Kapitel 6.3).
10. **Quellenverzeichnis** — Alle konsultierten RFCs, Docs, Repos, Artikel mit Links.

**Format:**
- Markdown mit sinnvollen Überschriften (H2 für Hauptabschnitte, H3 für Unterpunkte).
- Code-Blocks mit Sprach-Tag.
- Tabellen für Matrizen und Checklisten.
- Inline-Zitate mit Links bei externen Referenzen.
- Konkrete, umsetzbare Vorschläge — kein "man könnte..."-Schwafeln, sondern "Füge in `src/Client/IcapClient.php` nach Zeile X folgendes hinzu: [Code]".

**Sprache:** Deutsch. Fachtermini auf Englisch belassen. Ton: kollegial-direkt, keine Floskeln.

---

## 8. Quality Gates für deine Analyse

Bevor du deine finale Antwort abgibst, prüfe:

- [ ] Habe ich **jede Datei in `src/` mindestens einmal inspiziert** (nicht nur README gelesen)?
- [ ] Habe ich **jede Test-Datei** auf Coverage-Lücken geprüft?
- [ ] Habe ich **RFC 3507 konkret aufgeschlagen** und gegen den Code geprüft — nicht nur aus dem Gedächtnis?
- [ ] Habe ich **mindestens drei Alternativ-Implementierungen** in anderen Sprachen als Benchmark angeschaut?
- [ ] Ist **jede kritische Aussage mit einer Datei-/Zeilen-Referenz oder externen Quelle belegt**?
- [ ] Sind meine **Empfehlungen so konkret**, dass sie direkt als GitHub-Issue geöffnet werden könnten?
- [ ] Habe ich die **Symfony-/Public-Sector-Spezifika** (DI, Profiler, BSI, EUPL, Souveränität) ausreichend gewichtet?
- [ ] Bin ich **ehrlich kritisch** oder habe ich unterbewusst wohlwollend bewertet?

Wenn eines dieser Gates nicht erfüllt ist, recherchiere nach und ergänze, bevor du abschließt.

---

## 9. Zusätzliche Hinweise

- **Wenn Informationen unzugänglich sind** (z.B. privater Repo-Teil, fehlende Issue-History): das explizit markieren, keine Halluzinationen.
- **Wenn du Annahmen triffst** (z.B. über nicht dokumentierte interne Motivation): als Annahme kennzeichnen.
- **Wenn du zwischen zwei Design-Alternativen abwägst**: beide Optionen mit Trade-offs darstellen, dann eine begründete Empfehlung aussprechen.
- **Bei Ungewissheit über aktuelle Versionen von Dependencies** (Amphp, Revolt, PHPStan): aktuellen Stand recherchieren.

**Starte jetzt mit Phase 1.**
