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
- `allowPublicSearch` — off by default. Exposes `POST /spectacles/search` and `GET /spectacles/similar/{id}` to anyone.
- `publicVolumeUids` — the **only** volumes public search returns images from or may be asked about. Empty means none: analysing a volume (`volumeUids`) does not publish it.
- `maxVisitorUploadKb`, `publicSearchRateLimit` / `publicSearchRateWindow` — per-visitor limits on the public endpoints (by connecting address; `X-Forwarded-For` only counts once `trustedHosts` names your proxies), under a site-wide ceiling of 20×.

> **Switching providers?** Different models produce vectors of different dimensions, so Spectacles only compares vectors of matching shape. After switching, run `php craft spectacles/reindex` to see how many images were embedded with the old model, then `php craft spectacles/reindex --dry-run=0` to re-analyse just those (or click **Re-index all images** to redo everything).

## Usage

### Re-index existing assets

From the settings screen, click **Re-index all images** to queue a job for every image in the configured volumes, or use the [console commands](#console) to index only what's missing, cap a run, or see the cost first. Progress is visible in the queue. Re-indexing spends on your providers for every image, so it needs the **Re-index every image** permission; analysing a single asset needs permission to save it.

### Console

Every re-index option the settings screen has, plus the ones a deploy script needs: a volume filter, a cap per run, and a dry run that prints what a run would cost before it spends anything. The commands queue analysis jobs — run the queue as usual.

```bash
php craft spectacles/status                              # analysed / unanalysed / other-model counts per volume
php craft spectacles/index --missing-only --dry-run      # count, and estimate provider calls
php craft spectacles/index --missing-only --limit=500    # queue up to 500 never-analysed images
php craft spectacles/index --volume=photos,products      # queue every image in those volumes
php craft spectacles/reindex                             # after a provider switch: count the other-model images (dry run)
php craft spectacles/reindex --dry-run=0                 # …and queue them
```

| Command | Options | What it picks |
|---|---|---|
| `spectacles/index` | `--volume`, `--missing-only`, `--limit`, `--dry-run` (`-n`) | Every image in the analysed volumes; with `--missing-only`, those with no vector yet (never analysed, or the embedding call failed). |
| `spectacles/reindex` | `--volume`, `--limit`, `--dry-run` (**on by default**) | Images whose vector came from a different embedding model than the one configured now. Images already on the current model are left alone. |
| `spectacles/status` | — | Prints the counts; queues nothing. |

The estimate is two provider calls per image: one vision, one embedding. `--volume` takes handles of volumes Spectacles analyses (the **Volumes** setting, or every volume when that's empty); any other handle is refused.

### GraphQL

Two read-only queries, for headless front ends:

```graphql
{
  spectaclesSimilar(assetId: 412, limit: 8) {
    score
    description
    tags
    asset { id url title }
  }
  spectaclesSearch(text: "foggy mountain at sunrise", limit: 12) {
    score
    asset { id url }
  }
}
```

Both are off until a schema allows them. Under **GraphQL → Schemas → Spectacles**:

- **Find similar images in the “…” volume** — one per volume, the GraphQL counterpart of **Public volumes**. A schema only finds, and may only ask about, images in the volumes it has ticked here *and* can query as assets (the volume's own **Query for assets** permission). An image anywhere else gives an empty list, so its existence isn't confirmed.
- **Search those volumes by text** — adds `spectaclesSearch`. Each query is one paid call to the embedding provider, so it's a separate permission, it shares the public endpoints' rate limit (`publicSearchRateLimit` / `publicSearchRateWindow`, in a bucket of its own), and queries are capped at 1,000 characters. With Craft's GraphQL caching on, a repeated query is answered from the cache without another call.

`spectaclesSimilar` makes no provider call: it compares stored vectors. `limit` is clamped to 1–100 and defaults to `defaultResultLimit`. The public-search settings (`allowPublicSearch`, `publicVolumeUids`) don't apply to GraphQL — the schema is the switch.

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
| `harness` | plain PHP | `tests/harness` | The console commands and the GraphQL queries, run against the shared plugin-testing site (`php /var/www/craft-spectacles/tests/harness/console.php`, `…/graphql.php`). Providers are stubbed; nothing is queued for real. |

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
