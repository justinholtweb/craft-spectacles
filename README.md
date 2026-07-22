# Spectacles

Computer-vision-powered similar-image search for Craft CMS.

Docs and support: <https://craft-spectacles.com>

Spectacles analyzes your asset library with a vision model, stores structured metadata and a similarity vector for each image, and exposes endpoints + Twig helpers for finding similar images. Visitors can upload an image and get back the closest matches from your library.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- An API key from one of the supported providers — or a local Ollama daemon

## Installation

```bash
composer require justinholtweb/craft-spectacles
./craft plugin/install spectacles
```

## Supported providers

Vision and embedding are configured independently — pick whichever combination suits your budget and quality bar.

| Provider | Vision | Text embedding | Image embedding | Notes |
|---|---|---|---|---|
| **OpenAI** | gpt-4o, gpt-4o-mini, gpt-4.1 | text-embedding-3-small / -large | — | Strong default. JSON mode is reliable. |
| **Anthropic** | Claude 4.x (Opus / Sonnet / Haiku) | — | — | Highest-quality descriptions. Pair with another embedder. |
| **Google Gemini** | gemini-2.5-flash, gemini-2.5-pro | text-embedding-004 | — | Fast and inexpensive. |
| **Voyage AI** | — | voyage-multimodal-3 | voyage-multimodal-3 | **Best similarity results** — embeds the image directly, no description-loss. |
| **Ollama (local)** | llava, llama3.2-vision, bakllava | nomic-embed-text, mxbai-embed-large | — | Self-hosted, no API costs, slower. |

**Recommended pairings:**

- _Best quality:_ Anthropic Claude (vision) + Voyage `voyage-multimodal-3` (embedding)
- _Best price/perf:_ OpenAI `gpt-4o-mini` + OpenAI `text-embedding-3-small`
- _Self-hosted:_ Ollama `llava` + Ollama `nomic-embed-text`
- _Strict similarity, weak descriptions OK:_ any vision provider + Voyage multimodal

When the embedding provider is multimodal-capable (currently only Voyage), Spectacles will embed the **image bytes directly** instead of embedding the text description — this captures visual features the description would lose.

## Configuration

Settings live at **Settings → Plugins → Spectacles**. Set API keys via environment variables and reference them as `$OPENAI_API_KEY`, `$ANTHROPIC_API_KEY`, `$GEMINI_API_KEY`, `$VOYAGE_API_KEY`.

Other settings:

- `autoAnalyzeOnUpload` — queue an analysis job whenever an image asset is saved.
- `volumeUids` — restrict analysis to specific volumes.
- `defaultResultLimit` / `minSimilarityScore` — search tuning.
- `allowPublicSearch` / `maxVisitorUploadKb` — public upload endpoint controls.

> **Switching providers?** Different models produce vectors of different dimensions, so Spectacles only compares vectors of matching shape. After switching, click **Re-index all images** to regenerate embeddings.

## Usage

### Re-index existing assets

From the settings screen, click **Re-index all images** to queue a job for every image in the configured volumes. Progress is visible in the queue.

### Twig

```twig
{# similar to a given asset #}
{% set similar = craft.spectacles.similar(asset, 8) %}
{% for row in similar %}
    <a href="{{ row.asset.url }}">
        <img src="{{ row.asset.url({ width: 240, height: 240, mode: 'crop' }) }}">
        <small>{{ row.score }} — {{ row.metadata.description }}</small>
    </a>
{% endfor %}

{# free-form text search #}
{% for row in craft.spectacles.searchText('foggy mountain at sunrise') %}
    <img src="{{ row.asset.url }}">
{% endfor %}
```

### Visitor upload form

Drop the included partial into any frontend template:

```twig
{% include 'spectacles/_partials/upload-form' %}
```

Or post to the endpoint directly:

```http
POST /spectacles/search
Content-Type: multipart/form-data
Accept: application/json

image=<file>
```

Response:

```json
{
  "analysis": {
    "description": "A foggy mountain ridge at sunrise.",
    "tags": ["mountain", "fog", "sunrise"],
    "objects": ["mountain", "trees"],
    "colors": ["pink", "blue", "gray"]
  },
  "results": [
    { "id": 412, "url": "...", "thumbUrl": "...", "score": 0.87, "description": "..." }
  ]
}
```

### JSON for an existing asset

```http
GET /spectacles/similar/{assetId}
Accept: application/json
```

## Architecture

- `spectacles_imagemetadata` table stores description, tags, objects, colors, and the embedding vector (as JSON) for each asset.
- Vision and embedding are separate concerns:
  - `services/vision/VisionProvider` — `analyze()` returns an `AnalysisResult`.
  - `services/embedding/EmbeddingProvider` — `embedText()` and optional `embedImage()` return `EmbeddingResult`.
- `services/Vision::embedForImage()` prefers multimodal embedding when the provider supports it, falling back to text.
- Similarity uses pluggable backends (`services/similarity/SimilarityBackend`):
  - **Scan** (default): cosine in PHP over the JSON embeddings; works on any DB.
  - **pgvector**: queries a dedicated `spectacles_imagevectors` table with the `vector` type; orders of magnitude faster and lets you add an HNSW or IVFFlat index for production scale.
  - The active backend is auto-detected on Postgres + extension, or you can force it from the settings screen.

### CP integration

The asset edit screen renders a Spectacles panel in the sidebar showing the description, tags, similar-image thumbnails, and the active backend/model. If the asset hasn't been analyzed yet, an "Analyze now" button queues a job.

### Adding a provider

1. Implement `VisionProvider` and/or `EmbeddingProvider` under `src/services/vision/` or `src/services/embedding/`.
2. Add a constant to `Settings::PROVIDER_*` and to `VISION_PROVIDERS` / `EMBEDDING_PROVIDERS`.
3. Wire it into `Vision::visionProvider()` / `Vision::embeddingProvider()`.
4. Add fields to the settings template.

## Development

The repo ships a [DDEV](https://ddev.com) config so the toolchain runs without
installing PHP locally:

```bash
ddev start
ddev composer install
ddev test              # everything: unit + integration + static analysis
```

`ddev test` also accepts a target: `ddev test unit`, `ddev test integration`,
or `ddev test phpstan`.

### Test suites

There are two, split by what they need to run:

| Suite | Runner | Location | Scope |
|---|---|---|---|
| `unit` | PHPUnit | `tests/unit` | Pure logic — cosine similarity, provider response normalization, JSON extraction, pgvector literal formatting. No Craft, no database. |
| `integration` | Codeception + Craft's test framework | `tests/integration` | Anything needing a booted Craft: settings validation, the ActiveRecord and its JSON columns, the similarity/metadata/vision services, the Twig variable, event wiring, and the public endpoint's rate limiter. |

Without DDEV:

```bash
composer install
composer test              # PHPUnit only
composer test:integration  # Codeception (needs a database)
composer phpstan
```

The integration suite installs Craft into a dedicated `craft_test` database and
wipes it on every run — it never touches the project database. DDEV provisions
that database via a `post-start` hook; outside DDEV, create it yourself and
point `tests/.env` at it.

## License

This plugin is licensed under the [Craft license](LICENSE.md). See `LICENSE.md`
for the full terms.
