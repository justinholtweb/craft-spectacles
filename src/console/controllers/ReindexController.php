<?php

namespace justinholtweb\spectacles\console\controllers;

use justinholtweb\spectacles\Plugin;
use justinholtweb\spectacles\services\Indexer;

/**
 * Re-analyses the images whose vectors came from a different embedding model — what's left behind
 * by a provider or model switch, and can't be found until it's redone. Images already on the
 * current model are left alone.
 *
 * A dry run unless told otherwise, because every image it picks is a paid call:
 *
 *     php craft spectacles/reindex                 # counts only
 *     php craft spectacles/reindex --dry-run=0     # queues
 */
class ReindexController extends IndexController
{
    /** @var bool Count the images and the provider calls they'd cost, and queue nothing. On by default; pass --dry-run=0 to queue. */
    public bool $dryRun = true;

    public function options($actionID): array
    {
        return array_values(array_diff(parent::options($actionID), ['missingOnly']));
    }

    /**
     * Queues re-analysis for images whose vector is from another embedding model.
     */
    public function actionIndex(): int
    {
        $model = Plugin::getInstance()->getSettings()->getEmbeddingModel();
        $this->stdout("Current embedding model: {$model}\n");

        return $this->runIndex(Indexer::SCOPE_MISMATCHED, $this->volume, $this->limit, $this->dryRun);
    }
}
