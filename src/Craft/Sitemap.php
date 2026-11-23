<?php

namespace facilo\faro\Craft;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\models\Site;
use facilo\faro\Core\SeoValue;
use facilo\faro\Core\SitemapUrl;
use facilo\faro\Core\SitemapWriter;
use facilo\faro\Craft\jobs\IndexNowJob;
use facilo\faro\migrations\Install;
use facilo\faro\Plugin;
use yii\base\Component;
use yii\caching\TagDependency;

/**
 * The sitemap (F6): an index with one child per section, category group,
 * product type and custom list, split at the limit.
 */
class Sitemap extends Component
{
    /** Short names in child file names. */
    public const SLUGS = ['section' => 'section', 'categoryGroup' => 'category', 'productType' => 'product'];

    /**
     * Every source that can have URLs, per site, with its UID and name.
     *
     * @return list<array{type: string, uid: string, id: int, name: string, siteId: int}>
     */
    public function available(): array
    {
        $out = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            foreach ($section->getSiteSettings() as $ss) {
                if ($ss->hasUrls) {
                    $out[] = ['type' => 'section', 'uid' => $section->uid, 'id' => (int)$section->id, 'name' => $section->name, 'siteId' => (int)$ss->siteId];
                }
            }
        }
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            foreach ($group->getSiteSettings() as $ss) {
                if ($ss->hasUrls) {
                    $out[] = ['type' => 'categoryGroup', 'uid' => $group->uid, 'id' => (int)$group->id, 'name' => $group->name, 'siteId' => (int)$ss->siteId];
                }
            }
        }
        if (class_exists('craft\\commerce\\Plugin') && Craft::$app->getPlugins()->isPluginEnabled('commerce')) {
            /** @phpstan-ignore-next-line Commerce is optional */
            foreach (\craft\commerce\Plugin::getInstance()->getProductTypes()->getAllProductTypes() as $type) {
                foreach ($type->getSiteSettings() as $ss) {
                    if ($ss->hasUrls) {
                        $out[] = ['type' => 'productType', 'uid' => $type->uid, 'id' => (int)$type->id, 'name' => $type->name, 'siteId' => (int)$ss->siteId];
                    }
                }
            }
        }
        return $out;
    }

    /** Adds a row, switched on, for each source that has none yet. */
    public function syncSources(bool $force = true): void
    {
        $added = false;
        $have = [];
        foreach ((new Query())->select(['siteId', 'sourceType', 'sourceUid'])->from(Install::SITEMAP_SOURCES)->all() as $r) {
            $have[$r['siteId'] . '|' . $r['sourceType'] . '|' . $r['sourceUid']] = true;
        }
        foreach ($this->available() as $a) {
            if (!isset($have[$a['siteId'] . '|' . $a['type'] . '|' . $a['uid']])) {
                Db::insert(Install::SITEMAP_SOURCES, [
                    'siteId' => $a['siteId'], 'sourceType' => $a['type'], 'sourceUid' => $a['uid'],
                    'enabled' => true, 'changefreq' => 'weekly', 'priority' => 0.5, 'uid' => StringHelper::UUID(),
                ]);
                $added = true;
            }
        }
        if ($added || $force) {
            $this->clear();
        }
    }

    public function clear(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), CraftPorts::TAG . ':sitemap');
    }

    /** Rows for the Sitemap screen, joined to the source names. */
    public function sourceRows(int $siteId): array
    {
        $names = [];
        foreach ($this->available() as $a) {
            $names[$a['type'] . '|' . $a['uid']] = $a['name'];
        }
        $rows = (new Query())->from(Install::SITEMAP_SOURCES)->where(['siteId' => $siteId])->orderBy(['sourceType' => SORT_DESC, 'id' => SORT_ASC])->all();
        foreach ($rows as $i => $r) {
            $rows[$i]['name'] = $names[$r['sourceType'] . '|' . $r['sourceUid']] ?? null;
        }
        return array_values(array_filter($rows, fn($r) => $r['name'] !== null));
    }

    public function customRows(int $siteId): array
    {
        return (new Query())->from(Install::SITEMAP_URLS)->where(['siteId' => $siteId])->orderBy(['id' => SORT_ASC])->all();
    }

    /** @param array<int, array{enabled?: mixed, changefreq?: string, priority?: mixed}> $sources id => values */
    public function saveSources(array $sources): void
    {
        foreach ($sources as $id => $v) {
            Db::update(Install::SITEMAP_SOURCES, [
                'enabled' => (bool)($v['enabled'] ?? false),
                'changefreq' => SitemapWriter::freq((string)($v['changefreq'] ?? 'weekly')),
                'priority' => SitemapWriter::priority($v['priority'] ?? 0.5),
            ], ['id' => (int)$id]);
        }
        $this->clear();
    }

    /** @param list<array{url?: string, changefreq?: string, priority?: mixed, enabled?: mixed}> $urls */
    public function saveCustom(int $siteId, array $urls): void
    {
        Db::delete(Install::SITEMAP_URLS, ['siteId' => $siteId]);
        foreach ($urls as $u) {
            $url = trim((string)($u['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $this->addCustom($siteId, $url, (string)($u['changefreq'] ?? 'weekly'), $u['priority'] ?? 0.5, (bool)($u['enabled'] ?? true));
        }
        $this->clear();
    }

    public function addCustom(int $siteId, string $url, string $freq = 'weekly', mixed $priority = 0.5, bool $enabled = true): bool
    {
        if ((new Query())->from(Install::SITEMAP_URLS)->where(['siteId' => $siteId, 'url' => $url])->exists()) {
            return false;
        }
        Db::insert(Install::SITEMAP_URLS, [
            'siteId' => $siteId, 'url' => $url, 'changefreq' => SitemapWriter::freq($freq),
            'priority' => SitemapWriter::priority($priority), 'enabled' => $enabled, 'uid' => StringHelper::UUID(),
        ]);
        return true;
    }

    public function baseName(): string
    {
        return substr(Plugin::getInstance()->getSettings()->sitemapName, 0, -4);
    }

    /** @return list<array{loc: string, lastmod: ?\DateTimeInterface}> */
    public function children(Site $site): array
    {
        $limit = max(1, Plugin::getInstance()->getSettings()->sitemapLimit);
        $base = Urls::root($site) . '/' . $this->baseName();
        $children = [];
        $rows = (new Query())->from(Install::SITEMAP_SOURCES)->where(['siteId' => $site->id, 'enabled' => true])->orderBy(['id' => SORT_ASC])->all();
        foreach ($rows as $r) {
            $query = $this->query($r, $site);
            if ($query === null) {
                continue;
            }
            $count = (int)$query->count();
            $last = $count > 0 ? (clone $query)->orderBy(['elements.dateUpdated' => SORT_DESC])->one()?->dateUpdated : null;
            $pages = SitemapWriter::pages($count, $limit);
            for ($p = 1; $p <= $pages; $p++) {
                $children[] = ['loc' => "$base-" . self::SLUGS[$r['sourceType']] . "-{$r['id']}-$p.xml", 'lastmod' => $last];
            }
        }
        if ((new Query())->from(Install::SITEMAP_URLS)->where(['siteId' => $site->id, 'enabled' => true])->exists()) {
            $children[] = ['loc' => "$base-custom-0-1.xml", 'lastmod' => null];
        }
        return $children;
    }

    public function indexXml(Site $site): string
    {
        // A section made after install joins the sitemap the first time a crawler asks for it.
        $this->syncSources(false);
        return $this->cached("index:{$site->id}", fn() => SitemapWriter::index($this->children($site)));
    }

    /** One child sitemap. Null when it doesn't exist. */
    public function pageXml(Site $site, string $slug, string $id, int $page): ?string
    {
        $alternates = Plugin::getInstance()->getSettings()->sitemapAlternates;
        if ($slug === 'custom') {
            return $this->cached("custom:{$site->id}", function() use ($site) {
                $urls = [];
                foreach ((new Query())->from(Install::SITEMAP_URLS)->where(['siteId' => $site->id, 'enabled' => true])->all() as $r) {
                    $loc = preg_match('~^https?://~i', $r['url']) ? $r['url'] : Urls::root($site) . '/' . ltrim($r['url'], '/');
                    $urls[] = new SitemapUrl($loc, null, $r['changefreq'], (float)$r['priority']);
                }
                return SitemapWriter::urlset($urls, false);
            });
        }
        $type = array_search($slug, self::SLUGS, true);
        $row = $type === false ? null : (new Query())->from(Install::SITEMAP_SOURCES)->where(['id' => (int)$id, 'siteId' => $site->id, 'sourceType' => $type, 'enabled' => true])->one();
        if (!$row || $page < 1) {
            return null;
        }
        $limit = max(1, Plugin::getInstance()->getSettings()->sitemapLimit);
        return $this->cached("page:{$site->id}:{$row['id']}:$page", function() use ($row, $site, $page, $limit, $alternates) {
            $query = $this->query($row, $site);
            if ($query === null) {
                return null;
            }
            $elements = $query->orderBy(['elements.id' => SORT_ASC])->offset(($page - 1) * $limit)->limit($limit)->all();
            if ($elements === [] && $page > 1) {
                return null;
            }
            $others = $alternates ? $this->otherSites(array_map(fn($e) => (int)$e->id, $elements), (int)$site->id) : [];
            $meta = Plugin::getInstance()->meta;
            $urls = [];
            foreach ($elements as $e) {
                $url = (string)$e->getUrl();
                if ($url === '' || !$this->included($e, $url, $meta)) {
                    continue;
                }
                $alt = $others[(int)$e->id] ?? [];
                if ($alt !== []) {
                    $alt = [$site->language => $url] + $alt;
                }
                $urls[] = new SitemapUrl($url, $e->dateUpdated, $row['changefreq'], (float)$row['priority'], $alt);
            }
            return SitemapWriter::urlset($urls, $alternates);
        });
    }

    private function included(ElementInterface $e, string $url, MetaService $meta): bool
    {
        $field = $meta->fieldOf($e);
        if ($field === null) {
            return true;
        }
        $v = $e->getFieldValue($field->handle);
        $value = $v instanceof FieldValue ? $v->value : SeoValue::from(null);
        if ($value->robots === [] && $field->defaultRobots !== []) {
            $value = SeoValue::from(['robots' => $field->defaultRobots, 'canonical' => $value->canonical]);
        }
        return SitemapWriter::include($value, $url);
    }

    /**
     * Each element's URL on the other sites, by language.
     *
     * @param list<int> $ids
     * @return array<int, array<string, string>>
     */
    private function otherSites(array $ids, int $siteId): array
    {
        if ($ids === []) {
            return [];
        }
        $sites = [];
        foreach (Craft::$app->getSites()->getAllSites() as $s) {
            if ((int)$s->id !== $siteId && $s->enabled) {
                $sites[(int)$s->id] = $s;
            }
        }
        $out = [];
        $rows = (new Query())->select(['elementId', 'siteId', 'uri'])->from('{{%elements_sites}}')
            ->where(['elementId' => $ids, 'siteId' => array_keys($sites), 'enabled' => true])
            ->andWhere(['not', ['uri' => null]])->all();
        foreach ($rows as $r) {
            $s = $sites[(int)$r['siteId']];
            $uri = $r['uri'] === '__home__' ? '' : $r['uri'];
            $out[(int)$r['elementId']][$s->language] ??= Urls::root($s) . '/' . $uri;
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    private function query(array $row, Site $site): ?\craft\elements\db\ElementQueryInterface
    {
        switch ($row['sourceType']) {
            case 'section':
                $section = Craft::$app->getEntries()->getSectionByUid($row['sourceUid']);
                return $section ? Entry::find()->sectionId($section->id)->siteId($site->id)->uri(':notempty:') : null;
            case 'categoryGroup':
                $group = Craft::$app->getCategories()->getGroupByUid($row['sourceUid']);
                return $group ? Category::find()->groupId($group->id)->siteId($site->id)->uri(':notempty:') : null;
            case 'productType':
                if (!class_exists('craft\\commerce\\elements\\Product')) {
                    return null;
                }
                /** @phpstan-ignore-next-line Commerce is optional */
                $type = \craft\commerce\Plugin::getInstance()->getProductTypes()->getProductTypeByUid($row['sourceUid']);
                /** @phpstan-ignore-next-line */
                return $type ? \craft\commerce\elements\Product::find()->typeId($type->id)->siteId($site->id)->uri(':notempty:') : null;
        }
        return null;
    }

    private function cached(string $key, callable $make): ?string
    {
        $cache = Craft::$app->getCache();
        $key = 'faro-sitemap:' . $key;
        $xml = $cache->get($key);
        if (is_string($xml)) {
            return $xml;
        }
        $xml = $make();
        if (is_string($xml)) {
            $cache->set($key, $xml, 86400, new TagDependency(['tags' => [CraftPorts::TAG, CraftPorts::TAG . ':sitemap', CraftPorts::TAG . ':meta']]));
        }
        return $xml;
    }

    /** Total URLs, for the overview card. */
    public function urlCount(): int
    {
        $n = (int)(new Query())->from(Install::SITEMAP_URLS)->where(['enabled' => true])->count();
        foreach ((new Query())->from(Install::SITEMAP_SOURCES)->where(['enabled' => true])->all() as $r) {
            $site = Craft::$app->getSites()->getSiteById((int)$r['siteId']);
            $q = $site ? $this->query($r, $site) : null;
            $n += $q ? (int)$q->count() : 0;
        }
        return $n;
    }

    /** IndexNow (Should): note the changed URL; a job sends them in a batch every 10 minutes. */
    public function queueIndexNow(mixed $element): void
    {
        $s = Plugin::getInstance()->getSettings();
        if (!$s->indexNow || $s->indexNowKey === '' || !$element instanceof ElementInterface || !$element->getUrl() || !Plugin::getInstance()->isProduction()) {
            return;
        }
        $cache = Craft::$app->getCache();
        $pending = $cache->get('faro-indexnow') ?: [];
        $pending[] = $element->getUrl();
        $cache->set('faro-indexnow', array_values(array_unique($pending)), 3600);
        if (!$cache->get('faro-indexnow-queued')) {
            $cache->set('faro-indexnow-queued', true, 600);
            Queue::push(new IndexNowJob(), 2048, 600);
        }
    }
}
