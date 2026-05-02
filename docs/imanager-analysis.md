# iManager – Tiefenanalyse

> Stand: 2026-05-01 · iManager-Version 3.3.0 (`IM_VERSION_HUMAN`) · eingebettet in Scriptor 1.12.1

Diese Analyse betrachtet das in Scriptor enthaltene `imanager/`-Framework
(ca. 5.500 LOC PHP) als eigenständiges Mini-CMF und dient als Grundlage für
einen anschließenden Architektur- und Refactoring-Plan.

---

## 1. Was iManager ist

Eine eingebettete, dateibasierte **Mini-Persistenzschicht und Field/Form-Engine**,
konzeptionell verwandt mit ProcessWire (Teile des Sanitizers wurden von dort
übernommen, siehe §4.3).

Domänenmodell:

```
Category  (1) ──► (n) Field   (Schemadefinition)
Category  (1) ──► (n) Item    (Datenzeile mit dynamischen Field-Werten)
```

Persistenz: PHP-`var_export()` in `data/datasets/buffers/`:
- `categories/categories.php` – globale Kategorienliste
- `fields/<catId>.fields.php` – Schema je Kategorie
- `items/<catId>.items.php` – Items je Kategorie

Jede Datei ist `<?php return [...]; ?>` und wird per `include` als PHP-Array
zurückgelesen (mit OPcache-Invalidation).

---

## 2. Funktionalitäten

| Bereich | Klasse(n) | Funktion |
|---|---|---|
| Bootstrap / Singleton | `imanager()`, `ItemManager`, `Manager` | Lazy-DI über `__get`, Auto-Loader für Field-/Input-Prozessoren |
| Konfiguration | `Config`, `Util::buildConfig()` | Default `inc/config.php` + Override `data/settings/custom.config.php` |
| Schema/Daten-CRUD | `Category`, `Field`, `Item` und ihre `*Mapper` | Speichern, Laden, Löschen, Rebuild |
| Abfrage-DSL | `applySearchPattern`, `getItem(s)`, `getCategory/-ies` | Selektoren wie `name=foo`, `position>=3`, `name=%blog%` |
| Sortierung | `*Mapper::sort()` | usort über beliebiges Attribut, ASC/DESC, Offset/Length |
| Pluggable Field-Typen | `processors/fields/Field*.php` + `processors/inputs/Input*.php` | 17 Typen: Text, Longtext, Editor, Slug, Datepicker, Dropdown, Checkbox, Integer, Decimal, Money, Password, Hidden, Array, Filepicker, Fileupload, Imageupload |
| Validierung & Sanitisierung | `Sanitizer` (1.579 LOC), `Input*` | name/text/path/url/int/email/array/markdown/purify/etc. |
| HTTP-Input | `Input`, `UrlSegments`, `Post/Get/Put/Patch/Whitelist` | URL-Segment-Parsing, Pagination-Erkennung |
| Templates | `TemplateParser` | Naives `[[var]]`-Ersetzen + Pagination-Renderer |
| Cache | `SectionCache` / `ImCacheFile` | Markup-Fragment-Cache mit globalem `last`-mtime |
| Uploads / Bilder | `phpthumb/`, `upload/` (Blueimp jQuery File Upload) | Frontend + serverseitige Upload-Pipeline |
| Errors & Logs | `Util::imErrorHandler`, `imShutdownErrorHandler`, `dataLog` | Eigener Error-Handler + monatliche Log-Datei |
| Backups | `Util::createBackup`, `deleteOutdatedBackups` | Optional je Save (`backupItems/Fields/Categories`) |
| Concurrency | `Item::lockFile()` (`flock LOCK_EX`) | nur in `Item::save()` |

---

## 3. Pros

1. **Null externe Runtime-Abhängigkeiten** außer phpThumb/Blueimp. Deploy = kopieren.
2. **Dynamisches Schema zur Laufzeit**: Felder werden per Admin-UI definiert; Items haben dynamische Properties pro Field-Name.
3. **Sauberes Pluggable-Pattern für Field-Typen**: jeder Typ hat ein Render-Pendant (`Field*`) und ein Validator-Pendant (`Input*`) hinter den Interfaces `FieldInterface`/`InputInterface`. Open/Closed-freundlich.
4. **Selector-Mini-DSL** (`getItems('position>=3')`, Wildcards `%foo%`).
5. **Eingebauter Error-Handler & Shutdown-Handler** mit hübschem Trace im Debug-Modus.
6. **Backups & OPcache-Invalidation** out of the box.
7. **CSRF-Token-Generator** mit defensiver Fallback-Kette (`random_bytes` → `mcrypt_create_iv` → `openssl_random_pseudo_bytes`).
8. **Pagination-Mechanik** mit Ellipsen-/Adjacents-Logik und konfigurierbaren Templates.
9. **Strict types** wurde in einigen Kerndateien (Manager, Config, CategoryMapper, ItemMapper, Input, Item) bereits eingeführt – Modernisierung läuft.

---

## 4. Cons – konkret im Code aufgespürt

### 4.1 Architektur / Storage

- **`var_export` + `include` als Persistenz-Format** ist eine versteckte RCE-Klasse:
  Jede Lücke im Sanitizing kann zu einem ausführbaren Datenfile werden.
  Außerdem an PHP-Array-Dialekt gekoppelt → Migration zu anderen Tools schmerzhaft.
- **Kein atomares Schreiben**: `file_put_contents($im->path, '<?php return …')` in
  `Item.php:250`, `ItemMapper.php:113/414`, `CategoryMapper.php:407`,
  `Field.php:340`, `FieldMapper.php:233`. Crash mitten im Write → kaputtes
  PHP-File → Fatal beim nächsten Include. Pflicht: tmp-File + `rename()`.
- **Lock nur in `Item::save()`** (`Item.php:301`). `Field::save()`,
  `Category::save()`, alle `remove()`/`rebuild()`-Pfade schreiben **ohne** flock.
  Klassische Race-Condition unter Last.
- **Singletons via `private static $initialized`** in
  `CategoryMapper`/`ItemMapper`/`FieldMapper`. Beim Wechsel der Kategorie-ID
  muss man `force=true` mitgeben, sonst sieht man Stale-Daten. Long-Running-
  Setups (CLI-Worker, FrankenPHP, RoadRunner) brechen damit.
- **Ganze Kategorie wird auf jedes Save komplett neu geschrieben**.
  Skaliert nicht über ein paar Tausend Items pro Kategorie. `var_export($im->items, true)`
  baut den ganzen String im Speicher auf. `ItemMapper::initAll()` (Zeile 129) ist
  passenderweise leer mit Kommentar „Could be extreme slow…".
- **Vererbung statt Komposition**: `class Item extends FieldMapper` (`Item.php:8`).
  Ein Item ist konzeptuell *kein* FieldMapper. Es existiert auch ein
  `class Mapper extends ImObject {}` (3 Zeilen), das nur als Marker dient.

### 4.2 Konkrete Bugs

| # | Datei:Zeile | Problem |
|---|---|---|
| B1 | `CategoryMapper.php:350` | `$this->imanager->fieldMappar` (Tippfehler) → null via `__get` → Fatal beim folgenden `->remove(...)`. |
| B2 | `Item.php:230` | `if (!isset($this->{$field->name}) \|\| !empty($field->default))` – sobald ein Default existiert, wird **immer** der Default geschrieben. Logik vermutlich `&&` statt `\|\|`. |
| B3 | `Input.php:62-66` | `array_shift(explode(...))` – `array_shift` braucht Variable by-reference; seit PHP 7.0 Fatal. Methode privat & ungenutzt, aber latent. |
| B4 | `ItemMapper.php:400` | `$options['removeAssets']` ohne `isset` bei Default `array()` → Undefined-Index-Notice. |
| B5 | `CategoryMapper.php:159 ff.` | `$offset` lokal mit `0` reinitialisiert; jeder über die Public-API durchgereichte Offset ist effektiv tot. `ItemManager::getCategories` exponiert auch keinen Offset. |
| B6 | `CategoryMapper.php:190 ff.` / `ItemMapper.php:280 ff.` | `applySearchPattern` returnt nach **erstem Match**. Mehrfach-Selektoren mit AND (z.B. `name=foo, position>=3`) werden nicht unterstützt. |
| B7 | gleiche Stelle | Regex aus User-Input ohne `preg_quote()`. Eingaben mit `/`, `(`, `[` injizieren in die Regex. |
| B8 | `TemplateParser.php:217` | `$counter` nach `for`-Loop referenziert; im „close to end"-Pfad separat überschrieben → fragile Pagination-Logik am Rand. |

### 4.3 Geerbter, toter ProcessWire-Code im Sanitizer

Die Datei `Sanitizer.php` ist zu großen Teilen aus ProcessWire kopiert und
nie portiert. Beweise:

- `Sanitizer.php:67` – `$this->wire('modules')->getModuleConfigData('InputfieldPageName')`
- `Sanitizer.php:929` – `$this->wire('modules')->get('TextformatterMarkdownExtra')`
- `Sanitizer.php:1048` – `$this->wire('modules')->get('MarkupHTMLPurifier')`
- `Sanitizer.php:1544` – `$this->wire('modules')->findByPrefix('FileValidator', false)`

Die Methode `wire()` existiert hier nicht. Folge: `pageName($val, true)` mit
`Sanitizer::translate`, `entitiesMarkdown()`, `purify()`, `validateFile()`
werfen einen Fatal Error sobald sie ernsthaft aufgerufen werden. Substanzielle
**tote/kaputte Codepfade**, die bei oberflächlichem Review wie „funktional"
wirken.

### 4.4 Sicherheits- / Robustheits-Hinweise

- **`Util::redirect`** (`_Util.php:138-142`): `header('Location: ' . htmlspecialchars($url), …)` –
  `htmlspecialchars` ist hier das falsche Werkzeug. Was schützt werden muss: CRLF-Header-Injection
  (`%0d%0a`-Stripping) und idealerweise eine URL-Allowlist gegen Open-Redirects.
- **`Util::logException` ruft am Ende `exit()`** (`_Util.php:357`). Eine einzelne nicht-fatale
  Fehlersituation (illegaler Selector, fehlende Datei) terminiert die ganze Response.
  Sollte typisierte Exception werfen und dem Caller die Entscheidung lassen.
- **`Config::getUrl/getScriptUrl`** vertraut weitgehend `$_SERVER['SERVER_NAME']`/`REQUEST_URI`
  und mischt `htmlentities` auf URLs (semantisch falsch für Path-Building).
- **`data/`-Verzeichnis** liegt webroot-intern und ist nur durch `.htaccess` geschützt.
  Nginx/Caddy/LiteSpeed-Deployments exponieren Daten- und Backup-PHP-Files.
  README erwähnt nur Apache.
- **Logs** (`data/logs/imlog_*.txt`): `fopen('a+')` ohne flock → unter Last verschachtelte Zeilen.
- **Hardcoded `chmod 0644/0755`** geht an Server-Setups mit anderen umasks /
  containerisierten Mounts vorbei.

### 4.5 API-/Code-Hygiene

- Inkonsistentes `declare(strict_types=1)` (drin in Manager/Config/CategoryMapper/
  ItemMapper/Input/Item, nicht in Field/FieldMapper/Util/Sanitizer/TemplateParser/
  Category/SectionCache).
- Mischung aus PHPDoc-Typen und nativen PHP-Type-Hints; viele `mixed`-Returns,
  wo enge Typen möglich wären.
- `Mapper.php` ist 3-Zeilen-Tot-Klasse.
- `Config::$storage` ist explizit „currently not used".
- `CategoryMapper::removeField` ist vollständig auskommentiert (`CategoryMapper.php:426-450`).
- Globaler Zustand via `imanager()`-Funktion und `parent::___init()` macht
  Unit-Testing praktisch unmöglich.
- **Keine Tests** im gesamten Projekt, kein CI.
- Keine PSR-4-Autoloading-Definition; eigener handgeschriebener Loader in `Manager::loader`.

---

## 5. Verbesserungsvorschläge – priorisiert

### Must-fix (Bugs / Security)

1. `CategoryMapper::remove`: `fieldMappar` → `fieldMapper`.
2. `Item::save` Default-Logik (`Item.php:230`) korrigieren – sonst überschreibt jedes Save echte Inhalte mit dem Default.
3. ProcessWire-`wire()`-Aufrufe im `Sanitizer` portieren (z.B. echtes `erusev/parsedown` + `ezyang/htmlpurifier` als composer-deps) **oder** die toten Methoden entfernen.
4. `Util::redirect` auf CRLF-Strip + Allowlist umbauen.
5. `Util::logException` aufhören mit `exit()` – eigene `ImanagerException`-Hierarchie werfen.
6. `applySearchPattern`: User-Input mit `preg_quote()` escapen, Mehrfach-Selektoren (AND) unterstützen.

### Should-fix (Datenintegrität / Skalierbarkeit)

7. **Atomares Schreiben** überall: tmp-Datei + `rename()`. `rename` ist auf POSIX atomar im selben FS.
8. **Konsistentes File-Locking** via `withLock($path, callable)`-Helper – in **allen** Save-/Remove-/Rebuild-Pfaden.
9. **Statische Init-Flags** raus (`private static $initialized` / `$category_id`) – Init pro Instanz, nicht prozessweit. Ermöglicht Concurrent-Workers und Tests.
10. **JSON statt `var_export`** (oder besser: dedizierter Storage-Layer mit pluggable Backend, siehe Architektur).

### Architektur

11. **`Storage`-Interface** mit Implementierungen `FilesystemStorage` und `SqliteStorage`. Hint dazu liegt schon in `Config::$storage`. SQLite gibt ACID, FK, Indexes, atomare Mass-Updates – ohne Server, weiterhin „flat-file"-fühlbar.
12. **Item-Größe**: pro Kategorie eine Datei skaliert nicht. Optionen: pro Item eine Datei + Index-File / SQLite / Append-only journal + periodischer Compact.
13. `Item extends FieldMapper` → Komposition: `Item` *hat* einen `FieldMapper` per DI. Vererbungs-Hierarchie auf Substanz reduzieren (`Mapper.php` löschen).
14. Manager-Magic (`Manager::__get` → `_imXxx()`) durch echten DI-Container oder Service-Locator ersetzen. Liest sich klar, verbessert IDE-Support und Testbarkeit.
15. PSR-4-Autoloading, composer-managed.

### Modernes PHP 8.1+

16. `enum FieldType` statt `string $type`; `enum InputError` statt `const EMPTY_REQUIRED = -1`.
17. Constructor Property Promotion, readonly-Properties wo angebracht.
18. Selector-API als typisierter Builder: `$cat->items()->where('position', '>=', 3)->orderBy('created', 'desc')->paginate(10)`. Die String-DSL bleibt als Convenience-Wrapper.
19. **Tests**: PHPUnit-Suite, Hauptfokus (a) Sanitizer (b) Selector-Parser (c) Item-Save-Roundtrip (d) Concurrent-Save mit Locks.

### Operatives

20. nginx/Caddy-Configs in der README; `.htaccess` ist nicht universell.
21. `data/`-Verzeichnis aus dem Web-Root in ein Schwesterverzeichnis verschieben (per Konstante konfigurierbar).
22. Blueimp jQuery File Upload ist seit Jahren unmaintained – Migration zu **tus.io**, **Uppy** oder **FilePond** wäre eine spürbare Modernisierung des Editor-UX und reduziert vendored-JS-Volumen.
23. CI: GitHub Action mit PHPUnit + PHPStan (Level 6+) + Psalm + composer-audit.

---

## 6. TL;DR

iManager ist ein **gut durchdachtes Mini-CMF-Skelett** mit klarem Field-Plugin-Pattern
und ergonomischer Selector-DSL, aber an drei Stellen **strukturell brüchig**:

1. **Speicher-Layer** – PHP-`var_export`, kein atomares Write, lückenhaftes Locking.
2. **Globaler Zustand** – statische Init-Flags + globale `imanager()`-Funktion blockieren Tests/Workers.
3. **Sanitizer mit ungeportetem ProcessWire-Code**, der teilweise nur scheinbar funktioniert.

Die größten Hebel: atomares Schreiben + flock-Konsistenz, dedizierter Storage-Layer
(JSON oder SQLite hinter Interface), `wire()`-Reste eliminieren, Default-Bug in
`Item::save` fixen, `fieldMappar`-Tippfehler fixen, Tests einführen.
