<?php

namespace justinholtweb\spectacles\services;

use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\Plugin;
use justinholtweb\spectacles\services\vision\AnalysisResult;
use justinholtweb\spectacles\services\vision\OpenAiProvider;
use justinholtweb\spectacles\services\vision\VisionProvider;
use RuntimeException;
use yii\base\Component;

/**
 * Resolves the configured vision provider and proxies analyze/embed calls.
 */
class Vision extends Component
{
    public function provider(): VisionProvider
    {
        $settings = Plugin::getInstance()->getSettings();

        return match ($settings->visionProvider) {
            Settings::PROVIDER_OPENAI => new OpenAiProvider($settings),
            default => throw new RuntimeException("Unsupported vision provider: {$settings->visionProvider}"),
        };
    }

    public function analyze(string $imageData, string $mimeType): AnalysisResult
    {
        return $this->provider()->analyze($imageData, $mimeType);
    }

    /**
     * @return array{model: string, vector: float[]}
     */
    public function embedText(string $text): array
    {
        return $this->provider()->embedText($text);
    }
}
