<?php

namespace justinholtweb\spectacles\services;

use justinholtweb\spectacles\services\vision\AnalysisResult;

/**
 * Helpers shared by vision providers — system prompt + result normalization.
 */
trait ProviderHelpers
{
    private function systemPrompt(): string
    {
        return 'You analyze images for similarity search. Respond with strict JSON matching the schema: '
            . '{"description": string, "tags": string[], "objects": string[], "colors": string[]}. '
            . 'Tags should be lowercase, single concepts. Colors should be plain English names. No prose, JSON only.';
    }

    private function buildAnalysisResult(array $parsed, string $provider, string $model): AnalysisResult
    {
        return new AnalysisResult(
            description: (string)($parsed['description'] ?? ''),
            tags: $this->normalizeStringList($parsed['tags'] ?? []),
            objects: $this->normalizeStringList($parsed['objects'] ?? []),
            colors: $this->normalizeStringList($parsed['colors'] ?? []),
            raw: $parsed,
            provider: $provider,
            model: $model,
        );
    }

    /**
     * @return string[]
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = strtolower(trim($item));
            }
        }
        return array_values(array_unique($out));
    }
}
