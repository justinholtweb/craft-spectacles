<?php

namespace justinholtweb\spectacles;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\UrlHelper;
use craft\web\UrlManager;
use craft\web\twig\variables\CraftVariable;
use justinholtweb\spectacles\jobs\AnalyzeAsset;
use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\services\Metadata;
use justinholtweb\spectacles\services\Similarity;
use justinholtweb\spectacles\services\Vision;
use justinholtweb\spectacles\variables\SpectaclesVariable;
use yii\base\Event;

/**
 * @property-read Metadata $metadata
 * @property-read Similarity $similarity
 * @property-read Vision $vision
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
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

    public function getSettingsResponse(): \yii\web\Response
    {
        return Craft::$app->controller->redirect(UrlHelper::cpUrl('settings/plugins/spectacles'));
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            Asset::class,
            Asset::EVENT_AFTER_SAVE,
            function (ModelEvent $event): void {
                /** @var Asset $asset */
                $asset = $event->sender;

                if ($asset->kind !== Asset::KIND_IMAGE) {
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
            }
        );
    }
}
