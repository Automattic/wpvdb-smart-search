# AGENTS.md

Agent guidance for this repository. Keep this file focused on non-obvious constraints and gotchas. General project information belongs in `README.md`.

## Boundaries

- Do not add credentials, tokens, application passwords, site-specific hostnames, or private deployment details.
- Shared search algorithms belong in `https://github.com/Automattic/wpvdb-search`. Keep this plugin focused on the standalone UI and public REST adapter.
- Do not add Playground demo behavior here. Demo model routing, fixture data, and offline demo work belong in `wpvdb-playground-demo`.
- Keep the public REST response shape stable unless the React client is updated in the same change. The client consumes timing and count fields directly.

## Runtime gotchas

- `/smart-search/` is served through WordPress rewrite APIs. Do not switch back to exact `REQUEST_URI` matching, because public WordPress Playground can prefix paths with a scope segment.
- If the route changes, update activation and deactivation rewrite handling in the same change. This repo does not currently do a version-keyed init flush, so upgraded test sites may need a manual permalink flush.
- Search ranking comes from `WPVDB_Search\Search`. The React client may group rows by post, but it should not re-rank the server results.
- React translations are loaded through PHP-parsed `.mo` locale data passed to `window.WPVDB_SMART_SEARCH`, not through `wp_set_script_translations()` JSON sidecars.

## Development notes

- Build, lint, and test commands are defined in `package.json` and `composer.json`; prefer those scripts instead of inventing ad hoc commands.
- Keep asset cache busting based on `filemtime()` unless the build or release flow changes.
