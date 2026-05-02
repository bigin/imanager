# iManager 2.0 — Architektur- & Umsetzungsplan

> Master-Plan für den Umbau von iManager auf eine moderne, SQLite-gestützte
> Architektur. Begleitend zu `imanager-analysis.md`.
>
> Stand: 2026-05-01 · Ziel-Major: **2.0**

---

## 1. Vision

iManager 2.0 ist ein **moderner, embeddable PHP-CMF-Kern** mit:

- SQLite + JSON-Spalten als einzigem First-Class-Storage (mit Generated
  Columns für indizierbare Felder, FTS5 für Volltextsuche).
- Voll typisiertem PHP 8.2+-Code, PSR-4-Autoloading, Composer-managed.
- Klarer Schichtentrennung (Domain ↔ Storage ↔ HTTP ↔ Field-Plugins).
- Einer Repository/Query-Builder-API + rückwärtskompatibler Selector-DSL.
- Volltextsuche, FK-Constraints, ACID-Transaktionen, WAL-Concurrency.
- Test-Suite (PHPUnit + PHPStan + Psalm + CI).
- CLI-Werkzeug `imanager` (migrate, export, import, repair, fts:rebuild).
- Migration-Tool aus iManager 1.x Flat-Files (read-only Adapter).
- Saubere Docker-Erfahrung (PHP-FPM + nginx Beispielsetup).

iManager 2.0 ist eine **Major-Version mit Breaking Changes** und keinem
In-place-Upgrade-Pfad – Migration läuft über ein dediziertes Tool.

---

## 2. Leitprinzipien

1. **Komposition vor Vererbung.** Item ist kein FieldMapper.
2. **Keine globalen Singletons.** Container-managed Services, per Constructor injiziert.
3. **Strict types überall.** `declare(strict_types=1)` in jeder Datei.
4. **Fail-fast mit typisierten Exceptions.** Nie wieder `exit()` aus Util-Code.
5. **Eingaben validieren, intern vertrauen.** Sanitizer nur an Systemgrenzen.
6. **Atomarität und Transaktionalität** sind Storage-Verantwortung, nicht App-Verantwortung.
7. **Tests sind nicht optional.** Jede neue Komponente kommt mit Unit- und Integration-Tests.
8. **Dynamic Schema bleibt User-Feature.** Felder hinzufügen darf nie DDL erfordern.
9. **Backward-Compat im Migrations-Tool, nicht im Laufzeit-Code.** Wir schleppen keine Altlasten mit.
10. **Eine Wahrheit pro Domain-Konzept.** Field-Typ-Definition liegt nicht parallel in Editor-UI, Sanitizer, Input-Klasse, Field-Klasse.

---

## 3. Repo-, Branching- & Release-Strategie

**iManager 2.0 wird in einem eigenen Repo entwickelt** als standalone
Composer-Library `bigins/imanager`. Das Scriptor-Repo bleibt unverändert,
bis Phase 14 (Admin/Editor-Anpassung) – dann zieht Scriptor `bigins/imanager`
als Composer-Dependency und das eingebettete `imanager/`-Verzeichnis
entfällt.

- **Neues Repo:** `bigins/imanager` (GitHub), Composer-Paket `bigins/imanager`.
- **Branches im neuen Repo:**
  - `main` als Default-Branch (entwicklungsaktiv).
  - Pro Phase ein Feature-Branch `phase-N-<thema>` → PR auf `main`.
- **Scriptor-Repo:** `master` wird **eingefroren** als 1.x-Linie. Keine
  1.13.x-Wartungsrelease — Bestand ist minimal, Aufwand lohnt nicht.
- Vor 2.0-Release: Tags `2.0.0-rc.1`, `rc.2`, …, dann `2.0.0`.
- Phase 14 öffnet einen Branch `imanager-2.0` im **Scriptor**-Repo, der
  Scriptor's `editor/`-Module auf die neue iManager-API umstellt.

---

## 4. Ziel-Tech-Stack

| Layer | Wahl |
|---|---|
| PHP | 8.2 minimum (8.3 empfohlen) |
| Autoloading | Composer PSR-4 |
| DB | SQLite ≥ 3.38 (für JSON1 + Generated Columns + FTS5) |
| DB-Zugriff | PDO direkt (kein Doctrine, kein DBAL — Komplexität nicht nötig) |
| DI | `league/container` |
| CLI | `symfony/console` |
| Markdown | `erusev/parsedown` (ersetzt PW-Reste) |
| HTML-Sanitize | `ezyang/htmlpurifier` (ersetzt PW-Reste) |
| Tests | PHPUnit 10+ |
| Static Analysis | PHPStan Level 8 + Psalm Level 3 |
| Code-Style | PHP-CS-Fixer (PSR-12 + PER) |
| CI | GitHub Actions (PHP 8.2/8.3 Matrix, Linux + Mac) |
| Container | offizielles `php:8.3-fpm-alpine` + nginx |

---

## 5. Ziel-Verzeichnisstruktur (iManager 2.0 — standalone Repo)

Die Wurzel des neuen `bigins/imanager`-Repos sieht so aus:

```
.
├── bin/
│   └── imanager                    # CLI entry
├── config/
│   ├── default.php                 # Default config (typed)
│   └── schema/
│       ├── 0001_initial.sql
│       ├── 0002_fts.sql
│       └── ...
├── src/
│   ├── Bootstrap.php
│   ├── Container.php               # DI factory
│   ├── Domain/
│   │   ├── Category.php
│   │   ├── Field.php
│   │   ├── FieldDefinition.php
│   │   └── Item.php
│   ├── Storage/
│   │   ├── Storage.php             # interface
│   │   ├── CategoryRepository.php
│   │   ├── FieldRepository.php
│   │   ├── ItemRepository.php
│   │   └── Sqlite/
│   │       ├── SqliteStorage.php
│   │       ├── SqliteCategoryRepository.php
│   │       ├── SqliteFieldRepository.php
│   │       ├── SqliteItemRepository.php
│   │       ├── SchemaManager.php
│   │       └── ConnectionFactory.php
│   ├── Migration/
│   │   └── JsonV1Importer.php      # read-only Adapter für 1.x flat-files
│   ├── Query/
│   │   ├── QueryBuilder.php
│   │   ├── SelectorParser.php      # Mini-DSL als Adapter
│   │   └── Pagination.php
│   ├── Field/
│   │   ├── FieldType.php           # interface
│   │   ├── FieldTypeRegistry.php
│   │   ├── ValidationResult.php
│   │   ├── Renderer.php
│   │   └── Types/
│   │       ├── TextField.php
│   │       ├── LongTextField.php
│   │       ├── DropdownField.php
│   │       ├── ...
│   ├── Validation/
│   │   ├── Sanitizer.php           # neu, ohne PW-Reste
│   │   └── Rules/
│   ├── Http/
│   │   ├── Request.php
│   │   ├── UrlSegments.php
│   │   └── Csrf.php
│   ├── Search/
│   │   └── FullTextSearch.php
│   ├── Cache/
│   │   └── SectionCache.php        # PSR-16-konform
│   ├── Templating/
│   │   └── TemplateParser.php
│   ├── Cli/
│   │   ├── Application.php
│   │   └── Command/
│   │       ├── MigrateCommand.php
│   │       ├── ExportCommand.php
│   │       ├── ImportCommand.php
│   │       ├── DumpCommand.php
│   │       ├── RepairCommand.php
│   │       └── FtsRebuildCommand.php
│   └── Exception/
│       ├── ImanagerException.php
│       ├── StorageException.php
│       ├── ValidationException.php
│       ├── SchemaException.php
│       └── NotFoundException.php
├── tests/
│   ├── Unit/
│   ├── Integration/
│   └── Fixtures/
├── composer.json
├── phpunit.xml
├── phpstan.neon
├── psalm.xml
└── .php-cs-fixer.php
```

Das alte eingebettete `Scriptor/imanager/` bleibt während der gesamten
Entwicklung **unangetastet**. Der Switchover passiert in Phase 14, wenn
Scriptor `bigins/imanager` per Composer einbindet und das alte Verzeichnis
gelöscht wird.

---

## 6. SQLite-Schema (initial design)

```sql
PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;
PRAGMA synchronous = NORMAL;
PRAGMA temp_store = MEMORY;

CREATE TABLE schema_version (
  version    INTEGER PRIMARY KEY,
  applied_at INTEGER NOT NULL
);

CREATE TABLE categories (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  name      TEXT    NOT NULL UNIQUE,
  slug      TEXT    NOT NULL UNIQUE,
  position  INTEGER NOT NULL DEFAULT 0,
  created   INTEGER NOT NULL,
  updated   INTEGER NOT NULL
);
CREATE INDEX idx_categories_position ON categories(position);

CREATE TABLE fields (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
  name        TEXT    NOT NULL,
  label       TEXT,
  type        TEXT    NOT NULL,
  position    INTEGER NOT NULL DEFAULT 0,
  required    INTEGER NOT NULL DEFAULT 0,
  indexed     INTEGER NOT NULL DEFAULT 0,        -- soll Generated Column + Index erzeugt werden?
  searchable  INTEGER NOT NULL DEFAULT 0,        -- Teil von FTS?
  config      TEXT    NOT NULL DEFAULT '{}'
              CHECK(json_valid(config)),
  created     INTEGER NOT NULL,
  updated     INTEGER NOT NULL,
  UNIQUE(category_id, name)
);
CREATE INDEX idx_fields_category ON fields(category_id, position);

CREATE TABLE items (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
  name        TEXT,
  label       TEXT,
  position    INTEGER NOT NULL DEFAULT 0,
  active      INTEGER NOT NULL DEFAULT 1,
  data        TEXT    NOT NULL DEFAULT '{}'
              CHECK(json_valid(data)),
  created     INTEGER NOT NULL,
  updated     INTEGER NOT NULL
);
CREATE INDEX idx_items_cat_pos    ON items(category_id, position);
CREATE INDEX idx_items_cat_active ON items(category_id, active);

-- FTS5 (kommt in Migration 0002)
CREATE VIRTUAL TABLE items_fts USING fts5(
  name, label, body,
  content='items', content_rowid='id',
  tokenize='unicode61 remove_diacritics 2'
);
```

**Generated Columns für indexierte Felder** werden zur Laufzeit angelegt,
wenn der User ein Feld als „indexierbar" markiert:

```sql
ALTER TABLE items
  ADD COLUMN gen_<fieldname> <SQLITE_TYPE>
  GENERATED ALWAYS AS (json_extract(data, '$.<fieldname>')) VIRTUAL;
CREATE INDEX idx_items_<fieldname>
  ON items(category_id, gen_<fieldname>);
```

Der Mapping von FieldType → SQLite-Affinity (TEXT/INTEGER/REAL) gehört
in den Field-Type-Vertrag.

---

## 7. Phasenplan

Jede Phase liefert einen **eigenständig releasbaren Zustand** (Tests grün,
keine kaputte API innerhalb der Schicht). Phasen mit identischem Tiefen-Level
können parallel laufen, sobald ihre Vorbedingungen erfüllt sind.

### Status-Tracking

| Phase | Titel | Status | Branch |
|---|---|---|---|
| 0 | Infrastruktur & CI | ✅ done | `main` (initial scaffold) |
| 1 | 1.x-Bug-Tracking (verteilt auf 2-7) | ⬜ todo | – |
| 2 | Architektur-Foundations | ✅ done | `phase-2-foundations` (PR #1, squashed → main) |
| 3 | Storage-Abstraktion | ✅ done | `phase-3-storage-iface` (PR #2, squashed → main) |
| 4 | SQLite-Implementierung | ✅ done | `phase-4-sqlite` (PR #4, squashed → main) |
| 5 | Query-Builder & Selector-DSL | ✅ done | `phase-5-query` (PR #5, squashed → main) |
| 6 | Domain-Models neu | ✅ done | `phase-6-domain` (PR #6, squashed → main) |
| 7 | Field-Type-System | 🟡 in progress (7a) | `phase-7a-sanitizer`, `phase-7b-registry`, `phase-7c-types` |
| 8 | Volltextsuche (FTS5) | ⬜ todo | `phase-8-fts` |
| 9 | Migration-Tool (1.x → 2.0) | ⬜ todo | `phase-9-migration` |
| 10 | HTTP-/Input-Layer | ⬜ todo | `phase-10-http` |
| 11 | Templates & Pagination | ⬜ todo | `phase-11-templates` |
| 12 | SectionCache PSR-16 | ⬜ todo | `phase-12-cache` |
| 13 | Upload-Modernisierung | ⬜ todo | `phase-13-uploads` |
| 14 | Admin/Editor-Anpassung | ⬜ todo | `phase-14-admin` |
| 15 | CLI-Werkzeug | ⬜ todo | `phase-15-cli` |
| 16 | Doku & Examples | ⬜ todo | `phase-16-docs` |
| 17 | 2.0-Release | ⬜ todo | `release/2.0.0` |

---

### Phase 0 — Infrastruktur & CI

**Ziel:** alles aufstellen, was wir für sauberes Arbeiten brauchen.

**Scope:**
- Neues Repo `bigins/imanager` initialisieren (lokal als sibling von Scriptor).
- `composer.json`: PSR-4 `Imanager\\` → `src/`, Paket `bigins/imanager`,
  Bin-Eintrag `bin/imanager`.
- Dev-Dependencies: phpunit, phpstan, psalm, php-cs-fixer, league/container,
  symfony/console, nikic/php-parser, erusev/parsedown, ezyang/htmlpurifier.
- Konfigfiles: `phpunit.xml`, `phpstan.neon` (Level 8), `psalm.xml` (Level 3),
  `.php-cs-fixer.php`, `.gitignore`, `.editorconfig`.
- GitHub Actions Workflow (`ci.yml`): Matrix PHP 8.2/8.3 × Linux, Schritte:
  install, lint, phpstan, psalm, phpunit.
- `Dockerfile` + `docker-compose.yml` (php-fpm 8.3-alpine + nginx) mit:
  pdo_sqlite, mbstring, gd, dom, json, opcache + System-Tools sqlite3-cli,
  composer, git, make.
- README initial.
- LICENSE (MIT, übernommen aus Scriptor).
- Smoke-Test, der CI grün fährt.

**Deliverables:**
- Grüner CI-Run auf `2.0-dev` mit leerer Test-Suite.
- `make test`, `make lint`, `make analyse` Targets oder Composer-Scripts.

**Acceptance:** PR landet in `2.0-dev`, CI grün, README aktualisiert.

---

### Phase 1 — 1.x-Bug-Tracking (verteilt)

**Status-Update:** Ursprünglich als eigenständiges `1.13.0`-Release geplant.
Da es kaum Bestandsinstallationen gibt, **eingefroren** und auf andere Phasen
verteilt — die Bugs verschwinden während der Rewrites organisch:

| Bug | Wird gefixt in Phase |
|---|---|
| B1 `fieldMappar`-Typo | Phase 4 (CategoryRepository wird neu geschrieben) |
| B2 `Item::save` Default-Bug | Phase 4 / 6 (Item-Save kommt komplett neu) |
| B3 `array_shift(explode(...))` | Phase 10 (Input/Http neu) |
| B4 `$options['removeAssets']` | Phase 4 (rebuild-Logik fällt weg) |
| B5 `getCategories` Offset tot | Phase 5 (QueryBuilder ersetzt) |
| B6 Selektor Single-Clause-Limit | Phase 5 |
| B7 Selektor `preg_quote` fehlt | Phase 5 |
| B8 Pagination `$counter`-Edge | Phase 11 (Pagination wird neu) |
| `wire()`-Reste im Sanitizer | Phase 7 (Field-Type-System / Sanitizer-Rewrite) |
| `Util::redirect` CRLF | Phase 10 |
| `Util::logException` `exit()` | Phase 2 (Exception-Hierarchie) |
| Atomares Schreiben | Phase 4 (Storage-Layer) |
| flock-Konsistenz | Phase 4 (Transaktionen ersetzen flock) |

**Acceptance:** für jeden Bug einen Regression-Test in der jeweiligen Phase,
der die alte Pathologie reproduziert (gegen die neue Implementierung) und
grün ist.

---

### Phase 2 — Architektur-Foundations

**Ziel:** das Fundament im 2.0-Branch legen, auf dem alles folgt.

**Scope:**
- Exception-Hierarchie:
  ```
  ImanagerException (abstract)
   ├── StorageException
   ├── ValidationException
   ├── SchemaException
   ├── NotFoundException
   └── ConfigException
  ```
- DI-Container: `league/container` (entschieden — explizit über magisch).
- `Bootstrap`-Klasse: liest Config, baut Container, gibt fertigen Container zurück.
- `Config`-Klasse: typed properties, immutable, Builder-Pattern für Overrides.
- Enum: `FieldType` (text, longtext, dropdown, …), `InputErrorCode`.
- Logging via PSR-3 (Adapter, Default `monolog/monolog`).
- Erste leere Test-Klassen, die den Container und Bootstrap abdecken.

**Deliverables:**
- `src/Bootstrap.php`, `src/Container.php`, `src/Exception/*`.
- `Config` mit Tests (Override-Reihenfolge default → user-config).
- PHPStan Level 8 grün auf dem neuen Code.

**Acceptance:** `Imanager\Bootstrap::boot($configArray)` liefert einen
funktionsfähigen Container; alle Tests grün.

---

### Phase 3 — Storage-Abstraktion

**Ziel:** klare Trennung Domain ↔ Persistenz, ohne dass schon eine echte
Implementierung existiert.

**Scope:**
- `Storage`-Interface (atomare Transaktion, Connection-Lifecycle).
- Repository-Interfaces: `CategoryRepository`, `FieldRepository`, `ItemRepository`.
- Methodensignaturen mit Domain-Models (Phase 6 wird sie konkretisieren – hier
  reichen vorerst lose typisierte Skelette oder Anemic-DTOs).
- Schema-Migrations-Framework: `SchemaManager`, `Migration`-Interface,
  numerisch versionierte SQL-Dateien in `config/schema/`.
- Generic In-Memory-Repository für Tests (`InMemoryCategoryRepository` etc.).

**Deliverables:**
- `src/Storage/*Interface.php` (oder direkt PHP-Interfaces).
- `tests/Unit/Storage/InMemoryRepositoryTest.php`.

**Acceptance:** Repository-Verträge sind getestet; In-Memory-Variante erfüllt
denselben Test-Suite-Kontrakt wie die SQLite-Variante (Phase 4).

---

### Phase 4 — SQLite-Implementierung

**Ziel:** Storage-Interface produktionsreif gegen SQLite.

**Scope:**
- `ConnectionFactory`: PDO-Connection mit den Pragmas aus §6.
- `SqliteStorage`: Transaktions-Wrapper (`transactional(callable)`).
- `SqliteCategoryRepository`, `SqliteFieldRepository`, `SqliteItemRepository`:
  CRUD + atomare Saves.
- `SchemaManager` führt Migrationen idempotent aus, schreibt `schema_version`.
- Konflikt-Handling: `UNIQUE`-Verstöße → `ValidationException`.
- Generated-Column-Lifecycle:
  - Field als `indexed` markieren → `SchemaManager::indexField($categoryId, $fieldName, $type)`.
  - Field unindexen → Index + Generated Column droppen.
  - Field umbenennen → Index/Generated Column droppen + neu anlegen.
- Item-`data`-JSON-Serialisierung über `JsonSerializer`-Service (zentrale Stelle).
- Integration-Tests gegen ein temporäres SQLite-File pro Test-Run.

**Deliverables:**
- `src/Storage/Sqlite/*`.
- `config/schema/0001_initial.sql`.
- Test-Suite `tests/Integration/Storage/Sqlite/*`.

**Acceptance:** Repository-Test-Suite (aus Phase 3) läuft grün gegen SQLite;
50k-Item-Benchmark zeigt erträgliche Performance (`getItem` < 1 ms,
`getItems` mit Index < 50 ms).

---

### Phase 5 — Query-Builder & Selector-DSL

**Ziel:** einheitliche Abfrage-API, die sowohl typisiert als auch via
String-Selektor benutzt werden kann.

**Scope:**
- `QueryBuilder`-API (fluent):
  ```php
  $items = $catalog->category('blog')
      ->items()
      ->where('position', '>=', 3)
      ->whereJson('tags', 'contains', 'php')
      ->orderBy('created', 'desc')
      ->paginate($pageNumber, perPage: 20);
  ```
- `SelectorParser` als Adapter: parst `'name=foo, position>=3, tags=%php%'`
  in `QueryBuilder`-Aufrufe (Multi-Clause AND, escaped via `preg_quote`).
- Unterstützte Operatoren: `=`, `!=`, `<`, `<=`, `>`, `>=`, `LIKE` (`%...%`).
- JSON-Path-Where-Helpers für nicht-indexierte Felder.
- `Pagination`-Value-Object (page, perPage, total, lastPage).

**Deliverables:**
- `src/Query/*`.
- `tests/Unit/Query/*` mit kompletter Operator-Matrix.

**Acceptance:** Selector-Strings, die in 1.x funktioniert haben, liefern
identische Ergebnisse über die 2.0-API – plus echte Multi-Clause-Selectoren.

---

### Phase 6 — Domain-Models neu

**Ziel:** Item/Field/Category als saubere PHP-Objekte – Komposition,
keine globalen Zugriffe.

**Scope:**
- `Category`, `Field`, `FieldDefinition`, `Item` als immutable-ish Value-/
  Aggregate-Objekte mit promoted constructor properties.
- Kein `extends FieldMapper` mehr für `Item`.
- Kein `imanager()`-Aufruf in Domain-Klassen — alles per DI.
- ID-Value-Objects: `CategoryId`, `FieldId`, `ItemId` (typisierte int-Wrapper),
  zumindest für API-Klarheit. (Optional, kann am Ende entschieden werden.)
- `Item->data` als typisiertes `FieldValueBag` mit `get(string $field): mixed`.
- Domain-Events (`ItemCreated`, `ItemUpdated`, `ItemDeleted`, …) für späteren
  Hook-Mechanismus.

**Deliverables:**
- `src/Domain/*`.
- Tests gegen Konstruktion, Equality, Validation.

**Acceptance:** PHPStan Level 8 grün; keine `mixed`-Returns mehr im Domain-Layer.

---

### Phase 7 — Field-Type-System

**Ziel:** das Field-Plugin-System neu aufstellen — Renderer und Validator
getrennt, Registry-basiert, typisiert.

**Scope:**
- Interface `FieldType`:
  ```php
  interface FieldType {
    public function name(): string;             // 'text', 'longtext', ...
    public function label(): string;
    public function defaultConfig(): array;
    public function sqliteAffinity(): string;   // 'TEXT'|'INTEGER'|'REAL'
    public function indexable(): bool;
    public function searchable(): bool;
    public function validator(FieldDefinition $def): Validator;
    public function renderer(FieldDefinition $def): Renderer;
  }
  ```
- `FieldTypeRegistry` (DI-managed): Built-Ins + Custom Plugin-Registrierung
  über Container-Configuration.
- Built-in-Types portieren: Text, LongText, Editor, Slug, Datepicker,
  Dropdown, Checkbox, Integer, Decimal, Money, Password, Hidden, Array,
  Filepicker, Fileupload, Imageupload.
- Sanitizer modernisieren – PW-Reste durch echte Composer-Deps ersetzen
  (`erusev/parsedown`, `ezyang/htmlpurifier`).
- Renderer-Templates kommen aus `imanager/tpls/fields/` ins neue Templating
  oder werden zu PHP-Klassen.

**Deliverables:**
- `src/Field/Types/*`.
- `src/Validation/Sanitizer.php` (clean room neu).
- Tests pro Field-Typ (Roundtrip Validate → Render → Parse).

**Acceptance:** alle 17 Built-in-Felder durch typisierte Klassen vertreten,
keine `wire()`-Aufrufe mehr im Code.

---

### Phase 8 — Volltextsuche (FTS5)

**Ziel:** Feature, das in 1.x nicht existiert: produktionsreife Volltextsuche.

**Scope:**
- Migration `0002_fts.sql` mit FTS5-Virtual-Table und Triggern für
  `items` ↔ `items_fts`-Sync.
- Konfiguration: welche Felder eines Item-Schemas gehen in FTS (`searchable=1`).
- `FullTextSearch`-Service: `search(string $query, ?CategoryId, $limit, $offset)`.
- Snippets (`snippet()`-Funktion von FTS5) und Highlighting.
- CLI: `imanager fts:rebuild` zum Neuaufbau.

**Deliverables:**
- `src/Search/FullTextSearch.php`.
- Integration-Tests gegen Beispieldatensätze.

**Acceptance:** Suche über >50k Items <100ms (Smoke-Benchmark).

---

### Phase 9 — Migration-Tool (1.x → 2.0)

**Ziel:** Bestandsdaten verlustfrei in das neue SQLite-Schema überführen.

**Scope:**
- `JsonV1Importer` (read-only): liest `data/datasets/buffers/`-Dateien aus
  iManager 1.x – per `eval`-freier Auswertung der `<?php return [...];`-
  Strukturen über einen sichereren AST-Parser (`nikic/php-parser`) **oder**
  über kontrollierten `include` in einem Sandbox-Prozess.
- Walk-through: Categories → Fields → Items, alles in einer einzigen
  SQLite-Transaktion.
- Asset-Migration: `data/uploads/` Ordnerstruktur 1:1 übernehmen.
- Validation-Layer: vor dem Import Schema validieren, Konflikte (Duplikate,
  fehlende Categories) reporten.
- Dry-Run-Mode (`--dry-run`).
- Backup-Mode: vor Migration ein vollständiges Backup von `data/` anlegen.
- CLI-Command: `imanager migrate from-v1 --source ./data --target ./data/imanager.db`.

**Deliverables:**
- `src/Migration/JsonV1Importer.php`.
- `src/Cli/Command/MigrateCommand.php`.
- Test-Fixtures aus echten 1.x-Dateien.

**Acceptance:** Migration eines Demo-Datasets ist deterministisch
reproduzierbar; Roundtrip-Test (`v1 → 2.0 → export → re-import`) liefert
identischen Datensatz.

---

### Phase 10 — HTTP-/Input-Layer

**Ziel:** den `Input`-Layer aus 1.x typisiert und testbar neu aufbauen.

**Scope:**
- `Request`-Wrapper über `$_GET`/`$_POST`/php://input mit typed Accessor.
- PSR-7 als Option **prüfen**, aber nicht zwingend einbauen — Aufwand vs.
  Nutzen abwägen, wenn wir an Phase 10 sind.
- `UrlSegments` als injizierbarer Service.
- `Csrf`-Klasse: aktuelle Token-Logik aus Scriptor übernehmen, modernisieren
  (kryptographische Konstanten, Tab-Token-Buffer typisiert).

**Deliverables:**
- `src/Http/*`.
- Tests gegen alle Request-Methoden (GET/POST/PUT/PATCH).

**Acceptance:** keine `$_SERVER`-Direktzugriffe mehr in Domain- oder
Storage-Schichten.

---

### Phase 11 — Templates & Pagination

**Ziel:** den `[[var]]`-Parser durch etwas Wartbares ersetzen.

**Scope:**
- Entscheidung: Twig integrieren **oder** den simplen Parser typisiert lassen.
  Vorschlag: simpler typisierter Parser bleibt, Twig nur wenn Field-Renderer-
  Templates komplexer werden.
- `Pagination`-Renderer als eigenständige Klasse (raus aus TemplateParser).
- Templates von `imanager/tpls/` nach `imanager/src/Templating/templates/`
  oder als PHP-Klassen-Renderer (kein Disk-IO mehr für Built-Ins).

**Deliverables:**
- `src/Templating/*`, `src/Query/Pagination.php`.

**Acceptance:** Pagination-Output identisch zu 1.x (Snapshot-Tests gegen
HTML-Output).

---

### Phase 12 — SectionCache PSR-16

**Ziel:** Cache-Layer austauschbar und PSR-konform.

**Scope:**
- PSR-16-`CacheInterface`-Implementierung über entweder:
  - SQLite (eigene `cache`-Tabelle in `imanager.db`), oder
  - File-System mit atomic write (wie heute, aber sauber).
- Markup-Cache-API (`get`/`save`/`expire`) zu PSR-16 mappen.
- Globaler Expire-Mechanismus über `Cache::clear()`.

**Deliverables:**
- `src/Cache/SectionCache.php`.
- Tests, dass Item-Save den Cache invalidiert.

**Acceptance:** Bestehende Cache-Use-Cases funktionieren über die neue API.

---

### Phase 13 — Upload-Modernisierung

**Ziel:** Blueimp jQuery raus, moderner Stack rein.

**Scope:**
- Frontend: **FilePond** oder **Uppy**. Vorschlag FilePond — leichter, weniger
  Abhängigkeiten.
- Backend: eigener Upload-Endpoint mit Validation, Type-Checking,
  Mime-Sniffing, Größenlimits, Rate-Limit.
- `phpthumb` durch **Intervention/Image** (composer-managed) ersetzen.
- Storage-Abstraktion für Uploads (lokal jetzt, S3-/Object-Storage später
  vorbereitet aber nicht gebaut).
- File-Records eventuell in eigene SQLite-Tabelle `files` (id, path, mime,
  size, item_id, field_id).

**Deliverables:**
- `src/Upload/*`.
- Frontend-JS-Bundle (klein, ohne jQuery-Abhängigkeit).
- Tests für Validierung & Mime-Sniffing.

**Acceptance:** Upload + Thumbnail + Delete funktional, kein jQuery mehr.

---

### Phase 14 — Admin/Editor-Anpassung in Scriptor

**Ziel:** Scriptor's `editor/`-Module benutzen die neue iManager-2.0-API.

**Scope:**
- Editor-Module (`pages`, `profile`, `auth`, `settings`, `install`, `parsedown`)
  Schritt für Schritt umstellen.
- Scriptor's `Site`-Klasse, `Pages`, `Users`, `User`, `Page` auf
  Repository-API umstellen.
- Scriptor `Editor`-Hooks an iManager Domain-Events anpassen.
- Reservierte Slugs / Page-Resolution überarbeiten.

**Deliverables:**
- Scriptor läuft auf 2.0 ohne 1.x-Code-Pfade.
- Editor funktional gleich oder besser.

**Acceptance:** Manuell-Test des Default-Themes, alle Editor-Module
funktional.

---

### Phase 15 — CLI-Werkzeug

**Ziel:** ein einheitliches `imanager` CLI für Operations.

**Scope:**
- `bin/imanager` Script, `Imanager\Cli\Application` (symfony/console).
- Commands:
  - `migrate from-v1 --source ./data --target ./data/imanager.db [--dry-run]`
  - `export --category <slug> --format json|csv > out.json`
  - `import --category <slug> --format json < in.json`
  - `dump > out.sql` (SQLite-Dump)
  - `repair` (Integrity-Checks: orphan items, broken FK, FTS-rebuild)
  - `optimize` (`PRAGMA optimize`, `VACUUM`)
  - `fts:rebuild`
  - `schema:status`, `schema:migrate`
- Composer-Bin-Eintrag, sodass `vendor/bin/imanager` global verfügbar ist.

**Deliverables:**
- `bin/imanager`, `src/Cli/*`.
- Acceptance-Tests gegen Beispieldatenbank.

**Acceptance:** `vendor/bin/imanager --help` zeigt alle Commands; alle haben
Tests gegen ein temporäres DB-File.

---

### Phase 16 — Doku & Examples

**Ziel:** dass jemand Externes 2.0 in einer Stunde verstehen und einsetzen kann.

**Scope:**
- API-Doku (Domain, Storage, Field-Type, Query, FTS, CLI).
- Migrations-Guide: 1.x → 2.0 in <30 Minuten.
- Docker-Compose-Beispiel (php-fpm + nginx + Volume für `data/`).
- nginx- und Caddy-Configs als Snippets (statt nur Apache-`.htaccess`).
- Beispiel-Theme oder Beispiel-Site, die die neue API nutzt.
- Update von `README.md` und `scriptor-cms.info`-Doku.

**Deliverables:**
- `docs/api/*`, `docs/migration-guide.md`, `docs/deployment.md`,
  `docs/field-types.md`, `docs/query-cookbook.md`.

**Acceptance:** PR-Review von einem externen User-Profil; Doku-Lint grün.

---

### Phase 17 — 2.0-Release

**Ziel:** ausliefern.

**Scope:**
- CHANGELOG.md final.
- Migrationsguide validiert mit echter 1.x-Demo-Site.
- Performance-Benchmarks dokumentiert.
- `2.0-rc.1` taggen, Feedback-Phase.
- Bug-Fixes als `2.0-rc.2`, …
- `2.0.0` taggen, Composer-Release, GitHub-Release-Notes.
- Blog-Post / Announcement.

**Acceptance:** Composer `composer require bigins/scriptor:^2.0` installiert
sauber; Migration einer 1.x-Site liefert lauffähige 2.0-Site.

---

## 8. Cross-Cutting Topics

### 8.1 Testing-Strategie

- **Unit-Tests** für reine Logik (Sanitizer, Selector-Parser, Field-Validators,
  QueryBuilder, Domain).
- **Integration-Tests** für Storage gegen ein echtes (Test-)SQLite-File.
- **Contract-Tests:** dieselbe Suite gegen `InMemory*Repository` und
  `Sqlite*Repository` laufen lassen.
- **Snapshot-Tests** für Renderer (Field- und Pagination-Output).
- **Migration-Tests** gegen echte 1.x-Fixture-Dateien.
- **Concurrency-Tests:** parallele Saves in PHPUnit-Subprozessen.
- Coverage-Ziel: ≥ 85 % Lines, ≥ 75 % Branches.

### 8.2 Performance-Budget

| Operation | Ziel |
|---|---|
| `getItem` (PK) | < 1 ms |
| `getItems(limit=20)` mit Index | < 20 ms (50k Items) |
| Item-Save | < 5 ms |
| FTS-Search (Single-Wort) | < 50 ms (50k Items) |
| Migration 10k Items | < 30 s |

Benchmarks werden in CI als Smoke-Test mitgefahren (kein Hard-Fail, aber Trend-Monitor).

### 8.3 Sicherheits-Checkliste (gilt phasenübergreifend)

- Keine User-Daten ungefiltert in Regex/SQL/HTML/Header.
- CSP-Default-Header in Editor.
- CSRF auf allen state-changing Endpoints.
- File-Uploads: Mime-Sniff + Allowlist + Path-Traversal-Schutz + Size-Limits.
- `data/`-Verzeichnis aus Web-Root verlagerbar (Config-Option `dataPath`).
- Logs ohne PII / Passwort-Echos.

### 8.4 Concurrency-Modell

- SQLite WAL-Mode: viele parallele Reader, ein serieller Writer pro DB.
- Schreib-Operationen immer in `transactional(callable)`.
- Bei Schemaänderung (Generated Columns) kurz exklusiv – akzeptabel im
  Editor-Workflow.

### 8.5 Backwards-Compat-Strategie

- iManager 2.0 ist eine Major-Version → keine Source-Kompatibilität zu 1.x.
- Selektor-DSL bleibt funktional, aber strenger (Multi-Clause, escaped).
- Migrationsweg führt **ausschließlich** über das CLI-Tool.
- Keine 1.x-Code-Pfade im 2.0-Laufzeitcode.

### 8.6 Deprecation-Politik (post-2.0)

- 2.x-Linie: API-Stabilität, additives Feature-Wachstum.
- Deprecations werden mit `#[\Deprecated]`-Attribute markiert, mind. eine Minor-
  Version Karenz vor Entfernung.

---

## 9. Risiken & Mitigationen

| Risiko | Wahrscheinlichkeit | Impact | Mitigation |
|---|---|---|---|
| Migration verliert Daten aus exotischen 1.x-Strukturen | mittel | hoch | Roundtrip-Tests, Dry-Run-Mode, vollständiges Backup vor Migration |
| `eval`/`include` der 1.x-Datendateien als Sicherheitsrisiko in Migration | mittel | hoch | AST-Parser über `nikic/php-parser` oder Sandbox-Subprozess |
| SQLite-Generated-Columns brechen bei Field-Rename | niedrig | mittel | Lifecycle-Tests + Schema-Manager-Roundtrip |
| WAL-File-Größe wächst unter hoher Schreib-Last | niedrig | niedrig | `PRAGMA wal_autocheckpoint`, periodisches `optimize` |
| HTMLPurifier-Performance-Kosten in Field-Sanitisierung | mittel | mittel | Cache-Layer pro Item-Save oder lazy bei Output |
| Scope-Creep auf Phase 14 (Admin) | hoch | hoch | Strikte Phase-Grenzen, keine UI-Refactorings vor Funktionsparität |
| Editor-Module brechen während Phase 14 | hoch | mittel | Feature-Flag oder paralleler `editor-v2/`-Pfad während Übergang |

---

## 10. Definition of Done (für 2.0.0)

1. ✅ Alle Phasen 0–16 abgeschlossen, Status auf grün.
2. ✅ CI-Matrix (PHP 8.2/8.3 × Linux/Mac) durchgängig grün.
3. ✅ PHPStan Level 8, Psalm Level 3 ohne Errors.
4. ✅ Test-Coverage ≥ 85 % Lines.
5. ✅ Migration einer Referenz-1.x-Site verlustfrei.
6. ✅ Performance-Budget erfüllt (siehe §8.2).
7. ✅ Doku & Migrations-Guide live.
8. ✅ `composer require bigins/scriptor:^2.0` installiert sauber.
9. ✅ Demo-Site auf `demos.scriptor-cms.info` läuft auf 2.0.

---

## 11. Wie wir den Plan benutzen

- Jede Phase startet mit einem kurzen Kick-off im Chat: wir lesen den
  Phase-Block hier, identifizieren offene Fragen, klären sie, dann beginne ich
  mit der Implementierung.
- Ich update die **Status-Tabelle in §7** jeweils, wenn eine Phase startet
  (`🟡 in progress`) oder fertig ist (`✅ done`).
- Größere Designentscheidungen, die während einer Phase auftauchen, landen
  als ADR (`docs/adr/NNNN-<thema>.md`) – ein leichtgewichtiger Architecture-
  Decision-Record pro Punkt.
- Bei Bedarf splitten wir Phasen in Sub-Phasen, wenn sie zu groß werden.

---

## 12. Was als Nächstes ansteht

1. **Phase 0 (Infrastruktur)**: Composer, Tools, CI, Docker, `2.0-dev`-Branch.
2. Anschließend **Phase 2 (Foundations)** — Exception-Hierarchie, Container,
   Config, Enums.
3. 1.x-Bugs werden während der jeweiligen Phasen mit-gefixt (siehe Phase-1-Tabelle).

