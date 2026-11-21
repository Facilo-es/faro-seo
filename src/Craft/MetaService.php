<?php

namespace facilo\faro\Craft;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\models\Site;
use facilo\faro\Core\Indexing;
use facilo\faro\Core\JsonLd;
use facilo\faro\Core\Meta;
use facilo\faro\Core\MetaInput;
use facilo\faro\Core\MetaRenderer;
use facilo\faro\Core\MetaResolver;
use facilo\faro\Core\SeoValue;
use facilo\faro\Core\ShareImage;
use facilo\faro\Core\Text;
use facilo\faro\Craft\fields\SeoField;
use facilo\faro\Plugin;
use yii\base\Component;
use yii\caching\TagDependency;

/**
 * Builds each page's meta (F3) and puts it in the page once (F5).
 * Layers, first value wins: template override, the page's SEO field, the
 * section's fallback map, the field's defaults, the site's defaults.
 */
class MetaService extends Component
{
    /** @var array<string, mixed> values from craft.faro.set(), craft.seo.custom() or Ether's `seo` variable */
    private array $override = [];
    /** @var list<array<string, mixed>> extra JSON-LD from craft.faro.schema */
    private array $schema = [];
    private bool $printed = false;
    private ?Meta $pageMeta = null;

    /** @param array<string, mixed> $values */
    public function set(array $values): void
    {
        $this->override = array_replace($this->override, $values);
        $this->pageMeta = null;
    }

    /** @param array<string, mixed> $jsonLd */
    public function addSchema(array $jsonLd): void
    {
        $this->schema[] = $jsonLd;
    }

    public function clear(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), CraftPorts::TAG . ':meta');
    }

    /** The page Craft matched, if any. */
    public function currentElement(): ?ElementInterface
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest() || !$request->getIsSiteRequest()) {
            return null;
        }
        $el = Craft::$app->getUrlManager()->getMatchedElement();
        return $el instanceof ElementInterface ? $el : null;
    }

    /** The first Faro field on the element's layout. */
    public function fieldOf(ElementInterface $element): ?SeoField
    {
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $f) {
            if ($f instanceof SeoField) {
                return $f;
            }
        }
        return null;
    }

    /** Section, category group or product type, as "type:uid". */
    public static function sourceKey(?ElementInterface $element): ?string
    {
        if ($element instanceof Entry && $element->getSection() !== null) {
            return 'section:' . $element->getSection()->uid;
        }
        if ($element instanceof Category) {
            return 'categoryGroup:' . $element->getGroup()->uid;
        }
        if (is_a($element, 'craft\\commerce\\elements\\Product')) {
            /** @phpstan-ignore-next-line Commerce is optional */
            return 'productType:' . $element->getType()->uid;
        }
        return null;
    }

    public function forElement(?ElementInterface $element, ?SeoField $field = null, bool $withOverride = false): Meta
    {
        $site = $element ? Craft::$app->getSites()->getSiteById($element->siteId) : Craft::$app->getSites()->getCurrentSite();
        $site ??= Craft::$app->getSites()->getPrimarySite();
        $field ??= $element ? $this->fieldOf($element) : null;

        $layers = [];
        if ($withOverride && $this->override !== []) {
            $layers[] = MetaInput::fromArray($this->overrideArray());
        }
        if ($element && $field) {
            $value = $element->getFieldValue($field->handle);
            if ($value instanceof FieldValue) {
                $layers[] = MetaInput::fromValue($value->value);
            }
        }
        $layers = [...$layers, ...$this->defaultLayers($element, $field, $site)];

        $expiry = $element instanceof Entry && $element->expiryDate ? \DateTimeImmutable::createFromInterface($element->expiryDate) : null;
        return MetaResolver::resolve(
            $layers,
            $element ? (string)($element->title ?? '') : '',
            $site->getName(),
            $element?->getUrl() ?? $this->currentUrl(),
            $expiry,
            $this->settings()->titleFormat($site->uid),
        );
    }

    /**
     * What the page would get with its own field empty, for the field's grey placeholders (F1).
     *
     * @return array{title: string, description: string, imageId: ?int}
     */
    public function fallbackFor(ElementInterface $element, SeoField $field): array
    {
        $site = Craft::$app->getSites()->getSiteById($element->siteId) ?? Craft::$app->getSites()->getPrimarySite();
        $meta = MetaResolver::resolve(
            $this->defaultLayers($element, $field, $site),
            (string)($element->title ?? ''),
            $site->getName(),
            (string)$element->getUrl(),
            null,
            $this->settings()->titleFormat($site->uid),
        );
        return ['title' => $meta->pageTitle, 'description' => $meta->description, 'imageId' => $meta->imageId];
    }

    /** @return list<MetaInput> section map, field defaults, site defaults */
    private function defaultLayers(?ElementInterface $element, ?SeoField $field, Site $site): array
    {
        $s = $this->settings();
        $layers = [];
        $key = self::sourceKey($element);
        if ($element && $key && isset($s->fallbackMaps[$key])) {
            $map = $s->fallbackMaps[$key];
            $layers[] = new MetaInput(
                $this->firstText($element, $map['title'] ?? [], 0),
                $this->firstText($element, $map['description'] ?? [], MetaResolver::DESCRIPTION_MAX),
                $this->firstImage($element, $map['image'] ?? []),
            );
        }
        if ($field) {
            $layers[] = new MetaInput('', $field->defaultDescription, $field->defaultImageId, [], $field->defaultRobots ?: null, '', $field->getTitleFormatObject());
        }
        $layers[] = new MetaInput('', $s->defaultDescriptions[$site->uid] ?? '', SeoValue::id($s->defaultImages[$site->uid] ?? null), [], $s->globalRobots ?: null);
        return $layers;
    }

    /** @param list<string> $handles */
    private function firstText(ElementInterface $element, array $handles, int $max): string
    {
        foreach ($handles as $h) {
            $v = $h === 'title' ? $element->title : $this->fieldValue($element, $h);
            if ($v instanceof ElementQueryInterface) {
                continue;
            }
            $text = Text::plain(is_scalar($v) || $v instanceof \Stringable ? (string)$v : '');
            if ($text !== '') {
                return $max > 0 ? Text::cut($text, $max) : $text;
            }
        }
        return '';
    }

    /** @param list<string> $handles */
    private function firstImage(ElementInterface $element, array $handles): ?int
    {
        foreach ($handles as $h) {
            $v = $this->fieldValue($element, $h);
            if ($v instanceof \craft\elements\db\AssetQuery) {
                $asset = (clone $v)->kind('image')->one();
                if ($asset instanceof Asset) {
                    return (int)$asset->id;
                }
            }
        }
        return null;
    }

    private function fieldValue(ElementInterface $element, string $handle): mixed
    {
        try {
            return $element->getFieldValue($handle);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function overrideArray(): array
    {
        $o = $this->override;
        foreach (['image', 'imageId'] as $k) {
            if (($o[$k] ?? null) instanceof Asset) {
                $o['imageId'] = (int)$o[$k]->id;
                unset($o['image']);
            }
        }
        foreach (['og', 'x'] as $n) {
            if (($o['social'][$n]['image'] ?? null) instanceof Asset) {
                $o['social'][$n]['imageId'] = (int)$o['social'][$n]['image']->id;
            }
        }
        if (is_string($o['robots'] ?? null)) {
            $o['robots'] = array_values(array_filter(array_map('trim', explode(',', $o['robots']))));
        }
        return $o;
    }

    private function currentUrl(): string
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest()) {
            return '';
        }
        return Urls::root(Craft::$app->getSites()->getCurrentSite()) . '/' . $request->getPathInfo();
    }

    public function pageMeta(): Meta
    {
        return $this->pageMeta ??= $this->forElement($this->currentElement(), null, true);
    }

    /** The full tag list for the current page, cached per element and site unless overridden. */
    public function pageTags(): array
    {
        $element = $this->currentElement();
        $cacheable = $element !== null && $this->override === [] && $this->schema === [];
        $key = $cacheable ? 'faro-meta:' . $element->id . ':' . $element->siteId : null;
        $cache = Craft::$app->getCache();
        if ($key !== null && is_array($tags = $cache->get($key))) {
            return $tags;
        }
        $tags = $this->buildTags($element, $this->pageMeta());
        if ($key !== null) {
            $cache->set($key, $tags, 0, new TagDependency(['tags' => [CraftPorts::TAG, CraftPorts::TAG . ':meta']]));
        }
        return $tags;
    }

    /** @return list<array{0: string, 1: array<string, string>}> */
    public function buildTags(?ElementInterface $element, Meta $meta): array
    {
        $s = $this->settings();
        $sites = Craft::$app->getSites();
        $site = $element ? $sites->getSiteById($element->siteId) : $sites->getCurrentSite();
        $site ??= $sites->getPrimarySite();

        $images = [];
        foreach (SeoValue::NETWORKS as $n) {
            $images[$n] = $this->shareImage($meta->social[$n]['imageId'] ?? $meta->imageId);
        }
        [$alternates, $xDefault] = $element ? $this->alternates($element) : [[], null];

        $jsonLd = [];
        if ($s->jsonLd) {
            $base = Urls::root($site) . '/';
            $jsonLd[] = JsonLd::website($site->getName(), $base, $site->language);
            $logo = $s->organizationLogo ? Craft::$app->getAssets()->getAssetById($s->organizationLogo)?->getUrl() : '';
            $jsonLd[] = JsonLd::organization($s->organizationName !== '' ? $s->organizationName : $site->getName(), $base, (string)$logo, $s->sameAs);
            if ($element && $element->uri && $element->uri !== '__home__') {
                $crumbs = JsonLd::breadcrumbs($base, $site->getName(), $element->uri, $this->crumbNames($element));
                if ($crumbs !== null) {
                    $jsonLd[] = $crumbs;
                }
            }
        }
        foreach ($this->schema as $extra) {
            $jsonLd[] = $extra;
        }

        return MetaRenderer::tags($meta, $site->getName(), $site->language, $images, $alternates, $xDefault, $s->facebookAppId, $s->xHandle, $jsonLd);
    }

    /** @return array<string, string> uri => title of each ancestor and the page */
    private function crumbNames(ElementInterface $element): array
    {
        $names = [];
        if ($element instanceof Entry || $element instanceof Category) {
            foreach ($element->getAncestors()->all() as $a) {
                if ($a->uri) {
                    $names[$a->uri] = (string)$a->title;
                }
            }
        }
        $names[(string)$element->uri] = (string)$element->title;
        return $names;
    }

    public function shareImage(?int $id): ?ShareImage
    {
        if ($id === null) {
            return null;
        }
        $asset = Craft::$app->getAssets()->getAssetById($id);
        if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE) {
            return null;
        }
        $s = $this->settings();
        $transform = ['width' => $s->imageWidth, 'height' => $s->imageHeight, 'mode' => 'crop'];
        $url = (string)$asset->getUrl($transform, true);
        if ($url === '') {
            return null;
        }
        $w = $asset->getWidth() !== null ? min($s->imageWidth, (int)$asset->getWidth()) : $s->imageWidth;
        $h = $asset->getHeight() !== null ? min($s->imageHeight, (int)$asset->getHeight()) : $s->imageHeight;
        return new ShareImage($url, $w, $h, (string)($asset->alt ?? ''), (string)$asset->getMimeType());
    }

    /**
     * hreflang alternates for each site the page is live in (F5).
     *
     * @return array{0: array<string, string>, 1: ?string}
     */
    public function alternates(ElementInterface $element): array
    {
        $urls = [];
        $bySite = [];
        $others = $element::find()->id($element->id)->siteId('*')->status(Element::STATUS_ENABLED)->all();
        foreach ($others as $o) {
            $url = $o->getUrl();
            $site = $o->getSite();
            if ($url && !$this->noindex($o)) {
                $urls[$site->language] ??= $url;
                $bySite[$site->uid] = $url;
            }
        }
        if (count($urls) < 2) {
            return [[], null];
        }
        $s = $this->settings();
        $xDefault = $bySite[$s->xDefaultSite ?? ''] ?? $bySite[$s->fallbackSite ?? ''] ?? $bySite[Craft::$app->getSites()->getPrimarySite()->uid] ?? reset($urls);
        return [$urls, $xDefault];
    }

    private function noindex(ElementInterface $element): bool
    {
        $field = $this->fieldOf($element);
        if ($field === null) {
            return false;
        }
        $v = $element->getFieldValue($field->handle);
        return $v instanceof FieldValue && in_array('noindex', $v->value->robots, true);
    }

    /** The meta HTML for this page, using the custom meta template when set. */
    public function html(): string
    {
        $tpl = trim($this->settings()->metaTemplate);
        if ($tpl !== '') {
            $view = Craft::$app->getView();
            $element = $this->currentElement();
            try {
                return $view->renderTemplate($tpl, [
                    'meta' => $this->pageMeta(),
                    'seo' => $element && ($f = $this->fieldOf($element)) ? $element->getFieldValue($f->handle) : null,
                    'faroTags' => $this->pageTags(),
                ]);
            } catch (\Throwable $e) {
                Craft::warning("Faro meta template failed: {$e->getMessage()}", 'faro-seo');
            }
        }
        return MetaRenderer::html($this->pageTags());
    }

    /** {% hook "faro" %} and {% hook "seo" %}: print here, and not again in head(). */
    public function hook(array &$context = []): string
    {
        if ($this->printed) {
            return '';
        }
        $seo = $context['seo'] ?? null;
        // The page's own field is already a layer; only another element's value overrides.
        if ($seo instanceof FieldValue && $seo->element !== null && $seo->element->id === $this->currentElement()?->id) {
            $seo = null;
        } elseif ($seo instanceof FieldValue) {
            $seo = array_filter($seo->value->toArray(), fn($v) => $v !== '' && $v !== [] && $v !== null);
            unset($seo['v'], $seo['keywords']);
        }
        if (is_array($seo)) {
            $this->set($seo);
        }
        $this->printed = true;
        return $this->html();
    }

    /** Called before the page's head block is filled in. */
    public function registerHead(): void
    {
        if ($this->printed || !$this->isHtmlPage()) {
            return;
        }
        $this->printed = true;
        $view = Craft::$app->getView();
        $html = $this->html();
        $view->registerHtml($html, \yii\web\View::POS_HEAD, 'faro-seo');
    }

    public function sendHeaders(): void
    {
        if (!$this->isHtmlPage()) {
            return;
        }
        $canonical = $this->pageMeta()->canonical;
        if ($canonical !== '') {
            Craft::$app->getResponse()->getHeaders()->add('Link', Indexing::linkHeader($canonical));
        }
    }

    private function isHtmlPage(): bool
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest() || !$request->getIsSiteRequest() || $request->getIsActionRequest()) {
            return false;
        }
        $response = Craft::$app->getResponse();
        return $response->format === \yii\web\Response::FORMAT_HTML && $response->getStatusCode() < 400;
    }

    /** Values for the field preview in the control panel. */
    public function previewData(ElementInterface $element, SeoValue $value, ?SeoField $field): array
    {
        $meta = MetaResolver::resolve(
            [MetaInput::fromValue($value), ...$this->defaultLayers($element, $field ?? $this->fieldOf($element), Craft::$app->getSites()->getSiteById($element->siteId) ?? Craft::$app->getSites()->getPrimarySite())],
            (string)$element->title,
            (string)Craft::$app->getSites()->getSiteById($element->siteId)?->getName(),
            (string)$element->getUrl(),
        );
        $images = [];
        foreach (SeoValue::NETWORKS as $n) {
            $images[$n] = $this->shareImage($meta->social[$n]['imageId'] ?? $meta->imageId)?->url;
        }
        return ['title' => $meta->title, 'description' => $meta->description, 'social' => $meta->social, 'images' => $images, 'url' => $meta->canonical];
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
