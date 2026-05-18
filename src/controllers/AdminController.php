<?php

namespace justinholtweb\spectacles\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use justinholtweb\spectacles\jobs\AnalyzeAsset;
use justinholtweb\spectacles\Plugin;
use yii\web\Response;

class AdminController extends Controller
{
    /**
     * Queue an analysis job for every image asset in the configured volumes
     * (or all volumes if none are configured).
     */
    public function actionReindex(): Response
    {
        $this->requirePermission('utility:queue-manager');

        $settings = Plugin::getInstance()->getSettings();

        $query = Asset::find()->kind(Asset::KIND_IMAGE);
        if (!empty($settings->volumeUids)) {
            $query->volumeId(
                array_filter(array_map(
                    fn(string $uid): ?int => Craft::$app->volumes->getVolumeByUid($uid)?->id,
                    $settings->volumeUids
                ))
            );
        }

        $count = 0;
        foreach ($query->each() as $asset) {
            Craft::$app->queue->push(new AnalyzeAsset(['assetId' => $asset->id]));
            $count++;
        }

        Craft::$app->session->setNotice(
            Craft::t('spectacles', 'Queued {count} assets for analysis.', ['count' => $count])
        );

        return $this->redirect('settings/plugins/spectacles');
    }
}
