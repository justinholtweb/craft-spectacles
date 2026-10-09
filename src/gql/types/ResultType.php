<?php

namespace justinholtweb\spectacles\gql\types;

use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\elements\Asset as AssetInterface;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * One similar image: the asset, its score and what the vision model said about it — the same
 * row the Twig API and the JSON endpoints return.
 */
class ResultType
{
    public static function getName(): string
    {
        return 'SpectaclesResult';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'An image similar to the one or the text asked about.',
            'fields' => fn() => [
                'asset' => [
                    'type' => Type::nonNull(AssetInterface::getType()),
                    'resolve' => static fn(array $row) => $row['asset'],
                ],
                'score' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Cosine similarity, 0–1. Higher is closer.',
                    'resolve' => static fn(array $row) => (float)$row['score'],
                ],
                'description' => [
                    'type' => Type::string(),
                    'description' => 'The vision model’s description of the image.',
                    'resolve' => static fn(array $row) => $row['metadata']->description,
                ],
                'tags' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                    'resolve' => static fn(array $row) => array_values(array_map('strval', (array)($row['metadata']->tags ?? []))),
                ],
            ],
        ]));
    }
}
