<?php

namespace facilo\faro\Craft;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use facilo\faro\Core\Meta;
use facilo\faro\Core\SeoValue;
use facilo\faro\Plugin;
use Twig\Markup;

/**
 * An SEO field's value. `value` is what the editor typed; the getters give
 * the final page values, with Ether SEO's names (F9): title, description,
 * social.twitter, social.facebook, robots, canonical, expiry, advanced.
 *
 * @property-read Markup $title
 * @property-read Markup $description
 * @property-read array<string, SocialValue> $social
 * @property-read ?string $robots
 * @property-read string $canonical
 * @property-read ?string $expiry
 */
class FieldValue extends \yii\base\BaseObject
{
    public ?int $score = null;
    private ?Meta $meta = null;

    public function __construct(
        public readonly SeoValue $value,
        public readonly ?ElementInterface $element = null,
        public readonly ?fields\SeoField $field = null,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    public function getMeta(): Meta
    {
        return $this->meta ??= Plugin::getInstance()->meta->forElement($this->element, $this->field);
    }

    public function getTitle(): Markup
    {
        return new Markup($this->element ? $this->getMeta()->title : $this->value->title, 'UTF-8');
    }

    public function getDescription(): Markup
    {
        return new Markup($this->element ? $this->getMeta()->description : $this->value->description, 'UTF-8');
    }

    /** @return list<array{keyword: string, rating: string}> Ether's shape */
    public function getKeywords(): array
    {
        return array_map(static fn(string $k) => ['keyword' => $k, 'rating' => ''], $this->value->keywords);
    }

    /** @return array<string, SocialValue> */
    public function getSocial(): array
    {
        $meta = $this->element ? $this->getMeta() : null;
        $out = [];
        foreach (['og' => 'facebook', 'x' => 'twitter'] as $n => $ether) {
            $s = $meta?->social[$n] ?? $this->value->social[$n];
            $out[$ether] = $out[$n] = new SocialValue($ether, (string)$s['title'], (string)$s['description'], $s['imageId']);
        }
        return $out;
    }

    public function getRobots(): ?string
    {
        $robots = $this->element ? $this->getMeta()->robots : $this->value->robots;
        return $robots === [] ? null : implode(', ', $robots);
    }

    public function getCanonical(): string
    {
        return $this->element ? $this->getMeta()->canonical : $this->value->canonical;
    }

    public function getExpiry(): ?string
    {
        $d = $this->element->expiryDate ?? null;
        return $d instanceof \DateTimeInterface ? $d->format(DATE_RFC850) : null;
    }

    /** @return array{robots: list<string>, canonical: string} */
    public function getAdvanced(): array
    {
        return ['robots' => $this->value->robots, 'canonical' => $this->value->canonical];
    }

    public function getImage(): ?Asset
    {
        $id = $this->element ? $this->getMeta()->imageId : null;
        return $id ? Craft::$app->getAssets()->getAssetById($id) : null;
    }

    public function __toString(): string
    {
        return (string)$this->getTitle();
    }

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return $this->value->toArray() + ['score' => $this->score];
    }
}
