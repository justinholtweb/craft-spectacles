<?php

namespace justinholtweb\spectacles\console\controllers;

use craft\console\Controller;
use justinholtweb\spectacles\Plugin;
use yii\console\ExitCode;

/**
 * How much of each analysed volume can be searched.
 *
 *     php craft spectacles/status
 */
class StatusController extends Controller
{
    public $defaultAction = 'index';

    /**
     * Prints analysed, unanalysed and wrong-model counts per volume.
     */
    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $this->stdout(sprintf(
            "Embedding: %s (%s) · index backend: %s\n\n",
            $settings->embeddingProvider,
            $settings->getEmbeddingModel(),
            $plugin->similarity->backend()->name(),
        ));

        $rows = [];
        $totals = ['images' => 0, 'analysed' => 0, 'missing' => 0, 'mismatched' => 0];
        foreach ($plugin->indexer->status() as $row) {
            $rows[] = [$row['volume']->handle, $row['images'], $row['analysed'], $row['missing'], $row['mismatched']];
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $row[$key];
            }
        }

        if ($rows === []) {
            $this->stdout("No volumes to index.\n");
            return ExitCode::OK;
        }

        $rows[] = ['(total)', $totals['images'], $totals['analysed'], $totals['missing'], $totals['mismatched']];
        $this->table(['Volume', 'Images', 'Analysed', 'Unanalysed', 'Other model'], $rows);

        if ($totals['missing'] > 0) {
            $this->stdout("\nIndex the unanalysed images with: php craft spectacles/index --missing-only\n");
        }
        if ($totals['mismatched'] > 0) {
            $this->stdout("Re-index the other-model images with: php craft spectacles/reindex --dry-run=0\n");
        }

        return ExitCode::OK;
    }
}
