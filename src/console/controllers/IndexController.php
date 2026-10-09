<?php

namespace justinholtweb\spectacles\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use InvalidArgumentException;
use justinholtweb\spectacles\Plugin;
use justinholtweb\spectacles\services\Indexer;
use yii\console\ExitCode;

/**
 * Queues image analysis from the command line — the settings screen's **Re-index all images**
 * button, with a volume filter, a cap and a dry run, so a deploy script can seed an index and
 * bound what one run spends.
 *
 *     php craft spectacles/index --missing-only --limit=500
 *     php craft spectacles/index --volume=photos,products --dry-run
 */
class IndexController extends Controller
{
    /** @var string|null Comma-separated handles of the volumes to index. Default: every volume Spectacles analyses. */
    public ?string $volume = null;

    /** @var bool Only images that have no vector yet (never analysed, or the embedding call failed). */
    public bool $missingOnly = false;

    /** @var int|null Queue at most this many images. */
    public ?int $limit = null;

    /** @var bool Count the images and the provider calls they'd cost, and queue nothing. */
    public bool $dryRun = false;

    public $defaultAction = 'index';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['volume', 'missingOnly', 'limit', 'dryRun']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['n' => 'dryRun']);
    }

    /**
     * Queues analysis for the images in the analysed volumes.
     */
    public function actionIndex(): int
    {
        return $this->runIndex(
            $this->missingOnly ? Indexer::SCOPE_MISSING : Indexer::SCOPE_ALL,
            $this->volume,
            $this->limit,
            $this->dryRun,
        );
    }

    /**
     * Shared by `spectacles/index` and `spectacles/reindex`.
     */
    protected function runIndex(string $scope, ?string $volume, ?int $limit, bool $dryRun): int
    {
        if ($limit !== null && $limit < 1) {
            $this->stderr("--limit must be 1 or more.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $indexer = Plugin::getInstance()->indexer;

        try {
            $volumes = $indexer->resolveVolumes($volume);
        } catch (InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if ($volumes === []) {
            $this->stdout("No volumes to index.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $ids = $indexer->candidateIds($volumes, $scope, $limit);
        $count = count($ids);
        $calls = $count * Indexer::CALLS_PER_IMAGE;
        $names = implode(', ', array_map(static fn($v) => $v->name, $volumes));

        $this->stdout(sprintf(
            "%d %s in %s → about %d provider %s (%d vision + %d embedding).\n",
            $count,
            $count === 1 ? 'image' : 'images',
            $names,
            $calls,
            $calls === 1 ? 'call' : 'calls',
            $count,
            $count,
        ));

        if ($dryRun) {
            $this->stdout("Dry run — nothing queued.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if ($count === 0) {
            $this->stdout("Nothing to queue.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $indexer->queue($ids);
        $this->stdout("Queued {$count} analysis " . ($count === 1 ? 'job' : 'jobs') . ".\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
