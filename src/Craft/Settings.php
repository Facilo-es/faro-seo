<?php

namespace facilo\faro\Craft;

use craft\base\Model;
use facilo\faro\Core\TitleFormat;

/** Faro's settings, kept in project config. Per-site values are keyed by site UID. */
class Settings extends Model
{
    /** @var array<string, array{separator?: string, position?: string, siteName?: string}> site UID => title format (F3) */
    public array $titleFormats = [];
    /** @var array<string, string> site UID => default description */
    public array $defaultDescriptions = [];
    /** @var array<string, ?int> site UID => default share image asset id */
    public array $defaultImages = [];
    /** @var list<string> */
    public array $globalRobots = [];

    /** @var array<string, array{title?: list<string>, description?: list<string>, image?: list<string>}> source key => fields to try, in order (F3) */
    public array $fallbackMaps = [];

    public string $productionEnvironments = 'production';
    public string $robotsTxt = '';
    public string $metaTemplate = '';

    public string $facebookAppId = '';
    public string $xHandle = '';
    public int $imageWidth = 1200;
    public int $imageHeight = 630;

    public bool $jsonLd = true;
    public string $organizationName = '';
    public ?int $organizationLogo = null;
    /** @var list<string> */
    public array $sameAs = [];

    public ?string $xDefaultSite = null;
    public ?string $fallbackSite = null;

    public string $sitemapName = 'sitemap.xml';
    public int $sitemapLimit = 1000;
    public bool $sitemapAlternates = true;
    public bool $indexNow = false;
    public string $indexNowKey = '';

    public bool $checkBeforeRouting = false;
    public bool $uriChangeRedirects = true;
    public bool $log404s = true;
    /** @var list<string> */
    public array $botPatterns = [];

    public function titleFormat(string $siteUid): TitleFormat
    {
        return TitleFormat::fromArray($this->titleFormats[$siteUid] ?? []);
    }

    /** @return list<string> */
    public function productionList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->productionEnvironments))));
    }

    protected function defineRules(): array
    {
        return [
            [['sitemapName'], 'match', 'pattern' => '/^[a-z0-9_\-]+\.xml$/i'],
            [['sitemapLimit'], 'integer', 'min' => 100, 'max' => 50000],
            [['imageWidth', 'imageHeight'], 'integer', 'min' => 200, 'max' => 4000],
            [['xHandle'], 'match', 'pattern' => '/^@?\w{0,15}$/'],
            [['indexNowKey'], 'match', 'pattern' => '/^[a-zA-Z0-9\-]{0,128}$/'],
        ];
    }
}
