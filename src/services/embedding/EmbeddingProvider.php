<?php

namespace justinholtweb\spectacles\services\embedding;

interface EmbeddingProvider
{
    public function embedText(string $text): EmbeddingResult;

    /**
     * Multimodal embedding. Returns null if this provider does not support
     * embedding raw images. Callers should fall back to embedText() in that
     * case.
     */
    public function embedImage(string $imageData, string $mimeType): ?EmbeddingResult;

    public function supportsMultimodal(): bool;
}
