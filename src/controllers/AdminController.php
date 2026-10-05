<?php

namespace justinholtweb\spectacles\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use justinholtweb\spectacles\jobs\AnalyzeAsset;
use justinholtweb\spectacles\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class AdminController extends Controller
{
    /**
     * Queue an analysis job for every image asset in the configured volumes
     * (or all volumes if none are configured).
     */
    public function actionReindex(): Response
    {
        // POST, and a permission of its own: this queues a paid analysis of every image. Before
        // 5.1.0 it was a GET link, so an <img src> on any page an admin visited could run it.
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_REINDEX);

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

        $message = Craft::t('spectacles', 'Queued {count} assets for analysis.', ['count' => $count]);

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess($message, ['count' => $count]);
        }

        Craft::$app->session->setNotice($message);

        return $this->redirect('settings/plugins/spectacles');
    }

    /**
     * Queue an analysis job for a single asset, then return to the asset
     * edit page. Used by the "Analyze now" button in the sidebar.
     */
    public function actionAnalyzeAsset(): Response
    {
        // POST, and the right to save the asset rather than merely see it: analysing spends on a
        // paid API and writes the asset's metadata. Before 5.1.0 it was a GET link gated on
        // viewing.
        $this->requirePostRequest();

        $assetId = (int)Craft::$app->request->getRequiredBodyParam('assetId');
        $asset = Asset::find()->id($assetId)->one();
        if (!$asset) {
            throw new NotFoundHttpException('Asset not found.');
        }
        if (!Craft::$app->getElements()->canSave($asset)) {
            throw new \yii\web\ForbiddenHttpException('User is not authorized to analyse this asset.');
        }

        Craft::$app->queue->push(new AnalyzeAsset(['assetId' => $asset->id]));

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('spectacles', 'Analysis queued.'));
        }

        Craft::$app->session->setNotice(Craft::t('spectacles', 'Analysis queued.'));

        return $this->redirect($asset->getCpEditUrl() ?: 'assets');
    }
}
