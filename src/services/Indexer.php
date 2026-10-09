<?php

namespace justinholtweb\spectacles\services;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\models\Volume;
use InvalidArgumentException;
use justinholtweb\spectacles\jobs\AnalyzeAsset;
use justinholtweb\spectacles\Plugin;
use justinholtweb\spectacles\records\ImageMetadata;
use yii\base\Component;

/**
 * What is indexed, what isn't, and queueing the difference — the logic behind the console
 * commands, kept here so a dry run and a real run choose exactly the same images.
 *
 * An image is one of three things:
 * - analysed: it has a vector made by the current embedding model, so it can be found;
 * - missing: it has no metadata row, or a row without a vector (the embedding call failed);
 * - mismatched: it has a vector from another model — written before a provider or model
 *   switch. Vectors of different models aren't comparable, so it can't be found until it is
 *   re-analysed.
 */
class Indexer extends Component
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_MISSING = 'missing';
    public const SCOPE_MISMATCHED = 'mismatched';

    /**
     * Provider calls per analysis: one to the vision model, one to the embedding model (whether
     * it embeds the image or the description).
     */
    public const CALLS_PER_IMAGE = 2;

    /**
     * The volumes Spectacles analyses: the `volumeUids` setting, or every volume when it's empty —
     * the same rule auto-analyse and the settings screen's re-index button follow.
     *
     * @return Volume[]
     */
    public function analysedVolumes(): array
    {
        $volumes = Craft::$app->getVolumes();
        $uids = Plugin::getInstance()->getSettings()->volumeUids;

        if ($uids === []) {
            return $volumes->getAllVolumes();
        }

        return array_values(array_filter(array_map(
            static fn(string $uid): ?Volume => $volumes->getVolumeByUid($uid),
            $uids,
        )));
    }

    /**
     * The analysed volumes named by a comma-separated list of handles, or all of them for null.
     *
     * @return Volume[]
     * @throws InvalidArgumentException for a handle that isn't a volume, or is one Spectacles
     *                                  doesn't analyse
     */
    public function resolveVolumes(?string $handles): array
    {
        $analysed = $this->analysedVolumes();
        if ($handles === null || trim($handles) === '') {
            return $analysed;
        }

        $byHandle = [];
        foreach ($analysed as $volume) {
            $byHandle[$volume->handle] = $volume;
        }

        $out = [];
        foreach (array_unique(array_filter(array_map('trim', explode(',', $handles)))) as $handle) {
            if (!isset($byHandle[$handle])) {
                throw new InvalidArgumentException(Craft::$app->getVolumes()->getVolumeByHandle($handle)
                    ? "Volume “{$handle}” isn’t one Spectacles analyses (see the Volumes setting)."
                    : "No volume has the handle “{$handle}”.");
            }
            $out[] = $byHandle[$handle];
        }

        return $out;
    }

    /**
     * The ids of the image assets in `$volumes` that `$scope` selects, oldest first.
     *
     * @param Volume[] $volumes
     * @return int[]
     */
    public function candidateIds(array $volumes, string $scope = self::SCOPE_ALL, ?int $limit = null): array
    {
        if (!in_array($scope, [self::SCOPE_ALL, self::SCOPE_MISSING, self::SCOPE_MISMATCHED], true)) {
            throw new InvalidArgumentException("Unknown scope “{$scope}”.");
        }

        $ids = $this->imageIds(array_map(static fn(Volume $v): int => (int)$v->id, $volumes));

        if ($scope !== self::SCOPE_ALL) {
            $states = $this->states($ids);
            $ids = array_values(array_filter($ids, static fn(int $id): bool => $states[$id] === $scope));
        }

        return $limit !== null && $limit > 0 ? array_slice($ids, 0, $limit) : $ids;
    }

    /**
     * Counts per analysed volume, for `spectacles/status`.
     *
     * @return array<int, array{volume: Volume, images: int, analysed: int, missing: int, mismatched: int}>
     */
    public function status(): array
    {
        $rows = [];
        foreach ($this->analysedVolumes() as $volume) {
            $ids = $this->imageIds([(int)$volume->id]);
            $counts = array_count_values($this->states($ids));
            $rows[] = [
                'volume' => $volume,
                'images' => count($ids),
                'analysed' => $counts['analysed'] ?? 0,
                'missing' => $counts[self::SCOPE_MISSING] ?? 0,
                'mismatched' => $counts[self::SCOPE_MISMATCHED] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * Queue an analysis job per asset id. Returns how many were queued.
     *
     * @param int[] $assetIds
     */
    public function queue(array $assetIds): int
    {
        $queue = Craft::$app->getQueue();
        foreach ($assetIds as $assetId) {
            $queue->push(new AnalyzeAsset(['assetId' => $assetId]));
        }

        return count($assetIds);
    }

    /**
     * @param int[] $volumeIds
     * @return int[]
     */
    private function imageIds(array $volumeIds): array
    {
        if ($volumeIds === []) {
            return [];
        }

        return array_map('intval', Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->volumeId($volumeIds)
            ->orderBy(['elements.id' => SORT_ASC])
            ->ids());
    }

    /**
     * Each id's state: `analysed`, `missing` or `mismatched`.
     *
     * @param int[] $assetIds
     * @return array<int, string>
     */
    private function states(array $assetIds): array
    {
        $model = Plugin::getInstance()->getSettings()->getEmbeddingModel();
        $states = array_fill_keys($assetIds, self::SCOPE_MISSING);

        foreach (array_chunk($assetIds, 1000) as $chunk) {
            $rows = (new Query())
                ->select(['assetId', 'embeddingModel', 'hasVector' => '(CASE WHEN [[embedding]] IS NULL THEN 0 ELSE 1 END)'])
                ->from(ImageMetadata::tableName())
                ->where(['assetId' => $chunk])
                ->all();

            foreach ($rows as $row) {
                if (!(int)$row['hasVector']) {
                    continue;
                }
                $states[(int)$row['assetId']] = $row['embeddingModel'] === $model ? 'analysed' : self::SCOPE_MISMATCHED;
            }
        }

        return $states;
    }
}
