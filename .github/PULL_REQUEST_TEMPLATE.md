<!--
PR-Body folgt dem in dieser Repo etablierten Stil
(siehe z. B. PR #48, #49). Sektionen weglassen, die nicht passen.
Sprache: Englisch (öffentliche Library-Doku). Conventional-Commit-Subject im PR-Titel.
-->

## Context

<!--
Was wird gelöst und warum? Hintergrund, Motivation, Bezug zu vorigen PRs.
Bei Items aus docs/review/review_v2-1/consolidated_v2.1_task-list.md die Item-Nummer + Reviewer-Quelle nennen
(z. B. "v2.1.1 #2, 4/4 reviewers — cross-tenant TLS leakage in AmpConnectionPool").
-->

## API

<!--
Neue oder geänderte Public-API (Klassen, Interfaces, Methoden, Konstruktor-Parameter).
"none" wenn rein interne Änderung. BC-Breaks IMMER hier kennzeichnen.
-->

## Behaviour

<!--
Was beobachtet der Anwender anders? Branches, Status-Code-Pfade, Fail-Secure-Wirkung,
Pool/Session-Lifecycle, Wire-Format-Auswirkungen.
-->

## Refactoring

<!--
Interne Umbauten ohne Verhaltensänderung (Extract-Class, Encoder-Split, Parser-Cleanup, …).
"none" wenn nicht relevant.
-->

## Tests

<!--
Welche Tests dazu gekommen sind, in welcher Suite (Wire/Transport/Security/Integration/…),
welche Edge-Cases sie absichern, ob Mutation-Score betroffen ist.
-->

## Verification

- [ ] `composer cs-check`
- [ ] `composer stan` — PHPStan Level 9 + bleedingEdge clean
- [ ] `composer test` — Pest Unit-Suite grün
- [ ] `composer test:integration` — gegen `docker compose up -d` (falls relevant)
- [ ] CI-Matrix (PHP 8.4 + 8.5)

## Status

<!--
Was passiert nach Merge? Tag-Pflege, Folge-PR, Roadmap-Item geschlossen, Release-Notes.
-->
