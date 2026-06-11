<?php

namespace justinholtweb\spectacles;

use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\elements\Asset;
use craft\events\DefineHtmlEvent;
use craft\events\ModelEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\ReplaceAssetEvent;
use craft\helpers\ElementHelper;
use craft\services\Assets;
use craft\web\UrlManager;
use craft\web\twig\variables\CraftVariable;
use justinholtweb\spectacles\jobs\AnalyzeAsset;
use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\services\Metadata;
use justinholtweb\spectacles\services\Similarity;
use justinholtweb\spectacles\services\Vision;
use justinholtweb\spectacles\variables\SpectaclesVariable;
use Throwable;
use yii\base\Event;

/**
 * @property-read Metadata $metadata
 * @property-read Similarity $similarity
 * @property-read Vision $vision
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.1.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = false;

    public static function config(): array
    {
        return [
            'components' => [
                'metadata' => Metadata::class,
                'similarity' => Similarity::class,
                'vision' => Vision::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::$app->onInit(function (): void {
            $this->attachEventHandlers();
        });
    }

    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('spectacles/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            Asset::class,
            Asset::EVENT_AFTER_SAVE,
            function (ModelEvent $event): void {
                /** @var Asset $asset */
                $asset = $event->sender;

                // Only auto-analyze brand-new uploads here. File replacements
                // are handled by the AFTER_REPLACE_ASSET listener below, and
                // ordinary edits (title, focal point, moves) must NOT re-run
                // the paid vision pipeline. Skip drafts/revisions and the
                // duplicate saves that propagation fires on multi-site setups.
                if (!$asset->firstSave || $asset->propagating || ElementHelper::isDraftOrRevision($asset)) {
                    return;
                }

                $this->maybeQueueAnalysis($asset);
            }
        );

        Event::on(
            Assets::class,
            Assets::EVENT_AFTER_REPLACE_ASSET,
            function (ReplaceAssetEvent $event): void {
                // A replaced file invalidates the old analysis — re-queue it.
                $this->maybeQueueAnalysis($event->asset);
            }
        );

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('spectacles', SpectaclesVariable::class);
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event): void {
                $event->rules['POST spectacles/search'] = 'spectacles/search/upload';
                $event->rules['GET spectacles/similar/<assetId:\d+>'] = 'spectacles/search/similar';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event): void {
                $event->rules['spectacles/reindex'] = 'spectacles/admin/reindex';
                $event->rules['spectacles/analyze-asset'] = 'spectacles/admin/analyze-asset';
            }
        );

        Event::on(
            Asset::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function (DefineHtmlEvent $event): void {
                /** @var Asset $asset */
                $asset = $event->sender;
                if ($asset->kind !== Asset::KIND_IMAGE || !$asset->id) {
                    return;
                }

                try {
                    $event->html .= $this->renderSidebar($asset);
                } catch (Throwable $e) {
                    Craft::warning('Spectacles sidebar render failed: ' . $e->getMessage(), __METHOD__);
                }
            }
        );
    }

    /**
     * Queue a vision analysis job for an asset if it is an image, auto-analyze
     * is enabled, and it lives in a configured volume.
     */
    private function maybeQueueAnalysis(Asset $asset): void
    {
        if ($asset->kind !== Asset::KIND_IMAGE || !$asset->id) {
            return;
        }

        $settings = $this->getSettings();
        if (!$settings->autoAnalyzeOnUpload) {
            return;
        }

        if (!empty($settings->volumeUids) && !in_array($asset->getVolume()->uid, $settings->volumeUids, true)) {
            return;
        }

        Craft::$app->queue->push(new AnalyzeAsset(['assetId' => $asset->id]));
    }

    private function renderSidebar(Asset $asset): string
    {
        $metadata = $this->metadata->findByAssetId($asset->id);
        $similar = $metadata
            ? $this->similarity->similarToAsset($asset, 8)
            : [];

        return Craft::$app->view->renderTemplate(
            'spectacles/_cp/asset-sidebar',
            [
                'asset' => $asset,
                'metadata' => $metadata,
                'similar' => $similar,
                'backend' => $this->similarity->backend()->name(),
            ],
            \craft\web\View::TEMPLATE_MODE_CP,
        );
    }
}
