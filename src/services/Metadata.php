<?php

namespace justinholtweb\spectacles\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\Db;
use justinholtweb\spectacles\Plugin;
use justinholtweb\spectacles\records\ImageMetadata;
use justinholtweb\spectacles\services\vision\AnalysisResult;
use RuntimeException;
use Throwable;
use yii\base\Component;

/**
 * Persists vision results for assets and provides lookup helpers.
 */
class Metadata extends Component
{
    public function findByAssetId(int $assetId): ?ImageMetadata
    {
        return ImageMetadata::findOne(['assetId' => $assetId]);
    }

    /**
     * Run vision + embedding for an asset and store the result.
     */
    public function analyzeAsset(Asset $asset): ImageMetadata
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            throw new RuntimeException("Asset {$asset->id} is not an image.");
        }

        $stream = $asset->getStream();
        try {
            $imageData = stream_get_contents($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($imageData === false || $imageData === '') {
            throw new RuntimeException("Could not read asset {$asset->id} contents.");
        }

        $mimeType = $asset->getMimeType() ?? 'image/jpeg';

        $vision = Plugin::getInstance()->vision;
        $analysis = $vision->analyze($imageData, $mimeType);

        $embedding = null;
        $embeddingModel = null;
        $embeddable = $analysis->embeddableText();
        if ($embeddable !== '') {
            try {
                $embedded = $vision->embedText($embeddable);
                $embedding = $embedded['vector'];
                $embeddingModel = $embedded['model'];
            } catch (Throwable $e) {
                Craft::warning("Spectacles: embedding failed for asset {$asset->id}: " . $e->getMessage(), __METHOD__);
            }
        }

        return $this->store($asset->id, $analysis, $embedding, $embeddingModel);
    }

    public function store(
        int $assetId,
        AnalysisResult $analysis,
        ?array $embedding,
        ?string $embeddingModel,
    ): ImageMetadata {
        $record = $this->findByAssetId($assetId) ?? new ImageMetadata(['assetId' => $assetId]);

        $record->description = $analysis->description;
        $record->tags = $analysis->tags;
        $record->objects = $analysis->objects;
        $record->colors = $analysis->colors;
        $record->rawResponse = $analysis->raw;
        $record->visionProvider = $analysis->provider;
        $record->visionModel = $analysis->model;
        $record->embedding = $embedding;
        $record->embeddingModel = $embeddingModel;
        $record->analyzedAt = Db::prepareDateForDb(new \DateTime());

        if (!$record->save()) {
            throw new RuntimeException(
                "Failed to save metadata for asset {$assetId}: " . json_encode($record->getErrors())
            );
        }

        return $record;
    }

    public function deleteForAsset(int $assetId): void
    {
        ImageMetadata::deleteAll(['assetId' => $assetId]);
    }
}
