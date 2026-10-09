# Changelog

## 5.2.0 - 2026-10-09
### Added
- Console commands. `spectacles/index` queues analysis for the analysed volumes, with `--volume`, `--missing-only` (no vector yet), `--limit` and `--dry-run`, which prints the image count and the provider calls it would cost. `spectacles/reindex` re-analyses only the images embedded by a different model than the one configured now — what a provider switch leaves behind — and is a dry run unless given `--dry-run=0`. `spectacles/status` prints analysed, unanalysed and other-model counts per volume.
- GraphQL: `spectaclesSimilar(assetId, limit)` and `spectaclesSearch(text, limit)`, returning the asset with its score, description and tags. A schema gets one **Find similar images** permission per volume and only finds, or may ask about, images in volumes it has ticked and can query as assets. Text search, which makes one paid embedding call per query, has a permission of its own and shares the public endpoints' rate limit.

## 5.1.0 - 2026-10-05

> {warning} Public search is now off by default and only searches the volumes you mark public — none, until you choose them. If your site uses `/spectacles/search` or `/spectacles/similar`, open **Settings → Plugins → Spectacles**, tick **Public volumes** and save, or those endpoints return nothing. Re-indexing now needs the new **Re-index every image** permission (admins have it).

### Security
- **Public search returned images from every indexed volume.** The anonymous `/spectacles/search` and `/spectacles/similar/<id>` endpoints were on by default and searched everything Spectacles had analysed, so any visitor got titles, filenames, URLs and AI-generated descriptions from internal volumes — and `/similar` took any asset ID as its source. Public search now only returns, and only accepts as a source, images in the new **Public volumes** setting, which is empty by default; analysing a volume for the control panel no longer publishes it. `allowPublicSearch` defaults to off.
- **Re-index and Analyze now could be triggered by any page an admin visited.** Both were GET links, so an `<img src>` elsewhere queued a paid re-analysis of every image. Both are POST-only now, posted by their buttons; re-indexing needs its own **Re-index every image** permission rather than queue-manager access, and analysing an asset needs permission to save it rather than view it.
- **The public endpoints' rate limit could be reset by any client.** It keyed on `getUserIP()`, which reads `X-Forwarded-For` unasked, and read-then-wrote without a lock. It now keys on the connecting address (the forwarded one only when `trustedHosts` names your proxies), counts under a lock, and sits under a site-wide ceiling. The setting means what it did.
- The asset sidebar's similar images now only include assets the user can view.

## 5.0.2 - 2026-08-19

### Fixed
- The four provider preset buttons on the plugin settings screen ("Best
  quality", "Balanced", "Cheapest hosted", "Self-hosted") did nothing. Craft
  renders plugin settings inside `{% namespace 'settings' %}`, which rewrites
  every `name="…"` it finds — including inside `<script>` text — so the
  hard-coded `settings[…]` selector in the preset handler became
  `settings[settings][…]` and matched no field. The selector now matches on the
  field-name suffix, which the namespacing leaves alone.

## 5.0.1 - 2026-07-22

### Fixed
- Every vision and embedding request failed with `Class "yii\httpclient\Client"
  not found`. The shared HTTP helper all eight providers call through depends on
  `yiisoft/yii2-httpclient`, which was never declared as a requirement and is not
  pulled in by `craftcms/cms` — so no provider could reach its API. It is now an
  explicit dependency.
- `composer install` could not resolve the dependency tree: `craftcms/phpstan`
  (which publishes only `dev-main`) requires PHPStan 1.x, while the root package
  asked for `^2.0`. PHPStan is now pinned to `^1.12`.

### Added
- Test suites. PHPUnit covers provider-agnostic logic (cosine similarity,
  response normalization, JSON extraction, pgvector literal formatting);
  Codeception with Craft's test framework covers everything needing a booted
  Craft — settings validation, the metadata record and its JSON columns, the
  similarity/metadata/vision services, the Twig variable, event wiring, and the
  public endpoints' rate limiting.
- A DDEV config so the toolchain runs without a local PHP install, plus a
  `ddev test` command for the suites and static analysis.

## 5.0.0 - 2026-06-11

First public release. The version is aligned with the Craft 5 major version the
plugin targets, superseding the internal 1.x line.

### Added
- Per-IP rate limiting on the public search endpoints, configurable via the new
  `publicSearchRateLimit` and `publicSearchRateWindow` settings (each request
  can trigger a paid vision/embedding call). Set the limit to 0 to disable.

### Changed
- Auto-analyze now runs only on a brand-new upload or a file replacement. It no
  longer re-runs on unrelated edits (title, focal point, moves), drafts,
  revisions, or the duplicate saves that multi-site propagation fires —
  eliminating redundant paid API calls.
- `GET /spectacles/similar/<assetId>` now respects the "Allow public visitor
  uploads" setting, and a visitor-supplied `limit` is clamped to 100.

### Fixed
- The plugin settings page is now reachable. A `getSettingsResponse()` override
  redirected the settings URL to itself, causing an infinite redirect loop
  (`ERR_TOO_MANY_REDIRECTS`); the default Craft behavior renders the settings
  template directly.
- pgvector search no longer errors when the vector table contains mixed
  embedding dimensions (e.g. mid-provider-switch); mismatched-dimension rows
  are skipped, matching the scan backend.

### Removed
- Dead `Metadata::deleteForAsset()`. Asset deletions are handled by the
  database's `ON DELETE CASCADE`, which correctly preserves metadata across
  soft-delete/restore.

## 1.1.0 - 2026-05-07

### Added
- Pluggable similarity backends (`SimilarityBackend` interface).
- `PgvectorBackend` — Postgres `vector` column + cosine distance, auto-detected
  when the extension is installed. Migration creates `spectacles_imagevectors`
  on Postgres + pgvector and is a no-op everywhere else.
- Settings: `vectorIndex` (auto / scan / pgvector).
- Quick-preset buttons on the settings screen: Best quality (Claude + Voyage),
  Balanced (OpenAI), Cheapest (Gemini), Self-hosted (Ollama).
- CP asset edit sidebar panel showing description, tags, similar-image
  thumbnails, and an "Analyze now" action for un-analyzed images.
- `POST /spectacles/analyze-asset` (CP) for single-asset analysis.

### Changed
- `Similarity::similarToVector` now delegates to the active backend.
- `Metadata::store` keeps the JSON embedding for portability and *also* writes
  to the active backend's index, so switching backends is reversible without
  re-calling the vision API.

## 1.0.0 - 2026-05-07

### Added
- Initial release.
- Vision providers: OpenAI, Anthropic, Google Gemini, Ollama (self-hosted).
- Embedding providers: OpenAI, Google Gemini, Voyage AI (multimodal), Ollama.
- Multimodal-aware embedding pipeline — embeds image bytes directly when the
  provider supports it (Voyage), falls back to text otherwise.
- Independent vision + embedding configuration.
- Auto-analyze queue job on asset save.
- `spectacles_imagemetadata` table with description, tags, objects, colors,
  and embedding.
- Public `POST /spectacles/search` endpoint for visitor uploads.
- `GET /spectacles/similar/<assetId>` JSON endpoint.
- `craft.spectacles.similar(asset)`, `searchText(query)`, `metadataFor(asset)`
  Twig helpers.
- CP settings screen + bulk re-index button.
- Cosine similarity gracefully skips records whose vector dimension does not
  match the query (so switching providers degrades to "needs re-index"
  rather than crashing).
