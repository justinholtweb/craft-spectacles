<?php

namespace justinholtweb\spectacles\services\vision;

interface VisionProvider
{
    /**
     * Analyze an image and return a structured payload.
     *
     * @param string $imageData Raw image bytes.
     * @param string $mimeType  e.g. image/jpeg, image/png.
     */
    public function analyze(string $imageData, string $mimeType): AnalysisResult;
}
