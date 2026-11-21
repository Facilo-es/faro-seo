<?php

namespace facilo\faro\Craft;

use Craft;
use craft\elements\Asset;

/** One network's share values, with Ether's SocialData names (handle, title, description, imageId, image). */
class SocialValue extends \yii\base\BaseObject
{
    public function __construct(
        public string $handle,
        public string $title = '',
        public string $description = '',
        public ?int $imageId = null,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    public function getImage(): ?Asset
    {
        return $this->imageId ? Craft::$app->getAssets()->getAssetById($this->imageId) : null;
    }
}
