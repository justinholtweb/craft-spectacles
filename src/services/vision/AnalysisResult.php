<?php

namespace justinholtweb\spectacles\services\vision;

class AnalysisResult
{
    /**
     * @param string $description Long-form description of the image.
     * @param string[] $tags Short keyword tags.
     * @param string[] $objects Detected objects/subjects.
     * @param string[] $colors Dominant color names or hex codes.
     * @param array $raw Raw provider response, kept for debugging/reindex.
     * @param string $provider Provider key (e.g. "openai").
     * @param string $model Model identifier used.
     */
    public function __construct(
        public string $description,
        public array $tags = [],
        public array $objects = [],
        public array $colors = [],
        public array $raw = [],
        public string $provider = '',
        public string $model = '',
    ) {
    }

    /**
     * The text that gets embedded for similarity search. Combining the
     * description plus tags + objects gives the embedding more lexical
     * surface area than a single sentence.
     */
    public function embeddableText(): string
    {
        $parts = [$this->description];
        if ($this->tags) {
            $parts[] = 'Tags: ' . implode(', ', $this->tags);
        }
        if ($this->objects) {
            $parts[] = 'Objects: ' . implode(', ', $this->objects);
        }
        if ($this->colors) {
            $parts[] = 'Colors: ' . implode(', ', $this->colors);
        }
        return implode("\n", array_filter($parts));
    }
}
