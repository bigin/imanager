# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Phase 0 — Infrastructure & CI

- Initial repository scaffold.
- Composer package `bigins/imanager`, PSR-4 autoload `Imanager\\` → `src/`.
- Dev tooling: PHPUnit 11, PHPStan level 8, Psalm level 3, PHP-CS-Fixer.
- Runtime dependencies: `league/container`, `symfony/console`,
  `nikic/php-parser`, `erusev/parsedown`, `ezyang/htmlpurifier`, `psr/log`.
- GitHub Actions CI: PHP 8.2/8.3 matrix on Linux.
- Dockerfile (PHP 8.3 CLI + SQLite + Composer) and `docker-compose.yml`.
- Smoke test for autoload + version constant.
- CLI entrypoint stub at `bin/imanager`.
