<?php

namespace justinholtweb\spectacles\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use justinholtweb\spectacles\Plugin;

class AnalyzeAsset extends BaseJob
{
    public int $assetId;

    public function execute($queue): void
    {
        $asset = Asset::find()->id($this->assetId)->one();
        if (!$asset) {
            return;
        }

        $this->setProgress($queue, 0.1, "Loading {$asset->filename}");

        try {
            Plugin::getInstance()->metadata->analyzeAsset($asset);
            $this->setProgress($queue, 1.0, "Analyzed {$asset->filename}");
        } catch (\Throwable $e) {
            Craft::error("Spectacles AnalyzeAsset failed for asset {$this->assetId}: {$e->getMessage()}", __METHOD__);
            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('spectacles', 'Analyzing image with computer vision');
    }
}
