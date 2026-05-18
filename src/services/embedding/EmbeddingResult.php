<?php

namespace justinholtweb\spectacles\services\embedding;

class EmbeddingResult
{
    /**
     * @param string $model Identifier of the model that produced the vector.
     * @param float[] $vector
     * @param string $modality 'text' or 'image' — useful for telemetry/debug.
     */
    public function __construct(
        public string $model,
        public array $vector,
        public string $modality = 'text',
    ) {
    }
}
