# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> Sprache: **Deutsch.** Antworte, dokumentiere und committe in dieser Repo auf Deutsch (Code, Identifier und Wire-Format-Konstanten bleiben englisch).

## Projekt

`ndrstmr/icap-flow` — async-fähiger ICAP-Client (RFC 3507) für PHP 8.4+, veröffentlicht auf Packagist. Library, keine Anwendung.

Die Library scannt Uploads gegen einen ICAP-Server (typischerweise c-icap + ClamAV oder eine Vendor-Appliance: Symantec, Trend Micro, Sophos, McAfee, Kaspersky) und liegt damit auf dem sicherheitskritischen Pfad jeder aufrufenden Anwendung. Jede Änderung in `src/` ist sicherheitsrelevant; siehe Disclaimer im `README.md` und die Reviews unter `docs/review/review_v2-1/`.

## Verbindlicher Engineering-Maßstab

Die agentübergreifenden Regeln für Implementierung, Tests, Dokumentation und Abnahme stehen ausschließlich in [AGENTS.md — Engineering Standard](AGENTS.md#engineering-standard).

## Häufig genutzte Befehle

Alle Workflows laufen über Composer-Skripte (definiert in `composer.json`):

```bash
composer test               # Pest, nur Unit-Suite (Default lokal & im CI-Quality-Job)
composer test:integration   # Pest, Integration-Suite — braucht echten ICAP-Server
composer test:all           # beide Suites
composer stan               # PHPStan Level 9 + bleedingEdge, scannt src/ und tests/
composer cs-check           # PSR-12 via php-cs-fixer (dry-run)
composer cs-fix             # PSR-12-Fixes anwenden
composer audit              # composer audit + roave/security-advisories
composer mutation           # Pest-Mutation-Tests (Unit, parallel, --min=65)
```

Einzelner Test / einzelne Datei:

```bash
vendor/bin/pest tests/IcapClientTest.php
vendor/bin/pest --filter='returns infected when virus header present'
```

PHPStan auf einem einzelnen Pfad:

```bash
vendor/bin/phpstan analyse src/IcapClient.php
```

### Integrationstests

Die `Integration`-Suite ist aus `composer test` ausgenommen. Sie spricht einen echten ICAP-Server und überspringt sich selbst, wenn die Env-Variablen nicht gesetzt sind — Contributors ohne Docker bekommen also weiterhin einen grünen Lauf. Für die End-to-End-Wire-Format-Prüfung:

```bash
docker compose up -d                               # mnemoshare/clamav-icap auf :1344
ICAP_HOST=127.0.0.1 ICAP_PORT=1344 \
  ICAP_ECHO_SERVICE=/avscan \
  ICAP_CLAMAV_SERVICE=/avscan \
  composer test:integration
```

Beim ersten Start lädt das ClamAV-Image die Signaturdatenbank (~200 MB, mehrere Minuten). Der Healthcheck im Compose-File und der CI-Job in `.github/workflows/ci.yml` warten deshalb auf eine echte `ICAP/1.0`-Antwort, nicht nur auf einen offenen Port — dieses Muster bitte übernehmen, wenn du Integration-Skripte schreibst.

## Architektur

Schichtung (jede Schicht hängt nur von darunter liegenden ab):

```
SynchronousIcapClient ─┐
                       ├─► IcapClient (Facade) ──► PreviewStrategy   ──► PreviewDecision
RetryingIcapClient ────┘                       ──► RequestFormatter  ──► ChunkedBodyEncoder
                                                ──► ResponseParser
                                                ──► TransportInterface (+ SessionAwareTransport)
                                                       │
                                                       ├─ AsyncAmpTransport      (amphp/socket, Default)
                                                       │     └─ AmpTransportSession + AmpConnectionPool
                                                       └─ SynchronousStreamTransport (stream_socket_client)
                                                Cache: OptionsCacheInterface (InMemoryOptionsCache)
                                                DTOs:  IcapRequest, IcapResponse, HttpRequest, HttpResponse, ScanResult
```

Tragende Designregeln — Abweichungen brauchen einen guten Grund:

- **Fail-Secure-Statuscode-Auswertung lebt in `IcapClient::interpretResponse()`.** Jeder Status, der kein eindeutiges Clean-Signal ist (204, 200/206 ohne Virus-Header), wirft eine typisierte Exception. `100 Continue` außerhalb des Preview-Flows ist ein Protokollfehler, kein Clean-Scan — Finding G der v2-Konsolidierung. Hier dürfen keine stillen Fallbacks rein. `options()` hat eine eigene, engere Regel (`assertOptionsStatus()`, N02): nur 200 ist die Antwort auf OPTIONS, vom Server wie aus dem Cache; 100, 4xx und 5xx behalten ihre Typen, jeder andere Status ist `IcapProtocolException`, und eine abgelehnte Antwort wird nicht gecacht. Die Scan-Auswertung bleibt davon unberührt.
- **Der Preview-Flow nutzt `executeRaw()`** (ohne Fail-Secure-Interpretation), weil `100 Continue` mitten im Austausch legitim ist. Externe Aufrufer verwenden `request()` / `scanFile*()`, niemals direkt `executeRaw()`.
- **Strict RFC 3507 §4.5 Preview-Continue setzt `SessionAwareTransport` voraus.** `IcapClient::scanFileWithPreview()` verzweigt: session-fähige Transports laufen über `scanFileWithPreviewStrict` (Preview + Continuation auf demselben Socket, ein logischer ICAP-Request), andere fallen auf `scanFileWithPreviewLegacy` zurück (Zwei-Request-Approximation, kostet einen zusätzlichen TCP/TLS-Handshake). Diese beiden Pfade nicht zusammenlegen.
- **Sockets werden geschlossen, nicht gepoolt, solange der Austausch nicht belegt sauber war.** Standard ist `close()`. `AsyncAmpTransport::request()` liefert einen ungeprüften String und schließt immer. Über eine Session (`scanFileWithPreviewStrict`) geht ein Socket per `release()` erst zurück, nachdem Frame, Status und Verdikt geprüft sind (`interpretResponse()`) und keine Antwort des Austauschs `close` in der `Connection`-Tokenliste trug (`HeaderFields::hasToken()`, jede Schreibweise). Exceptions, 100 nach `ieof`, unerlaubte Status und Framing-Fehler schließen, damit der nächste Pool-User keine ausgerichteten Bytes sieht. Pool-Schlüssel für TLS ist ein nie wiederverwendetes Token je `ClientTlsContext`-Objekt (`\WeakMap`), nie `spl_object_hash()`.
- **Der Pool begrenzt Verbindungen, nicht nur Idle-Sockets (`AmpConnectionPool`, M04–M06).** Verbindende, aktive und idle Sockets zählen gegen `maxConnectionsPerHost` (Endpoint = Host und Port, egal welcher TLS-Kontext) und `maxConnections` (ganzer Pool, Standard = Endpoint-Cap); der Platz wird vor dem ersten Suspendieren reserviert (`admit()`, kein Suspendieren dazwischen), die Zählung ergibt sich aus der Registry (`occupancy()`), nicht aus Zählern: Connect-/TLS-Fehler, Cancel, `close()` durch den Halter und ein eingesammelter Socket geben den Platz frei, genau einmal. Ein voller Pool wirft `IcapConnectionException`; optional wartet eine endliche FIFO-Queue (`maxWaiters`, `waitTimeout` → `IcapTimeoutException`; der Cancel des Aufrufers bleibt `CancelledException` und gewinnt). Die Frist ist eine absolute Endzeit auf der monotonen Uhr (`PoolWaiter::$deadline`), nicht nur ein Timer: `dispatch()` bedient keinen abgelaufenen Waiter, und was einem Waiter zugeteilt wurde, den die Frist vor der Übernahme einholt, geht über `giveBack()` zurück (ein Loop, den fremder Code aufhält, lässt den Timer nicht laufen). Nur ein Idle-Socket weicht für eine neue Verbindung (der älteste zuerst), nie einer in Benutzung. Der Pool nimmt nur Sockets zurück, die er verliehen hat, und für den Bucket, für den sie verliehen wurden (anderer Endpoint/TLS-Kontext, Fremdsocket → schließen); ein frühes Doppel-Release im Idle-Zustand ist idempotent (ein Eintrag, eine Ausgabe), ein spätes nach erneuter Ausgabe ist ohne Lease-Token nicht vom Release des neuen Halters zu unterscheiden, dagegen schützt die Session (`disposed`). Schließt sich das Leseende eines Sockets (Peer weg, Halter las EOF), schließt der Pool ihn ganz (`watch()`): amphp ruft `onClose` nur für den Reader, ein späteres `close()` des Halters meldet nichts mehr. Idle-Sockets altern in allen Buckets auf einer monotonen Uhr aus (Timer unreferenziert, nur solange etwas idle ist, hält den Pool nicht fest, `close()` entfernt ihn); vor der Ausgabe liest `isQuiet()` einen Idle-Socket einmal mit einem bereits abgebrochenen `read()`: Bytes oder EOF schließen ihn, es bleibt kein Watcher zurück, der Daten eines neuen Scans verschlucken könnte (`Cancellable::cancel()` ruft Abonnenten über `EventLoop::queue()`, also asynchron: ein dauerhaft wartender Read ließe sich vor der Übergabe nicht synchron beenden). Keine Liveness-Garantie, kein Reconnect und kein Retry in Pool oder Transport (wiederholt wird nur `options()` im `RetryingIcapClient`, nach 5xx). `tuneFromOptions($response, $config)` liest Max-Connections als positive Dezimalzahl bis 9 Stellen und senkt nur (mit `Config` für den Endpoint, ohne für alle); ein später höherer Wert disponiert Wartende neu. Die Endpoint-Limits sind auf 256 Endpoints begrenzt (`MAX_ENDPOINT_LIMITS`): ein weiterer Endpoint wird mit `IcapConnectionException` abgelehnt, nie still vergessen; `close()` leert sie, danach lehnt `tuneFromOptions()` ab. Ein Pool je Sicherheitsbereich; Rotation = neuer TLS-Kontext oder neuer Pool, geänderte Zertifikatsdateien invalidieren nichts. Ohne Pool begrenzt die Anwendung ihre Parallelität selbst.
- **Jeder IO-Aufruf einer Session hat ein eigenes Fenster** (`AmpTransportSession::inWindow()` für `write()` und `readResponse()`, `AsyncAmpTransport::openSession()` für den Erwerb): ein `streamTimeout`-Fenster für alle Chunks eines `write()`, kein Session-Timer. `readResponse()` hat zwei Fenster nacheinander (M10): `responseStartTimeout` bis zum ersten Byte (die Scanzeit des Servers; Fallback `streamTimeout`), danach `streamTimeout` als Höchstdauer für die ganze Antwort (`$arm`/`$next` ersetzt Timer und Endzeit, das Fenster bleibt dasselbe); Bytes, die schon in der Session liegen, zählen als begonnen. Ein `read()`, der seine Bytes ohne Suspendieren findet, gibt dem Timer keinen Loop-Turn: der Checkpoint prüft Caller und Uhr vor und unmittelbar nach jedem Read (auch bei EOF, bevor der Decoder die Bytes bekommt; beim ersten Read, bevor das Übertragungsfenster das Startfenster ersetzt) und vor der Rückgabe des Frames; der synchrone Transport unmittelbar nach `fread()`, bevor Daten, EOF oder Lesefehler ausgewertet werden, und am Frame-Ende (Caller zuerst, dann die Übertragungsfrist). Das ist ein Maximum, kein Idle-Timer, den jedes Byte neu startet. Der Erwerb hat `max(streamTimeout, tlsHandshakeTimeout)`, wenn ein TLS-Kontext gesetzt ist. Wo der Producer die Kontrolle zurückgibt (jeder Chunk, auch ein leerer; nach jedem Write; vor der Rückgabe) und nach dem Erwerb prüfen Caller, Fenster und eine monotone Endzeit; ein Connector, der nach Abbruch oder Fensterablauf einen Socket liefert, bekommt ihn geschlossen zurück. `Socket::write()` kennt keine Cancellation; ein unter Backpressure festhängender Write endet nur, indem der Socket geschlossen wird, nie durch ein `await()` mit Timeout auf einer weiterlaufenden Fiber. Ein Aufruf, der nicht fertig wird (Cancel des Aufrufers, Fensterablauf, Producer- oder Socketfehler), schließt die Session dauerhaft. Das eigene Fenster ist `IcapTimeoutException`; der Cancel des Aufrufers – auch ein `TimeoutCancellation` – bleibt `Amp\CancelledException` und gewinnt, wenn beides zutrifft. Timer und Subscriptions werden in `finally` ausdrücklich entfernt, nicht per Destruktor: eine gehaltene Exception hält die Argumente ihrer Frames am Leben.
- **Der synchrone Transport sendet jeden Chunk ganz oder wirft** (`SynchronousStreamTransport`): Ein blockierender `send()` ist nur unter Linux durch das Stream-Timeout begrenzt (auf macOS wartet er ewig auf einen Peer, der nicht liest); der Request geht deshalb über einen nicht blockierenden Socket hinaus, vor dem Lesen kehrt er in den blockierenden Modus zurück (dort gilt das Stream-Timeout). `writeAll()` schreibt in Scheiben von 64 KiB und setzt einen Teil-Write am erreichten Offset fort; bei vollem Puffer wartet `awaitWritable()` mit `stream_select()` bis zu einer monotonen Frist, die mit jedem Fortschritt neu beginnt (eine Stillstandsfrist, keine Höchstdauer des Writes: die Gesamtdauer begrenzt die Operations-Deadline). `false` ist `IcapConnectionException`, ein ganzes `streamTimeout` ohne Fortschritt `IcapTimeoutException`. Ein Wait, den `stream_select()` nicht leisten kann (Signal, Deskriptor ab `FD_SETSIZE`), ist kein Fehler: kurz schlafen und erneut schreiben. `streamTimeout` geht in Sekunden und Mikrosekunden an den Stream (`splitTimeout()`); null, negativ oder nicht endlich gibt sofort auf, statt über `tv_sec` −1 ewig zu warten. `readChunk()` meldet einen abgelaufenen Read (`timed_out`, bei Timeout 0 ein leerer Read ohne EOF) als `IcapTimeoutException`, nie als EOF; ein echtes EOF mit unvollständigem Frame bleibt Framingfehler des `ResponseFrameReader`, ein fehlgeschlagener Read ist `IcapConnectionException`. Ein laufender Wait ist durch den Cancel des Aufrufers nicht unterbrechbar (geprüft wird dazwischen); wo das nicht reicht, gehört `AsyncAmpTransport` hin. Die Tests lassen den Client in einem Kindprozess laufen (`SyncChild`), den der Test bei Fristablauf beendet: ein Client, der auf blockierendes Schreiben zurückfällt, hängt auf macOS sonst für immer.
- **Eine Operation hat eine Endzeit (`OperationDeadline`, M10).** Jeder öffentliche Aufruf von `IcapClient` (`request()`, `options()`, `scanFile()`, `scanFileWithPreview()`) und von `RetryingIcapClient` legt am Anfang `OperationDeadline::begin($cancellation, $operationTimeout)` an (Default 60 s, `Config::$operationTimeout` bzw. Decorator-Parameter): ein monotoner Zeitpunkt, der Cache, Pool-Warten, Connect, TLS, Upload, Preview, Continuation, Antworten und im Decorator alle Versuche und Pausen umfasst. Er läuft als **Cancellation** des Aufrufs durch alle Schichten, damit auch fremder Code (Connector, Producer, innerer Client) damit endet; ein verschachtelter Aufruf (`scanFile()` → `request()`, Decorator → `IcapClient`) bekommt nur das frühere von beiden Enden, nie ein späteres, getrennte und parallele Aufrufe je ein eigenes. Timer allein reichen nicht: `throwIfRequested()`/`checkpoint()` vergleichen die Uhr, und `IcapClient::run()` prüft vor dem Rückgabewert, jede Phase nach Kontrollübergaben (Antwort gelesen, Preview-Strategie und Logger zurück, vor `release()`, vor dem Cache-Schreiben): ein verspätetes Ergebnis ist ein Timeout, wird nicht gecacht und nicht gepoolt. Ein OPTIONS-Cache-Treffer ist eine Antwort wie jede andere: `checkpoint()` vor dem Zugriff und danach (`IcapClient::cachedOptions()`, im Future, der Cancel des Aufrufers gewinnt), nicht nur `hasEnded()`. `AmpTransportSession::release()` poolt keinen Socket einer abgebrochenen oder abgelaufenen Operation (`userCancellation->isRequested()`, auf der Uhr, nicht per Timer). **Eigenes Ende = `IcapTimeoutException`** (`outcome()` macht aus der `CancelledException`, die das Budget auslöste, die Timeout-Exception); **der Cancel des Aufrufers bleibt `CancelledException` und gewinnt** (`throwIfCallerRequested()` rekursiv über verschachtelte Deadlines). Timer und Subscription setzt `arm()` im Fiber der Operation und `close()` entfernt sie in `finally`. Der synchrone Transport kennt die Deadline als Cancellation-Instanz: `look()` statt `throwIfRequested()`, Connect/Select/Read-Wartezeiten höchstens `remaining()`, die Antwort mit Startfrist und Höchstdauer; was er nicht unterbrechen kann (Producer, der nicht zurückkehrt, blockierendes Datei-I/O – B09/AP-14), prüft er beim Zurückkehren. Ein fremder innerer Client, der seine Cancellation ignoriert, wird abgewartet. `SocketOpener::open()` ist der eine Weg zu einer Verbindung (Transport, Pool, NullPool): TCP in `socketTimeout`, dann `setupTls()` in `tlsHandshakeTimeout`, nie `connectTls()`.
- **Dateiquellen werden geöffnet, am Handle vermessen, bis zum bestätigten EOF gelesen und geschlossen.** `scanFile()` und `scanFileWithPreview()` öffnen die Datei erst innerhalb des Futures (ein vorab gesetzter Cancel öffnet nichts), nehmen die Größe per `fstat()` vom offenen Handle (nie `filesize()`, nie ersatzweise 0) und schließen den eigenen Handle in `finally`, auch bei gehaltenen Fehler-Futures. Ein per `HttpResponse::$body` übergebener Stream bleibt Sache des Aufrufers; der Client liest ihn nur. `ChunkedBodyEncoder` schreibt Abschlusschunk und `ieof` nur nach bestätigtem EOF: Lesefehler, leerer Read vor dem Ende und ein nötiger, aber fehlgeschlagener Rewind werfen `IcapStorageException`. Ein Stream-Body, dessen Nachricht eine einzelne `Content-Length` ankündigt, muss genau so viele Bytes liefern (`RequestFormatter::announcedLength()` → `encode(…, $length)`, im Strict-Rest `encodeRemainderFromStream($stream, $length)`); Unter- und Überlänge werfen vor dem Abschlusschunk, ohne Bytes über die Ankündigung hinaus zu senden. Ausgenommen ist nur ein Teil-Preview (`Preview`-Header ohne `ieof`): sein Stream ist der Anfang des Bodys, die `Content-Length` beschreibt den ganzen (`isPartialPreview()`). Das Preview ist 0..1 MiB (`IcapClient::MAX_PREVIEW_SIZE`), `Preview: 0` ist gültig (Body-Abschnitt mit `0\r\n\r\n`, nie `null-body`), gecachte `Preview`-Werte gelten erst nach Dezimalprüfung vor dem Cast. Die unveränderliche Objektbindung (gleich große Inhaltsänderung) bleibt AP-14.
- **Header-/URI-Injection wird abgewiesen, bevor irgendein Byte den Socket erreicht.** `RequestFormatter::format()` prüft jeden Request, bevor der erste Chunk entsteht: ICAP-Methode, URI (`IcapUri::authority()`), ICAP- und gekapselte HTTP-Header (`HeaderFields::validated()`) sowie HTTP-Request- und Statuszeile. Service-URI und Socket-Adresse baut `IcapUri` (`forService()`, `tcp()`) aus getrennt geprüftem Host, Port und Service-Pfad; die Scan-Helfer prüfen `extraHeaders` schon vor dem Öffnen der Datei (`IcapClient::validateIcapHeaders()`). Verwaltete Header erkennt der Client in jeder Schreibweise und verwirft Caller-Werte dafür: `Host` und `Encapsulated` setzt immer der Formatter; `Preview` und `Allow: 204` setzt `IcapClient` im Preview-Request (`mergeHeaders()`), der Vollrequest nach `100 Continue` trägt keinen der beiden. `scanFile()` sendet ohne Preview-Handshake und verwirft nur `Preview`; `Allow` und die übrigen `extraHeaders` gehen mit.
- **OPTIONS-Cache-Keys sind 64-stellige Hex-Hashes (`OptionsCacheKey`, M12).** Der Client nennt einen Eintrag nach Host, Port und Service (`IcapClient::optionsCacheKey()`, in `options()` und `resolvePreviewSize()` derselbe Key). `Psr6OptionsCache` und `Psr16OptionsCache` bilden jeden Backend-Key (Eintrag, ISTag, Registry der getrackten Keys) aus Präfix, Rolle und Caller-Key, jeden Teil mit seiner Länge im Hash-Input, nie als Präfix vor einem fertigen Hash: Host, Servicepfad oder Präfix können die PSR-Regeln (`A-Za-z0-9_.`, höchstens 64 Zeichen, `{}()/\@:` reserviert) nicht mehr verletzen, und ein Caller-Key kann keinen Meta-Eintrag treffen. Alte Keys werden weder gelesen noch gelöscht (kalter Cache). Der Key enthält keine TLS-/Tenant-Identität; die bleibt AP-17.
- **DTOs und `Config` sind immutable** (`final readonly class` / `readonly`-Properties). Mutatoren liefern neue Instanzen (`Config::withTlsContext`, `withVirusFoundHeaders`, `withLimits`). Keine Setter ergänzen. `Config` prüft alle Werte im Konstruktor (N01), Wither und `IcapClient::forServer()` laufen durch ihn: Host nach den Regeln von `IcapUri` (ohne DNS), Port 1..65535, `socketTimeout` endlich und >0, `streamTimeout` endlich und >=0 (0 ist die Sofortfrist der Transporte, kein Warten ohne Grenze), `operationTimeout` (Default 60), `tlsHandshakeTimeout` und `responseStartTimeout` (beide Fallback `streamTimeout`) endlich und >0 (`withTimeouts()`), Limits >=1, Virus-Header eine nicht leere Liste von Header-Token. Ungültiges ist `\InvalidArgumentException`, nie ein Fehler erst beim ersten Request.
- **`RequestFormatter::format()` liefert ein iterierbares Chunk-Array.** Encapsulated-Bodies werden als HTTP/1.1-Chunks gestreamt; Preview-Requests, die bereits den vollständigen Payload tragen, terminieren mit `0; ieof\r\n\r\n`. Bodies nicht in einen einzigen String puffern.
- **Eine Antwort endet dort, wo ihr Layout es sagt (`ResponseDecoder`, B02/B03/M03).** `ResponseFrameReader` (Transporte) und `ResponseParser` lesen mit demselben internen Decoder, also gilt für beide dieselbe Entscheidung: Kopf und Header mit CRLF (ein einzelnes CR oder LF ist ungültig), `Encapsulated` als eine der Antwortformen aus RFC 3507 §4.4.1 (`[req-hdr] req-body`, `[res-hdr] res-body`, `opt-body`, jeweils auch mit `null-body`; Request-Layouts wie `req-hdr`+`res-hdr` sind als Antwort ungültig), Headersegmente exakt so lang wie angegeben, Body als Chunks (Hex-Größe, Extensions, genau so viele Bytes, CRLF, Abschlusschunk, Trailer). Terminator oder scheinbare ICAP-Antwort im Payload sind Nutzbytes. Zahlen werden Ziffer für Ziffer gegen das Größenlimit geprüft, nie gecastet. Finale Antworten brauchen `Encapsulated`; nur 100, 204 und 4xx/5xx dürfen ohne enden, und 100/204 tragen keine Nachricht (Layout höchstens `null-body=0`). Headerzeilen (Kopf und Headersegmente) werden vor trim/Unfold geprüft: Name ist ein Token, Wert ohne Steuerzeichen. Alles andere ist sofort `IcapMalformedResponseException`, ohne Teilresultat. Der Decoder behält seinen Cursor (jedes Byte konstant oft angefasst, `workedBytes()`), und `maxResponseSize`, `maxHeaderCount`, `maxHeaderLineLength` gelten für jeden Zeilenblock (Kopf, Headersegmente, Chunkzeilen, Trailer). Wer den Parser per Hand instanziiert, übergibt die Header-Limits aus derselben `Config`, die der Transport sieht. **Bytes hinter einer Antwort gehören der Session** (`AmpTransportSession` hält den Reader): ein `write()` darauf wirft und schließt, `release()` schließt statt zu poolen, `ResponseParser::parse()` verweigert sie.
- **Vendor-Virus-Header sind eine geordnete Liste.** `Config::getVirusFoundHeaders()` liefert `list<string>`, `IcapClient::extractVirusName()` gibt den ersten vorhandenen zurück. Neue Vendor-Header in die Liste aufnehmen, nicht per Sonderfall behandeln.

Öffentliche Einstiegspunkte:

- `IcapClient::create()` / `IcapClient::forServer($host)` — Async/Sync-Convenience-Factories mit Default-Verdrahtung. Gut für Beispiele; produktiver Code konstruiert `IcapClient` explizit mit getunter `Config` und `LoggerInterface`.
- `SynchronousIcapClient::create()` — dünner `await()`-Wrapper um `IcapClient`; existiert, damit Framework-lose Aufrufer `Revolt\EventLoop` nicht selbst kennen müssen.
- `RetryingIcapClient` — Decorator (keine Subklasse), wiederholt **nur `options()`** nach 5xx, mit Exponential-Backoff und Full Jitter (N03); `request()`, `scanFile()`, `scanFileWithPreview()` bekommen einen Versuch. Einen `IcapClient` einwickeln, nicht ableiten.

## Konventionen

- **PHP 8.4 Minimum** (CI fährt 8.4 + 8.5). PHPStan Level 9 + bleedingEdge, keine Baseline — Issues fixen, nicht unterdrücken. Überall `final class`, `readonly` wo Daten unveränderlich sind.
- **`#[\Override]`** auf jeder Interface-Implementierung. PHPStan flagt fehlende Annotationen.
- **EUPL-1.2-Dateiheader** ist Pflicht in jeder PHP-Datei in `src/` und `tests/`. `composer cs-fix` setzt ihn aus `.php-cs-fixer.dist.php` neu — den Header-Block nicht von Hand editieren.
- **PSR-4-Namespace** `Ndrstmr\Icap\` (src) / `Ndrstmr\Icap\Tests\` (tests). PSR-12-Coding-Style wird von php-cs-fixer durchgesetzt.
- **Tests laufen unter Pest 3** (PHPUnit 11 darunter). Mockery steht für Transport-Doubles bereit. Testdateien liegen neben der Schicht, die sie prüfen: `tests/Wire/` für Formatter/Parser, `tests/Transport/` für Transports, `tests/Security/` für Fail-Secure- und DoS-Limit-Invarianten, `tests/Integration/` für Smoke-Tests gegen echte Server. Diese Aufteilung beim Hinzufügen neuer Tests beibehalten.
- **Logging ist PSR-3, strukturiert und datensparsam (M16).** `IcapClient::request()` und `options()` schreiben `info` beim Start und Abschluss sowie `warning` bei Fehlschlag, die Messages sind fest. Der Kontext besteht nur aus `method` (`OPTIONS`, `REQMOD`, `RESPMOD`, sonst `other`), `port`, `statusCode`, `infected` und im Fehlerfall `exception_class` (`logContext()`, `logFailure()`); nie URI, Host, Service- oder Dateipfad, Header-/Bodywerte, Exception-Message oder das Throwable selbst. `ResponseParser` und `HeaderFields::parse()` betten keine empfangene Status- oder Headerzeile in Fehlermeldungen ein. Neue Log-Stellen und Fehlermeldungen halten sich daran.

## Commits & Pull Requests

Diese Repo folgt **strikt** [Conventional Commits 1.0](https://www.conventionalcommits.org/de/v1.0.0/). Bestehende Historie zeigt das Muster (`docs:`, `feat(v2.1):`, `docs(review):`) — neue Commits halten dieses Schema.

### Commit-Format

```
<type>(<scope>): <subject>

<body — Pflicht, keine reinen Subject-Commits>

<footer — optional: BREAKING CHANGE, Refs, Closes>
```

- **Types:** `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`. Eigene Types nicht erfinden.
- **Scope:** wenn sinnvoll, in Klammern. Übliche Scopes hier: `transport`, `wire`, `preview`, `pool`, `cache`, `security`, `review`, `v2.1`, `v2.2` … oder ein Klassenname (`IcapClient`).
- **Subject:** Imperativ, kleinbuchstaben, kein Punkt am Ende, max. ~70 Zeichen.
- **Body:** Pflicht. Erklärt **warum**, nicht **was** — der Diff zeigt das Was. Bei Items aus `docs/review/review_v2-1/consolidated_v2.1_task-list.md` die Tabellenzeile + Reviewer-Quelle nennen.
- **Footer:**
  - `BREAKING CHANGE: <Beschreibung>` für jeden BC-Break (gehören in den v3.0.0-Bucket, nicht in Patch/Minor).
  - `Closes #<issue>` wenn der Commit/PR das Issue schließen soll. `Refs #<issue>` nur für Verweise ohne Schließen. **Wichtig:** GitHub Auto-Close braucht exakt `Closes #XX` / `Fixes #XX` / `Resolves #XX` — `Refs` verlinkt nur, schließt nicht. Bei mehreren Issues jedes einzeln auflisten (`Closes #53`, `Closes #54`), nicht komma-separiert.

### AI-Attribution in Commits / PRs

Für die **v3-Linie und alles danach** ist die AI-Attribution explizit erwünscht. Die Repo-Politik dazu wurde vom Maintainer am 01.05.2026 umgestellt: nicht mehr verstecken, sondern transparent dokumentieren, wer welchen Commit geschrieben hat.

**Erlaubt und erwünscht:**

- Genau **ein** `Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>` (oder das jeweils aktuelle Modell) als Trailer auf Commits, die mit Claude-Code-Hilfe entstanden sind. Mehr nicht — das ist die Provenance.

**Nicht erlaubt:**

- **Kein `🤖 Generated with [Claude Code]`-Hinweis** oder andere Generator-Werbe-Footer im Commit-Body. Der `Co-Authored-By`-Trailer reicht — alles darüber hinaus ist Werbung.
- **Niemals einen `Co-authored-by`-Trailer auf den Repo-Maintainer setzen.** Der Maintainer ist sowieso der Git-`Author` jedes Commits — ein Co-Author-Trailer für ihn ist redundant und falsch.
- Keine anderen Personen als Co-Author setzen, ohne dass diese tatsächlich am Commit mitgearbeitet haben.

### Sonstige Commit-/PR-Regeln

- **Keine `--no-verify`** — wenn pre-commit-Hooks fehlschlagen, Root-Cause fixen und neuen Commit anlegen (nicht amend).

### Pull Requests

- **PR-Titel** ist eine Conventional-Commit-Subject-Zeile (≤ 70 Zeichen). Kein `[WIP]`-Präfix; Drafts sind GitHub-nativ.
- **PR-Body** mit zwei Sektionen:
  - `## Summary` — 1–3 Bullets, was sich ändert und warum.
  - `## Test plan` — Checkliste, wie der Reviewer das Verhalten prüft (`composer test`, einzelner Pest-Filter, Integration-Run gegen Docker, manueller Eicar-Probe-Lauf, …).
- **Verweise auf Roadmap-Items** aus `docs/review/review_v2-1/consolidated_v2.1_task-list.md` mit Item-Nummer und Reviewer-Quellen-Tabelle, wenn der PR ein gelistetes Finding schließt.
- **CI muss grün sein** vor Merge: `cs-check`, `stan`, `test`, `audit`. Integration-Tests sind aktuell `continue-on-error: true` (Item #17 der v2.2-Liste hebt das); ein roter Integration-Step blockiert den Merge **noch nicht**, sollte aber im PR adressiert werden.

### CHANGELOG-Eintrag

Jeder funktionale PR (`feat`, `fix`, `perf`, `refactor` mit User-sichtbarer Wirkung) ergänzt einen Bullet im `[Unreleased]`-Block der `CHANGELOG.md` (Keep-a-Changelog-Format). `docs:`, `test:`, `ci:`, `chore:` brauchen das nicht.

## Roadmap & Reviews — historischer Kontext

Maßgeblich sind der aktuelle Nutzerauftrag und der dafür bestätigte Plan. `docs/review/review_v2-1/consolidated_v2.1_task-list.md` und die folgenden Releasepläne sind historischer Kontext; sie erweitern den Auftrag nicht. Findings vor einer Änderung gegen den aktuellen Code und CHANGELOG prüfen.

Vier unabhängige Audits unter `docs/review/review_v2-1/`:

- `claude_deep-research-audit-icap-flow-v2.1.0.md`
- `codex_deep-research-v2.1.0-audit.md`
- `codex_v2.1.0-production-readiness-audit-2026-04-25.md`
- `jules_deep_research_report_2-1.md`

### Historischer Release-Plan aus der v2.1-Task-Liste

- **v2.1.1 — Critical Hotfix** (kein BC-Break, höchste Priorität):
  - `AmpConnectionPool::key()` um TLS-Context-Fingerprint erweitern (Cross-Tenant-Leakage, CVE-würdig im Multi-Tenant-Deployment) — `src/Transport/AmpConnectionPool.php:130-133`.
  - `DefaultPreviewStrategy` für 200/206 erweitern: Virus-Header → `ABORT_INFECTED`, sonst `ABORT_CLEAN`. Der Branch in `IcapClient.php:388` ist heute unerreichbar — Virus-Treffer im Preview kommen als uncatchable Exception zurück.
  - `SECURITY.md:73-75` von Stale-Claims befreien (Cache/Pool/Retry sind seit v2.0/2.1 implementiert).
  - Kleine Doku-Korrekturen in Cookbook + `ConnectionPoolInterface`-Phpdoc.
- **v2.1.2 — CI-Quality-Patch** (kein BC-Break): Strict-§4.5-Streaming-Fix (`stream_get_contents()` durch `ChunkedBodyEncoder::encodeRemainderFromStream()` ersetzen) plus `failOnRisky=true` im Phpunit-Setup und PHPStan-Memory-Limit-Stabilisierung.
- **v2.2.0 — Minor, additiv:** OPTIONS-getriebenes Preview-/Pool-Tuning, `NullConnectionPool`, Mutation-Tests als Required-CI, Coverage-Push (`AmpConnectionPool` 54 → ≥90 %, `SynchronousStreamTransport` 41 → ≥85 %), 4 zusätzliche Cookbook-Beispiele, OpenTelemetry-Decorator, PHPBench-Suite.
- **v2.3.0** — separates Repo `ndrstmr/icap-flow-bundle` (Symfony-Bundle), nicht in dieser Repo.
- **v3.0.0** — gesammelte BC-Breaks: `executeRaw()` `protected`/Interface, `options()` zu `Future<IcapResponse>`, `IcapResponseException` entfernen.

Konkrete Korrekturen aus der konsolidierten Liste, die beim Code-Lesen auffallen können:

- **Jules' Befund zur Header-Array-Validierung ist faktisch falsch** — `HeaderFields::validated()` prüft jeden Eintrag eines Array-Werts einzeln, in `RequestFormatter::format()` und über `IcapClient::validateIcapHeaders()` in den Scan-Helfern. Diesen Punkt **nicht** als offen behandeln.
- Der Strict-§4.5-Streaming-Bug ist von 3 von 4 Reviewern erfasst (Jules nicht).

### Historische Reviews

`docs/review/` enthält unter anderem die drei v1-Audits und deren `consolidated_task-list.md`, abgeschlossen mit dem v2.0-Release. Auch die v2.1-Listen sind historische Provenienz; für laufende Arbeit gilt der aktuell bestätigte Auftrag.

`docs/migration-v1-to-v2.md` dokumentiert jeden v1 → v2 Break. `CHANGELOG.md` folgt Keep-a-Changelog und SemVer ist committed. v1 ist deprecated; dort keine neuen Codepfade einbauen.
