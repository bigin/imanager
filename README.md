# iManager

> Embeddable, SQLite-backed Content Management Framework for PHP.

[![CI](https://github.com/bigin/imanager/actions/workflows/ci.yml/badge.svg)](https://github.com/bigin/imanager/actions/workflows/ci.yml)

---

## Status

**🚧 2.0 development in progress — not for production use.**

iManager 2.0 is a ground-up rewrite of the iManager library that ships with
[Scriptor](https://github.com/bigin/Scriptor). The 2.0 line replaces the
flat `var_export`-based persistence with SQLite (JSON columns + generated
columns + FTS5), introduces typed domain models, a Repository / Query
layer, a CLI tool, and a clean field-type plugin system.

The full multi-phase plan lives in
[`Scriptor/docs/imanager-2.0-plan.md`](https://github.com/bigin/Scriptor/blob/master/docs/imanager-2.0-plan.md);
the deep analysis of the 1.x codebase is in
[`Scriptor/docs/imanager-analysis.md`](https://github.com/bigin/Scriptor/blob/master/docs/imanager-analysis.md).

For the current production-ready 1.x line, use Scriptor ≤ 1.x.

---

## Requirements

- PHP **8.2+**
- Extensions: `pdo_sqlite`, `mbstring`, `gd`, `dom`, `json`
- Composer 2

---

## Development

The repo ships with a Docker-based dev environment (PHP 8.3 CLI + SQLite +
Composer). You don't need anything else on your host machine.

```bash
docker compose build
docker compose run --rm imanager composer install
docker compose run --rm imanager composer ci
```

Available composer scripts:

| Script | Description |
|---|---|
| `composer test` | Run PHPUnit |
| `composer lint` | Run PHP-CS-Fixer in dry-run |
| `composer format` | Auto-format with PHP-CS-Fixer |
| `composer stan` | Static analysis (PHPStan, level 8) |
| `composer psalm` | Static analysis (Psalm, level 3) |
| `composer ci` | Full pipeline (lint + stan + psalm + test) |

---

## License

[MIT](LICENSE) — © bigin / Juri Ehret
