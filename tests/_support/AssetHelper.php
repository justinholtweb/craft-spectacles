<?php

namespace justinholtweb\spectacles\tests\_support;

use Craft;
use craft\elements\Asset;
use craft\fs\Local;
use craft\helpers\FileHelper;
use craft\models\FieldLayout;
use craft\models\Volume;
use RuntimeException;

/**
 * Creates a throwaway local volume plus real image assets.
 *
 * spectacles_imagemetadata.assetId carries a foreign key to {{%assets}}, so
 * anything that touches the metadata table needs genuine asset rows — we can't
 * fake the ids.
 */
trait AssetHelper
{
    /**
     * Resolved fresh on every call — each test runs inside a transaction that
     * is rolled back afterwards, so a volume cached in a static (or still held
     * by Craft's in-memory service cache) would point at rows that no longer
     * exist by the time the next test runs.
     */
    protected function testVolume(string $volumeHandle = 'spectaclesTest'): Volume
    {
        // Must live outside the project — Craft refuses local filesystems that
        // sit within or above its own system directories.
        $path = sys_get_temp_dir() . '/spectacles-test-volume' . ($volumeHandle === 'spectaclesTest' ? '' : '-' . $volumeHandle);
        FileHelper::createDirectory($path);

        $fsHandle = $volumeHandle . 'Fs';
        if (!Craft::$app->getFs()->getFilesystemByHandle($fsHandle)) {
            $fs = new Local([
                'handle' => $fsHandle,
                'name' => "Spectacles Test FS ($volumeHandle)",
                'path' => $path,
                'hasUrls' => false,
            ]);
            if (!Craft::$app->getFs()->saveFilesystem($fs)) {
                throw new RuntimeException(
                    'Could not save the test filesystem: ' . json_encode($fs->getErrors())
                );
            }
        }

        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);

        // A volume the service still remembers but whose folder rows were
        // rolled back is unusable — rebuild it from scratch.
        if ($volume && Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)) {
            return $volume;
        }

        $volume = new Volume([
            'name' => "Spectacles Test ($volumeHandle)",
            'handle' => $volumeHandle,
            'fsHandle' => $fsHandle,
        ]);
        $volume->setFieldLayout(new FieldLayout(['type' => Asset::class]));
        if (!Craft::$app->getVolumes()->saveVolume($volume)) {
            throw new RuntimeException(
                'Could not save the test volume: ' . json_encode($volume->getErrors())
            );
        }

        return $volume;
    }

    /**
     * Save a real (tiny) PNG into the test volume and return the Asset.
     */
    protected function createImageAsset(string $filename = 'test.png', string $volumeHandle = 'spectaclesTest'): Asset
    {
        $volume = $this->testVolume($volumeHandle);

        $tempPath = Craft::$app->path->getTempPath() . '/' . uniqid('spectacles-', true) . '.png';
        file_put_contents($tempPath, $this->onePixelPng());

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->setFilename($filename);
        $asset->newFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)->id;
        $asset->setVolumeId($volume->id);
        $asset->setScenario(Asset::SCENARIO_CREATE);
        $asset->avoidFilenameConflicts = true;

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new RuntimeException(
                'Could not save the test asset: ' . json_encode($asset->getErrors())
            );
        }

        return $asset;
    }

    /**
     * Smallest valid PNG — enough for Craft to detect kind=image.
     */
    protected function onePixelPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }
}
