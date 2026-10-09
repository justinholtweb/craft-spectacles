<?php

namespace justinholtweb\spectacles;

use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\elements\Asset;
use craft\events\DefineHtmlEvent;
use craft\events\ModelEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\ReplaceAssetEvent;
use craft\helpers\ElementHelper;
use craft\services\Assets;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\twig\variables\CraftVariable;
use justinholtweb\spectacles\gql\queries\SpectaclesQueries;
use justinholtweb\spectacles\gql\types\ResultType;
use justinholtweb\spectacles\jobs\AnalyzeAsset;
use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\services\Indexer;
use justinholtweb\spectacles\services\Metadata;
use justinholtweb\spectacles\services\Similarity;
use justinholtweb\spectacles\services\Vision;
use justinholtweb\spectacles\variables\SpectaclesVariable;
use Throwable;
use yii\base\Event;

/**
 * @property-read Indexer $indexer
 * @property-read Metadata $metadata
 * @property-read Similarity $similarity
 * @property-read Vision $vision
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** Queue a re-analysis of every image — paid API spend, so not something "queue manager" implies. */
    public const PERMISSION_REINDEX = 'spectacles:reindex';

    public string $schemaVersion = '1.1.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = false;

    public static function config(): array
    {
        return [
            'components' => [
                'indexer' => Indexer::class,
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

        // The admin actions are POST-only since 5.1.0, posted by the CP buttons as actions; the
        // GET-style CP routes they used to have are gone.

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('spectacles', 'Spectacles'),
                    'permissions' => [
                        self::PERMISSION_REINDEX => [
                            'label' => Craft::t('spectacles', 'Re-index every image (spends on the configured AI providers)'),
                        ],
                    ],
                ];
            }
        );

        $this->registerGraphQl();

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
     * `spectaclesSimilar` and `spectaclesSearch`, behind one schema component per volume (the
     * GraphQL counterpart of **Public volumes**) plus one for text search, which spends on the
     * embedding provider per query. See {@see SpectaclesQueries}.
     */
    private function registerGraphQl(): void
    {
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_TYPES,
            function (RegisterGqlTypesEvent $event): void {
                $event->types[] = ResultType::class;
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            function (RegisterGqlQueriesEvent $event): void {
                $event->queries = array_merge($event->queries, SpectaclesQueries::getQueries());
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
            function (RegisterGqlSchemaComponentsEvent $event): void {
                $components = [];
                foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
                    $components[SpectaclesQueries::VOLUME_COMPONENT . ".{$volume->uid}:read"] = [
                        'label' => Craft::t('spectacles', 'Find similar images in the “{name}” volume', ['name' => $volume->name]),
                    ];
                }
                $components[SpectaclesQueries::TEXT_SEARCH_COMPONENT . ':read'] = [
                    'label' => Craft::t('spectacles', 'Search those volumes by text (one paid embedding call per query)'),
                ];

                $event->queries[Craft::t('spectacles', 'Spectacles')] = $components;
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
        // Only what this user may see: the index spans every analysed volume, and a thumbnail and
        // title from a volume they cannot open is a leak however small it is drawn.
        $elements = Craft::$app->getElements();
        $similar = $metadata
            ? array_slice(array_values(array_filter(
                $this->similarity->similarToAsset($asset, 24),
                static fn(array $row): bool => $elements->canView($row['asset']),
            )), 0, 8)
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
