<?php
/**
 * Runs GraphQL queries against one in-memory schema and prints the results as JSON.
 *
 *     php tests/harness/_gql.php <base64 JSON job>
 *
 * The job is `{"scope": [...], "queries": [string, ...], "caching": bool, "settings": {...}}`.
 * One schema per process: Craft's GraphQL type registry can't be rebuilt for a second schema in
 * the same process. Every schema gets a fresh uid so no answer comes from another run's cache.
 */

require __DIR__ . '/_boot.php';

$job = json_decode(base64_decode($argv[1] ?? ''), true) ?: [];
$general = Craft::$app->getConfig()->getGeneral();
$general->enableGraphqlCaching = (bool)($job['caching'] ?? false);

$plugin = justinholtweb\spectacles\Plugin::getInstance();
$plugin->setSettings(array_merge(['autoAnalyzeOnUpload' => false, 'minSimilarityScore' => 0.5, 'publicSearchRateLimit' => 0], $job['settings'] ?? []));
$stub = stubVision();

$schema = new craft\models\GqlSchema([
    'name' => 'Spectacles check',
    'uid' => craft\helpers\StringHelper::UUID(),
    'scope' => $job['scope'] ?? [],
]);

$out = [];
foreach ($job['queries'] ?? [] as $query) {
    $before = $stub->embedCalls;
    $out[] = [
        'result' => Craft::$app->getGql()->executeQuery($schema, $query),
        'embedCalls' => $stub->embedCalls - $before,
    ];
}

echo json_encode($out);
