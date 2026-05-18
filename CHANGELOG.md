# Changelog

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
