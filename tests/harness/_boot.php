<?php
/**
 * Boots the plugin-testing harness's Craft for a plain-PHP check script, and gives the scripts
 * their shared helpers. Run from the harness root (/var/www/html).
 *
 * Plain PHP rather than Codeception: a Craft-backed Codeception suite drops every table, so it is
 * never run in the shared harness. The Codeception suites run in this repo's own DDEV (`ddev test`).
 */

use craft\elements\Asset;
use justinholtweb\spectacles\Plugin;
use justinholtweb\spectacles\records\ImageMetadata;
use justinholtweb\spectacles\services\embedding\EmbeddingResult;
use justinholtweb\spectacles\services\vision\AnalysisResult;
use justinholtweb\spectacles\services\Vision;

require '/var/www/html/bootstrap.php';
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

if (Plugin::getInstance() === null) {
    fwrite(STDERR, "Spectacles isn't installed in the harness.\n");
    exit(1);
}

// Nothing a check does may reach a provider: the harness has no outbound network, and a real key
// would be billed. Auto-analyse would queue a paid job for every image a check creates.
Plugin::getInstance()->setSettings(['autoAnalyzeOnUpload' => false]);

/**
 * Stands in for the provider layer: text embeds to a fixed vector, and any call that would
 * analyse an image fails loudly. Counts what it was asked to do.
 */
class StubVision extends Vision
{
    public int $embedCalls = 0;

    /** @var float[] */
    public array $vector = [1.0, 0.0, 0.0, 0.0001];

    public function embedText(string $text): EmbeddingResult
    {
        $this->embedCalls++;
        return new EmbeddingResult(Plugin::getInstance()->getSettings()->getEmbeddingModel(), $this->vector, 'text');
    }

    public function analyze(string $imageData, string $mimeType): AnalysisResult
    {
        throw new RuntimeException('A check tried to call the vision provider.');
    }

    public function embedImage(string $imageData, string $mimeType): ?EmbeddingResult
    {
        throw new RuntimeException('A check tried to call the embedding provider with an image.');
    }
}

function stubVision(): StubVision
{
    $stub = new StubVision();
    Plugin::getInstance()->set('vision', $stub);
    return $stub;
}

/**
 * A tiny PNG in the harness's images volume.
 */
function makeImage(string $filename): Asset
{
    $volume = Craft::$app->getVolumes()->getVolumeByHandle('images') ?? throw new RuntimeException('No images volume.');
    $tmp = tempnam(sys_get_temp_dir(), 'spectacles') . '.png';
    $image = imagecreatetruecolor(4, 4);
    imagepng($image, $tmp);

    $asset = new Asset();
    $asset->tempFilePath = $tmp;
    $asset->filename = $filename;
    $asset->newFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)->id;
    $asset->volumeId = $volume->id;
    $asset->avoidFilenameConflicts = true;
    $asset->setScenario(Asset::SCENARIO_CREATE);

    if (!Craft::$app->getElements()->saveElement($asset)) {
        throw new RuntimeException('Asset save failed: ' . json_encode($asset->getErrors()));
    }

    return $asset;
}

function seedMetadata(int $assetId, ?array $embedding, string $model, string $description = 'a test image', array $tags = ['test']): void
{
    $record = new ImageMetadata([
        'assetId' => $assetId,
        'description' => $description,
        'tags' => $tags,
        'embedding' => $embedding,
        'embeddingModel' => $model,
    ]);
    if (!$record->save()) {
        throw new RuntimeException('Metadata save failed: ' . json_encode($record->getErrors()));
    }
}

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS' : 'FAIL') . "  $label" . (!$ok && $detail !== '' ? "\n      $detail" : '') . "\n";
}
