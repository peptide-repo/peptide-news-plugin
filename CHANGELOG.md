# Changelog

All notable changes to Peptide News Plugin are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning: [Semantic Versioning](https://semver.org/).

## [2.5.0] — 2026-06-16

### Changed
- **CI standardization (estate wave 2):** Replace per-repo CI jobs in
  `ci.yml` with a thin caller of `peptiderepo/peptide-e2e/.github/workflows/ci.yml@main`
  (`tests: stubs`, `has_js: true`). Adds `workflow_call` trigger so `deploy.yml`
  gate remains valid.
- Extract `security-audit` job from `ci.yml` into standalone
  `.github/workflows/security-audit.yml` (kept as-is, unique to this repo).
- `deploy.yml`: elevate permissions to `contents: write` so the nested
  phpcbf commit-back step in the reusable workflow receives the correct token scope.
- `composer.json`: pin `test` script to `vendor/bin/phpunit --configuration phpunit.xml.dist`
  for explicit path resolution in CI; pin `lint` / `lint:fix` to `vendor/bin/`.
- `phpunit.xml.dist`: add XSD reference for PHPUnit 9.6; drop deprecated
  `convertErrorsToExceptions`, `convertNoticesToExceptions`, `convertWarningsToExceptions`
  attributes.

### Notes
- `rollback.yml` and `cto-review.yml` and `main-push-audit.yml` unchanged — unique / estate-separate.
- No functional code changes in this commit.

## Prior history

Versions prior to 2.5.0 were not tracked in a CHANGELOG. See git log for details.
