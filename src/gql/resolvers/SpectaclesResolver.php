<?php

namespace justinholtweb\spectacles\gql\resolvers;

use Craft;
use craft\elements\Asset;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Error\UserError;
use justinholtweb\spectacles\gql\queries\SpectaclesQueries;
use justinholtweb\spectacles\helpers\RateLimit;
use justinholtweb\spectacles\Plugin;
use Throwable;

class SpectaclesResolver
{
    private const MAX_LIMIT = 100;

    /** Longer than any honest description of a picture; bounds what one query sends the provider. */
    private const MAX_TEXT = 1000;

    public static function resolveSimilar(mixed $source, array $arguments): array
    {
        $volumeIds = self::volumeIds();
        if ($volumeIds === []) {
            return [];
        }

        // The source must be one the schema could search, or the answer would confirm that an
        // image elsewhere exists and say what it looks like.
        $asset = Asset::find()->id((int)$arguments['assetId'])->volumeId($volumeIds)->one();
        if (!$asset) {
            return [];
        }

        return Plugin::getInstance()->similarity->similarToAsset($asset, self::limit($arguments), $volumeIds);
    }

    public static function resolveSearch(mixed $source, array $arguments): array
    {
        $text = trim((string)$arguments['text']);
        if ($text === '') {
            return [];
        }
        $text = mb_substr($text, 0, self::MAX_TEXT);

        $volumeIds = self::volumeIds();
        if ($volumeIds === []) {
            return [];
        }

        // One embedding call per query: the public endpoints' budget applies, in a bucket of
        // its own. Repeats of the same query are answered from Craft's GraphQL cache, unspent.
        $settings = Plugin::getInstance()->getSettings();
        if ($settings->publicSearchRateLimit > 0
            && !RateLimit::allowWindow('gql-search', $settings->publicSearchRateLimit, max(1, $settings->publicSearchRateWindow))
        ) {
            throw new UserError('Search rate limit exceeded. Please slow down.');
        }

        try {
            return Plugin::getInstance()->similarity->similarToText($text, self::limit($arguments), $volumeIds);
        } catch (Throwable $e) {
            Craft::error('Spectacles GraphQL text search failed: ' . $e->getMessage(), __METHOD__);
            throw new UserError('Search failed.');
        }
    }

    /**
     * The volumes this schema may search: those it has a Spectacles component for **and** may
     * query assets in — an asset type the schema doesn't know can't be returned through it.
     *
     * @return int[]
     */
    public static function volumeIds(): array
    {
        $pairs = GqlHelper::extractAllowedEntitiesFromSchema('read');
        $uids = array_intersect($pairs[SpectaclesQueries::VOLUME_COMPONENT] ?? [], $pairs['volumes'] ?? []);

        $volumes = Craft::$app->getVolumes();
        return array_values(array_filter(array_map(
            static fn(string $uid): ?int => $volumes->getVolumeByUid($uid)?->id,
            $uids,
        )));
    }

    private static function limit(array $arguments): ?int
    {
        $limit = $arguments['limit'] ?? null;
        return $limit === null ? null : max(1, min(self::MAX_LIMIT, (int)$limit));
    }
}
