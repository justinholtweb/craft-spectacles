<?php

namespace justinholtweb\spectacles\gql\queries;

use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use justinholtweb\spectacles\gql\resolvers\SpectaclesResolver;
use justinholtweb\spectacles\gql\types\ResultType;

/**
 * `spectaclesSimilar` and `spectaclesSearch`, read-only.
 *
 * Scoped per volume like the public endpoints' **Public volumes**: a schema gets one
 * `spectaclesVolumes.<uid>:read` component per volume it may search, and only finds — or may ask
 * about — images in those volumes that it can also query as assets. Text search embeds the query
 * with the configured provider, a paid call each time, so it needs `spectacles.textSearch:read`
 * as well.
 */
class SpectaclesQueries extends Query
{
    public const VOLUME_COMPONENT = 'spectaclesVolumes';
    public const TEXT_SEARCH_COMPONENT = 'spectacles.textSearch';

    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::isSchemaAwareOf(self::VOLUME_COMPONENT)) {
            return [];
        }

        $limit = [
            'type' => Type::int(),
            'description' => 'How many results, 1–100. Defaults to the plugin’s result limit.',
        ];

        $queries = [
            'spectaclesSimilar' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(ResultType::getType()))),
                'args' => [
                    'assetId' => ['type' => Type::nonNull(Type::int())],
                    'limit' => $limit,
                ],
                'resolve' => SpectaclesResolver::class . '::resolveSimilar',
                'description' => 'Images similar to an analysed image. Empty unless the image is in a volume this schema may search.',
            ],
        ];

        if (!$checkToken || GqlHelper::canSchema(self::TEXT_SEARCH_COMPONENT)) {
            $queries['spectaclesSearch'] = [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(ResultType::getType()))),
                'args' => [
                    'text' => ['type' => Type::nonNull(Type::string())],
                    'limit' => $limit,
                ],
                'resolve' => SpectaclesResolver::class . '::resolveSearch',
                'description' => 'Images matching a text description. Each query is one call to the embedding provider, rate-limited like public search.',
            ];
        }

        return $queries;
    }
}
