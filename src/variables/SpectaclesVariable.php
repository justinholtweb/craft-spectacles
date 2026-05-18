<?php

namespace justinholtweb\spectacles\variables;

use craft\elements\Asset;
use justinholtweb\spectacles\Plugin;
use justinholtweb\spectacles\records\ImageMetadata;

/**
 * Twig API:
 *   craft.spectacles.similar(asset)            // similar to a given asset
 *   craft.spectacles.searchText('beach sunset') // similar to a query string
 *   craft.spectacles.metadataFor(asset)         // raw metadata record
 */
class SpectaclesVariable
{
    /**
     * @return array<int, array{asset: Asset, score: float, metadata: ImageMetadata}>
     */
    public function similar(Asset $asset, ?int $limit = null): array
    {
        return Plugin::getInstance()->similarity->similarToAsset($asset, $limit);
    }

    /**
     * @return array<int, array{asset: Asset, score: float, metadata: ImageMetadata}>
     */
    public function searchText(string $query, ?int $limit = null): array
    {
        return Plugin::getInstance()->similarity->similarToText($query, $limit);
    }

    public function metadataFor(Asset $asset): ?ImageMetadata
    {
        return Plugin::getInstance()->metadata->findByAssetId($asset->id);
    }
}
