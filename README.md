# WPVDB Smart Search

[![Checks](https://github.com/rbcorrales/wpvdb-smart-search/actions/workflows/ci.yml/badge.svg)](https://github.com/rbcorrales/wpvdb-smart-search/actions/workflows/ci.yml)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-3858e9?logo=wordpress&logoColor=white)](#requirements)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4?logo=php&logoColor=white)](#requirements)
[![License](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](LICENSE)

Demo search UI, REST adapter, and optional site search replacement for dense, sparse, and hybrid search over content indexed by [`wpvdb`](https://github.com/Automattic/wpvdb).

The plugin also includes an optional site search surface. When enabled in Smart Search settings, eligible front end `?s=` main queries are answered with ranked semantic results from `wpvdb-search`, while unsupported queries fall through to WordPress search. Site search defaults to dense mode; sparse and hybrid remain available for sites that want keyword ranking or sparse plus dense ranking.

![WPVDB Smart Search interface](docs/screenshots/screenshot.png)

## Requirements

| Requirement | Version or notes |
|---|---|
| WordPress | 6.9 or newer |
| PHP | 8.3 or newer |
| [`wpvdb-search`](https://github.com/rbcorrales/wpvdb-search) | Installed and configured |

## What this plugin owns

- The `/smart-search/` public page.
- Optional semantic handling for WordPress `?s=` search queries.
- The `wpvdb-smart-search/v1` REST namespace.
- The React UI, browser assets, and REST adapter.
- Demo examples, placeholders, and UI copy.
- A WP-CLI prewarm command for example query embeddings.

Search execution lives in [`wpvdb-search`](https://github.com/rbcorrales/wpvdb-search).

## Development

Install dependencies:

```bash
bun install
composer install
```

Build the browser assets:

```bash
bun run build
```

Run the local checks:

```bash
bun run lint
bun run test
```

The main branch maintenance workflow regenerates translation files and commits them when strings change.

Run the same command locally only when you want to preview language file changes:

```bash
bun run i18n
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
