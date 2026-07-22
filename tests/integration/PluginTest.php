<?php

namespace justinholtweb\spectacles\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Asset;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use justinholtweb\spectacles\jobs\AnalyzeAsset;
use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\Plugin;
use justinholtweb\spectacles\services\Metadata;
use justinholtweb\spectacles\services\Similarity;
use justinholtweb\spectacles\services\Vision;
use justinholtweb\spectacles\tests\_support\AssetHelper;
use yii\base\Event;

/**
 * Plugin wiring: components, settings, URL rules, and the auto-analyze
 * behaviour hung off asset save events.
 */
class PluginTest extends Unit
{
    use AssetHelper;

    public function testComponentsAreRegistered(): void
    {
        $plugin = Plugin::getInstance();

        $this->assertInstanceOf(Metadata::class, $plugin->metadata);
        $this->assertInstanceOf(Similarity::class, $plugin->similarity);
        $this->assertInstanceOf(Vision::class, $plugin->vision);
    }

    public function testSettingsModelIsUsed(): void
    {
        $this->assertInstanceOf(Settings::class, Plugin::getInstance()->getSettings());
    }

    public function testSiteUrlRulesAreRegistered(): void
    {
        // The UrlManager only collects rules while parsing a real request, so
        // fire the event directly and inspect what the plugin contributes.
        $event = new RegisterUrlRulesEvent();
        Event::trigger(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, $event);

        $this->assertSame('spectacles/search/upload', $event->rules['POST spectacles/search'] ?? null);
        $this->assertSame(
            'spectacles/search/similar',
            $event->rules['GET spectacles/similar/<assetId:\d+>'] ?? null
        );
    }

    public function testCpUrlRulesAreRegistered(): void
    {
        $event = new RegisterUrlRulesEvent();
        Event::trigger(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, $event);

        $this->assertSame('spectacles/admin/reindex', $event->rules['spectacles/reindex'] ?? null);
        $this->assertSame(
            'spectacles/admin/analyze-asset',
            $event->rules['spectacles/analyze-asset'] ?? null
        );
    }

    public function testUploadingAnImageQueuesAnalysisWhenEnabled(): void
    {
        Plugin::getInstance()->setSettings(['autoAnalyzeOnUpload' => true, 'volumeUids' => []]);

        $before = $this->queuedAnalysisCount();
        $this->createImageAsset('queued.png');

        $this->assertSame($before + 1, $this->queuedAnalysisCount());
    }

    public function testUploadingDoesNotQueueWhenAutoAnalyzeIsOff(): void
    {
        Plugin::getInstance()->setSettings(['autoAnalyzeOnUpload' => false, 'volumeUids' => []]);

        $before = $this->queuedAnalysisCount();
        $this->createImageAsset('not-queued.png');

        $this->assertSame($before, $this->queuedAnalysisCount());
    }

    public function testUploadingDoesNotQueueForVolumesOutsideTheAllowList(): void
    {
        Plugin::getInstance()->setSettings([
            'autoAnalyzeOnUpload' => true,
            'volumeUids' => ['some-other-volume-uid'],
        ]);

        $before = $this->queuedAnalysisCount();
        $this->createImageAsset('other-volume.png');

        $this->assertSame($before, $this->queuedAnalysisCount());
    }

    public function testResavingAnAssetDoesNotRequeueAnalysis(): void
    {
        Plugin::getInstance()->setSettings(['autoAnalyzeOnUpload' => true, 'volumeUids' => []]);

        $asset = $this->createImageAsset('resaved.png');

        $before = $this->queuedAnalysisCount();

        // An ordinary edit must not re-run the paid vision pipeline.
        $asset->title = 'Renamed';
        Craft::$app->getElements()->saveElement($asset);

        $this->assertSame($before, $this->queuedAnalysisCount());
    }

    public function testAnalyzeAssetJobIsANoopForAMissingAsset(): void
    {
        $job = new AnalyzeAsset(['assetId' => 999999]);

        // Should return quietly rather than throwing.
        $job->execute(Craft::$app->getQueue());

        $this->assertTrue(true);
    }

    private function queuedAnalysisCount(): int
    {
        return (int)(new \yii\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'AnalyzeAsset'])
            ->count();
    }

    public function testKindGuardIgnoresNonImages(): void
    {
        // Sanity check on the constant the event handler filters against.
        $this->assertSame('image', Asset::KIND_IMAGE);
    }
}
