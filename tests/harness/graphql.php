<?php
/**
 * GraphQL checks for `spectaclesSimilar` and `spectaclesSearch`, run against the shared
 * plugin-testing harness:
 *
 *     /tmp/hxl.sh php /var/www/craft-spectacles/tests/harness/graphql.php
 *
 * Seeds three analysed images, runs real queries through `Gql::executeQuery()` against schemas
 * with and without each Spectacles component (one PHP process per schema, see _gql.php), then
 * deletes what it made. The provider layer is stubbed: text "embeds" to a fixed vector and the
 * stub counts the calls, so the checks can tell when a query would have been billed.
 */

use justinholtweb\spectacles\gql\queries\SpectaclesQueries;
use justinholtweb\spectacles\Plugin;

require __DIR__ . '/_boot.php';

/**
 * @return array<int, array{result: array, embedCalls: int}>
 */
function gql(array $scope, array $queries, array $settings = [], bool $caching = false): array
{
    $job = base64_encode(json_encode(['scope' => $scope, 'queries' => $queries, 'settings' => $settings, 'caching' => $caching]));
    $output = shell_exec('php ' . escapeshellarg(__DIR__ . '/_gql.php') . ' ' . escapeshellarg($job) . ' 2>&1');
    $decoded = json_decode((string)$output, true);

    if (!is_array($decoded)) {
        echo "      _gql.php said: " . substr((string)$output, 0, 2000) . "\n";
        return [];
    }

    return $decoded;
}

function ids(array $rows): array
{
    return array_map(static fn(array $row): int => (int)$row['asset']['id'], $rows);
}

$model = Plugin::getInstance()->getSettings()->getEmbeddingModel();
$volume = Craft::$app->getVolumes()->getVolumeByHandle('images');
$suffix = substr(md5((string)microtime(true)), 0, 6);
$made = [];

try {
    // -- Seed: a source, its near twin, and one pointing elsewhere -------------------------

    $source = $made[] = makeImage("spectacles-gql-source-$suffix.png");
    $twin = $made[] = makeImage("spectacles-gql-twin-$suffix.png");
    $other = $made[] = makeImage("spectacles-gql-other-$suffix.png");
    seedMetadata($source->id, [1.0, 0.0, 0.0, 0.0001], $model, 'the source');
    seedMetadata($twin->id, [0.9, 0.1, 0.0, 0.0001], $model, 'a red barn at dusk', ['barn', 'dusk']);
    seedMetadata($other->id, [0.0, 1.0, 0.0, 0.0001], $model, 'something else');

    $vol = "volumes.{$volume->uid}:read";
    $spec = SpectaclesQueries::VOLUME_COMPONENT . ".{$volume->uid}:read";
    $text = SpectaclesQueries::TEXT_SEARCH_COMPONENT . ':read';
    $similar = "{ spectaclesSimilar(assetId: {$source->id}) { asset { id title } score description tags } }";
    $search = '{ spectaclesSearch(text: "red barn") { asset { id } score description } }';

    // -- Schema components ----------------------------------------------------------------

    $components = Craft::$app->getGql()->getAllSchemaComponents()['queries']['Spectacles'] ?? [];
    check('the schema editor offers a Spectacles component per volume', isset($components[$spec]));
    check('… and a separate one for paid text search', isset($components[$text]));

    // -- Without the components, the fields don't exist ------------------------------------

    $runs = gql([], [$similar]);
    check('a schema with nothing has no spectaclesSimilar field', isset($runs[0]['result']) && !array_key_exists('spectaclesSimilar', $runs[0]['result']['data'] ?? []), json_encode($runs[0]['result'] ?? null));

    $runs = gql([$vol], [$similar]);
    check('asset access alone does not add Spectacles fields', !array_key_exists('spectaclesSimilar', $runs[0]['result']['data'] ?? []), json_encode($runs[0]['result'] ?? null));

    // -- spectaclesSimilar ------------------------------------------------------------------

    $runs = gql([$vol, $spec], [
        $similar,
        "{ spectaclesSimilar(assetId: {$source->id}, limit: 1) { asset { id } } }",
        '{ spectaclesSimilar(assetId: 999999999) { asset { id } } }',
        $search,
    ]);
    $rows = $runs[0]['result']['data']['spectaclesSimilar'] ?? null;
    check('similar returns the closest image first, never the source itself', is_array($rows) && ids($rows) !== [] && ids($rows)[0] === $twin->id && !in_array($source->id, ids($rows), true), json_encode($runs[0]['result'] ?? null));
    check('… below the score threshold, nothing', is_array($rows) && !in_array($other->id, ids($rows), true));
    check('… with score, description and tags', is_array($rows) && ($rows[0]['score'] ?? 0) > 0.9 && $rows[0]['description'] === 'a red barn at dusk' && $rows[0]['tags'] === ['barn', 'dusk'], json_encode($rows[0] ?? null));
    check('… and spends nothing on providers', ($runs[0]['embedCalls'] ?? -1) === 0);
    check('limit is honoured', count($runs[1]['result']['data']['spectaclesSimilar'] ?? [0, 0]) === 1, json_encode($runs[1]['result'] ?? null));
    check('an unknown source gives an empty list, not an error', ($runs[2]['result']['data']['spectaclesSimilar'] ?? null) === [] && empty($runs[2]['result']['errors']), json_encode($runs[2]['result'] ?? null));
    check('text search is absent without its own component', !array_key_exists('spectaclesSearch', $runs[3]['result']['data'] ?? []) && ($runs[3]['embedCalls'] ?? -1) === 0, json_encode($runs[3]['result'] ?? null));

    $runs = gql([$spec, $text], [$similar, $search]);
    check('a Spectacles volume the schema cannot query as assets is not searched (similar)', ($runs[0]['result']['data']['spectaclesSimilar'] ?? null) === [], json_encode($runs[0]['result'] ?? null));
    check('… nor by text, and no embedding call is made for it', ($runs[1]['result']['data']['spectaclesSearch'] ?? null) === [] && ($runs[1]['embedCalls'] ?? -1) === 0, json_encode($runs[1]['result'] ?? null));

    $runs = gql([$vol, SpectaclesQueries::VOLUME_COMPONENT . '.not-a-volume:read', $text], [$similar]);
    check('a component for a volume that no longer exists finds nothing', ($runs[0]['result']['data']['spectaclesSimilar'] ?? null) === [], json_encode($runs[0]['result'] ?? null));

    // -- spectaclesSearch -------------------------------------------------------------------

    $runs = gql([$vol, $spec, $text], [
        $search,
        '{ spectaclesSearch(text: "   ") { asset { id } } }',
        '{ spectaclesSearch(text: "red barn", limit: 1) { asset { id } } }',
    ]);
    $rows = $runs[0]['result']['data']['spectaclesSearch'] ?? null;
    check('text search finds the matching images', is_array($rows) && in_array($source->id, ids($rows), true) && in_array($twin->id, ids($rows), true) && !in_array($other->id, ids($rows), true), json_encode($runs[0]['result'] ?? null));
    check('… with one embedding call', ($runs[0]['embedCalls'] ?? -1) === 1);
    check('blank text returns nothing and spends nothing', ($runs[1]['result']['data']['spectaclesSearch'] ?? null) === [] && ($runs[1]['embedCalls'] ?? -1) === 0, json_encode($runs[1]['result'] ?? null));
    check('text search honours limit', count($runs[2]['result']['data']['spectaclesSearch'] ?? []) === 1);

    // A window of its own, so earlier runs' counts don't share the bucket.
    $window = 7200 + random_int(1, 50000);
    $runs = gql([$vol, $spec, $text], [
        '{ a: spectaclesSearch(text: "one") { score } }',
        '{ b: spectaclesSearch(text: "two") { score } }',
        '{ c: spectaclesSearch(text: "three") { score } }',
    ], ['publicSearchRateLimit' => 2, 'publicSearchRateWindow' => $window]);
    $third = $runs[2]['result'] ?? [];
    check('text search is rate-limited like public search', empty($runs[1]['result']['errors']) && str_contains(json_encode($third['errors'] ?? []), 'rate limit'), json_encode($third));
    check('… and a refused query makes no embedding call', ($runs[2]['embedCalls'] ?? -1) === 0);

    $runs = gql([$vol, $spec, $text], [$search, $search], caching: true);
    check('with GraphQL caching on, a repeated query is answered without a second embedding call', ($runs[0]['embedCalls'] ?? -1) === 1 && ($runs[1]['embedCalls'] ?? -1) === 0 && ids($runs[1]['result']['data']['spectaclesSearch'] ?? []) === ids($runs[0]['result']['data']['spectaclesSearch'] ?? [1]), json_encode($runs));
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach ($made as $asset) {
        Craft::$app->getElements()->deleteElement($asset, true);
    }
}

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
