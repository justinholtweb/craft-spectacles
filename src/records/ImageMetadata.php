<?php

namespace justinholtweb\spectacles\records;

use craft\db\ActiveRecord;
use craft\records\Asset;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $assetId
 * @property string|null $description
 * @property array|null $tags
 * @property array|null $colors
 * @property array|null $objects
 * @property array|null $rawResponse
 * @property array|null $embedding
 * @property string|null $embeddingModel
 * @property string|null $visionProvider
 * @property string|null $visionModel
 * @property string|null $analyzedAt
 */
class ImageMetadata extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%spectacles_imagemetadata}}';
    }

    public function getAsset(): ActiveQueryInterface
    {
        return $this->hasOne(Asset::class, ['id' => 'assetId']);
    }
}
