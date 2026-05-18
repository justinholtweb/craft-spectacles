<?php

namespace justinholtweb\spectacles\services\vision;

interface VisionProvider
{
    /**
     * Analyze an image and return a structured payload.
     *
     * @param string $imageData Raw image bytes.
     * @param string $mimeType  e.g. image/jpeg, image/png.
     * @return AnalysisResult
     */
    public function analyze(string $imageData, string $mimeType): AnalysisResult;

    /**
     * Generate an embedding vector from a textual description.
     *
     * @param string $text
     * @return array{model: string, vector: float[]}
     */
    public function embedText(string $text): array;
}
