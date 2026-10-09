<?php
/**
 * Console checks for `spectacles/index`, `spectacles/reindex` and `spectacles/status`, run
 * against the shared plugin-testing harness:
 *
 *     /tmp/hxl.sh php /var/www/craft-spectacles/tests/harness/console.php
 *
 * Seeds four images — one analysed on the current model, one on another model, one never
 * analysed, one analysed without a vector — then checks what each command picks. Commands that
 * would queue work run in this process with the queue swapped for a recorder, so no paid
 * analysis job is ever really queued; the counting-only commands also run as real `php craft`
 * processes. Self-cleaning.
 */

use craft\elements\Asset;
use craft\queue\Queue;
use justinholtweb\spectacles\jobs\AnalyzeAsset;
use justinholtweb\spectacles\Plugin;
use justinholtweb\spectacles\services\Indexer;

require __DIR__ . '/_boot.php';

/** Records pushed jobs instead of storing them. */
class RecordingQueue extends Queue
{
    /** @var int[] */
    public array $assetIds = [];

    public function push($job): ?string
    {
        if ($job instanceof AnalyzeAsset) {
            $this->assetIds[] = $job->assetId;
        }
        return (string)count($this->assetIds);
    }
}

/** Runs a console command in this process and returns its exit code and the jobs it queued. */
function runInProcess(string $route, array $params): array
{
    $queue = new RecordingQueue();
    Craft::$app->set('queue', $queue);
    $code = Craft::$app->runAction($route, $params);
    return [$code, $queue->assetIds];
}

/** Runs `php craft …` in a fresh process; returns [exit code, output]. */
function craft(string $args): array
{
    exec('cd /var/www/html && php craft ' . $args . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines)];
}

$plugin = Plugin::getInstance();
$indexer = $plugin->indexer;
$model = $plugin->getSettings()->getEmbeddingModel();
$volume = Craft::$app->getVolumes()->getVolumeByHandle('images');
$suffix = substr(md5((string)microtime(true)), 0, 6);
$made = [];
$originalQueue = Craft::$app->getQueue();
stubVision();

try {
    $analysed = $made[] = makeImage("spectacles-cli-analysed-$suffix.png");
    $stale = $made[] = makeImage("spectacles-cli-stale-$suffix.png");
    $fresh = $made[] = makeImage("spectacles-cli-fresh-$suffix.png");
    $noVector = $made[] = makeImage("spectacles-cli-novector-$suffix.png");
    seedMetadata($analysed->id, [1.0, 0.0], $model);
    seedMetadata($stale->id, [1.0, 0.0], 'some-older-model');
    seedMetadata($noVector->id, null, $model);

    $all = $indexer->candidateIds([$volume]);
    $missing = $indexer->candidateIds([$volume], Indexer::SCOPE_MISSING);
    $mismatched = $indexer->candidateIds([$volume], Indexer::SCOPE_MISMATCHED);
    $images = (int)Asset::find()->kind(Asset::KIND_IMAGE)->volumeId($volume->id)->count();
    $sorted = $all;
    sort($sorted);

    // -- The indexer's choices ----------------------------------------------------------------

    check('every image in the volume is a candidate for a full index', count($all) === $images && in_array($analysed->id, $all, true), count($all) . " vs $images");
    check('missing = never analysed, or analysed without a vector', in_array($fresh->id, $missing, true) && in_array($noVector->id, $missing, true) && !in_array($analysed->id, $missing, true) && !in_array($stale->id, $missing, true));
    check('mismatched = a vector from another model, nothing else', in_array($stale->id, $mismatched, true) && !array_intersect([$analysed->id, $fresh->id, $noVector->id], $mismatched));
    check('candidates are oldest first', $all === $sorted);

    $status = $indexer->status()[0] ?? [];
    check('status counts add up for the volume', ($status['images'] ?? 0) === $images
        && $status['analysed'] + $status['missing'] + $status['mismatched'] === $images
        && $status['mismatched'] === count($mismatched) && $status['missing'] === count($missing), json_encode(array_diff_key($status, ['volume' => 1])));

    // -- spectacles/index ---------------------------------------------------------------------

    [$code, $queued] = runInProcess('spectacles/index', ['missingOnly' => true, 'limit' => '2']);
    check('index --missing-only --limit=2 queues the two oldest unanalysed images', $code === 0 && $queued === array_slice($missing, 0, 2), json_encode([$code, $queued]));

    [$code, $queued] = runInProcess('spectacles/index', ['missingOnly' => true, 'dryRun' => true]);
    check('index --dry-run queues nothing', $code === 0 && $queued === [], json_encode([$code, $queued]));

    [$code, $queued] = runInProcess('spectacles/index', ['volume' => 'images']);
    check('index --volume=images queues every image in it', $code === 0 && $queued === $all);

    // -- spectacles/reindex -------------------------------------------------------------------

    [$code, $queued] = runInProcess('spectacles/reindex', []);
    check('reindex is a dry run unless told otherwise', $code === 0 && $queued === [], json_encode([$code, $queued]));

    [$code, $queued] = runInProcess('spectacles/reindex', ['dryRun' => '0']);
    check('reindex --dry-run=0 queues only the other-model images', $code === 0 && $queued === $mismatched && in_array($stale->id, $queued, true), json_encode([$code, $queued]));

    // -- Real processes, counting only --------------------------------------------------------

    Craft::$app->set('queue', $originalQueue);
    $jobsBefore = (int)(new craft\db\Query())->from('{{%queue}}')->where(['like', 'job', 'AnalyzeAsset'])->count();

    [$code, $out] = craft('spectacles/index --missing-only --dry-run');
    $n = count($missing);
    check('`php craft spectacles/index --dry-run` prints the image count and estimated calls', $code === 0
        && str_contains($out, "$n images in {$volume->name}")
        && str_contains($out, sprintf('about %d provider calls (%d vision + %d embedding)', $n * 2, $n, $n))
        && str_contains($out, 'Dry run'), $out);

    [$code, $out] = craft('spectacles/reindex --limit=1');
    check('`php craft spectacles/reindex` names the current model and stays a dry run', $code === 0 && str_contains($out, "Current embedding model: $model") && str_contains($out, "1 image in {$volume->name}") && str_contains($out, 'Dry run'), $out);

    [$code, $out] = craft('spectacles/status');
    check('`php craft spectacles/status` prints a row per volume', $code === 0 && str_contains($out, 'Other model') && preg_match('/images\s+' . $images . '\s+' . $status['analysed'] . '\s+' . $status['missing'] . '\s+' . $status['mismatched'] . '/', $out) === 1, $out);

    [$code, $out] = craft('spectacles/index --volume=nope --dry-run');
    check('an unknown volume handle is a usage error', $code === 64 && str_contains($out, 'No volume has the handle'), "$code $out");

    [$code, $out] = craft('spectacles/index --limit=0 --dry-run');
    check('--limit=0 is a usage error', $code === 64, "$code $out");

    $jobsAfter = (int)(new craft\db\Query())->from('{{%queue}}')->where(['like', 'job', 'AnalyzeAsset'])->count();
    check('no analysis job reached the real queue', $jobsAfter === $jobsBefore, "$jobsBefore -> $jobsAfter");

    // -- A volume Spectacles doesn't analyse ---------------------------------------------------

    $plugin->setSettings(['volumeUids' => ['00000000-0000-0000-0000-000000000000']]);
    $refused = false;
    try {
        $indexer->resolveVolumes('images');
    } catch (InvalidArgumentException $e) {
        $refused = str_contains($e->getMessage(), 'isn’t one Spectacles analyses');
    }
    check('a volume outside the Volumes setting is refused', $refused);
    check('… and with no analysed volumes, there is nothing to index', $indexer->analysedVolumes() === []);
    $plugin->setSettings(['volumeUids' => []]);
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    Craft::$app->set('queue', $originalQueue);
    foreach ($made as $asset) {
        Craft::$app->getElements()->deleteElement($asset, true);
    }
}

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
