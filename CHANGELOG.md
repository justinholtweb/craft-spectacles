# Changelog

## 1.0.0 - 2026-05-07

### Added
- Initial release.
- OpenAI vision + embedding provider.
- Auto-analyze queue job on asset save.
- `spectacles_imagemetadata` table with description, tags, objects, colors, and embedding.
- Public `POST /spectacles/search` endpoint for visitor uploads.
- `GET /spectacles/similar/<assetId>` JSON endpoint.
- `craft.spectacles.similar(asset)`, `searchText(query)`, `metadataFor(asset)` Twig helpers.
- CP settings screen + bulk re-index button.
