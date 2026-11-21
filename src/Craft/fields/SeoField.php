<?php

namespace facilo\faro\Craft\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\base\SortableFieldInterface;
use craft\helpers\Html;
use craft\helpers\Json;
use facilo\faro\Core\SeoValue;
use facilo\faro\Core\TitleFormat;
use facilo\faro\Craft\FieldValue;
use facilo\faro\Craft\web\FieldAsset;
use facilo\faro\Plugin;
use yii\db\Schema;

/**
 * The "Faro SEO" field (F1). Reads Ether SEO's stored shape as well as its own,
 * so a field switched by project config works at once (F8).
 */
class SeoField extends Field implements PreviewableFieldInterface, SortableFieldInterface
{
    /** @var array<string, mixed>|null */
    public ?array $titleFormat = null;
    public string $defaultDescription = '';
    public ?int $defaultImageId = null;
    /** @var list<string> */
    public array $defaultRobots = [];
    public bool $hideSocial = false;
    public bool $hideAdvanced = false;
    /** @var list<array<string, mixed>> Ether's title tokens, kept to read Ether-shaped values */
    public array $etherTokens = [];

    public static function displayName(): string
    {
        return Craft::t('faro-seo', 'Faro SEO');
    }

    public static function icon(): string
    {
        return 'magnifying-glass';
    }

    public static function dbType(): array
    {
        return [
            'title' => Schema::TYPE_TEXT,
            'description' => Schema::TYPE_TEXT,
            'score' => Schema::TYPE_INTEGER,
        ];
    }

    public function setAttributes($values, $safeOnly = true): void
    {
        if (isset($values['defaultImageId']) && is_array($values['defaultImageId'])) {
            $values['defaultImageId'] = $values['defaultImageId'][0] ?? null;
        }
        if (isset($values['defaultImageId']) && $values['defaultImageId'] === '') {
            $values['defaultImageId'] = null;
        }
        if (isset($values['defaultRobots']) && !is_array($values['defaultRobots'])) {
            $values['defaultRobots'] = [];
        }
        parent::setAttributes($values, $safeOnly);
    }

    public function getTitleFormatObject(): ?TitleFormat
    {
        return $this->titleFormat ? TitleFormat::fromArray($this->titleFormat) : null;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('faro-seo/_field/settings', [
            'field' => $this,
            'separators' => TitleFormat::SEPARATORS,
            'robots' => SeoValue::ROBOTS,
        ]);
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof FieldValue) {
            return $value;
        }
        $raw = is_string($value) ? Json::decodeIfJson($value) : $value;
        // The input posts the social and robots parts as JSON in hidden inputs.
        if (is_array($raw) && isset($raw['json']) && is_string($raw['json'])) {
            $raw = Json::decodeIfJson($raw['json']);
        }
        $raw = is_array($raw) ? $raw : null;
        $out = new FieldValue(SeoValue::from($raw, $this->etherTokens), $element, $this);
        $out->score = isset($raw['score']) && is_numeric($raw['score']) ? (int)$raw['score'] : null;
        return $out;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        return $value instanceof FieldValue ? $value->serialize() : parent::serializeValue($value, $element);
    }

    public function getSortOption(): array
    {
        $option = parent::getSortOption();
        $option['orderBy'] = $this->getValueSql('score');
        return $option;
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        /** @var FieldValue $value */
        $view = Craft::$app->getView();
        $view->registerAssetBundle(FieldAsset::class);
        $plugin = Plugin::getInstance();
        $fallback = $element ? $plugin->meta->fallbackFor($element, $this) : null;
        $id = Html::id($this->handle);
        $images = [];
        foreach (['og', 'x'] as $n) {
            $iid = $value->value->social[$n]['imageId'] ?? null;
            $images[$n] = $iid ? Craft::$app->getAssets()->getAssetById($iid) : null;
        }

        return $view->renderTemplate('faro-seo/_field/input', [
            'id' => $id,
            'name' => $this->handle,
            'field' => $this,
            'value' => $value->value,
            'score' => $value->score,
            'fallback' => $fallback,
            'images' => $images,
            'element' => $element,
            'robotsOptions' => SeoValue::ROBOTS,
            'previewUrl' => $element?->getUrl(),
            'siteName' => $element ? Craft::$app->getSites()->getSiteById($element->siteId)?->getName() : '',
        ]);
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        $score = $value instanceof FieldValue ? $value->score : null;
        // Element indexes don't load Faro's CSS, so the dot carries its own colour.
        $colours = ['good' => '#0d9488', 'fair' => '#d97706', 'poor' => '#dc2626', 'none' => '#cbd5e1'];
        $band = $score === null ? 'none' : ($score >= 80 ? 'good' : ($score >= 50 ? 'fair' : 'poor'));
        $label = $score === null ? Craft::t('faro-seo', 'Not checked yet') : Craft::t('faro-seo', ['good' => 'Good', 'fair' => 'Needs work', 'poor' => 'Poor'][$band]);
        $dot = Html::tag('span', '', [
            'class' => "faro-dot $band",
            'style' => "display:inline-block;width:8px;height:8px;border-radius:50%;vertical-align:middle;background:{$colours[$band]}",
            'title' => $score === null ? $label : "$label ($score)",
        ]);
        return Html::tag('span', $score === null ? $dot : $dot . ' ' . Html::encode($label), ['class' => 'faro']);
    }

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        return $value instanceof FieldValue ? trim($value->value->title . ' ' . $value->value->description . ' ' . implode(' ', $value->value->keywords)) : '';
    }

    public function getContentGqlType(): \GraphQL\Type\Definition\Type|array
    {
        return [
            'name' => $this->handle,
            'type' => \facilo\faro\Craft\gql\SeoDataType::getType(),
            'resolve' => fn($source) => $source->getFieldValue($this->handle),
        ];
    }
}
