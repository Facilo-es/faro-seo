<?php

namespace facilo\faro\Craft;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use facilo\faro\Core\EtherMapping;
use facilo\faro\Craft\fields\SeoField;
use facilo\faro\migrations\Install;
use facilo\faro\Plugin;
use yii\base\Component;

/**
 * Move from Ether SEO (F8). Scan reads only. Move is safe to run again:
 * what's already moved is skipped and counted as such.
 */
class Mover extends Component
{
    public const ETHER_FIELD = 'ether\\seo\\fields\\SeoField';
    public const ETHER_REDIRECTS = '{{%seo_redirects}}';
    public const ETHER_SITEMAP = '{{%seo_sitemap}}';
    public const SCAN_CACHE_KEY = 'faro-seo:ether-scan';
    /** Template calls Ether answers and Faro keeps answering (F9). */
    public const TEMPLATE_PATTERN = '~\{%-?\s*hook\s+[\'"]seo[\'"]|craft\.seo\b|getSeoField\s*\(~';

    public function etherInstalled(): bool
    {
        return Craft::$app->getPlugins()->isPluginInstalled('seo');
    }

    public function hasTable(string $table): bool
    {
        return Craft::$app->getDb()->tableExists($table);
    }

    /** Anything of Ether's left to move from: the plugin, its tables or its fields. */
    public function etherFound(): bool
    {
        return $this->etherInstalled() || $this->hasTable(self::ETHER_REDIRECTS) || $this->etherFields() !== [];
    }

    /** @return list<array<string, mixed>> Ether fields, from the fields table so it works with Ether gone */
    public function etherFields(): array
    {
        $rows = (new Query())->select(['id', 'uid', 'handle', 'name', 'settings'])->from(Table::FIELDS)
            ->where(['type' => self::ETHER_FIELD])->all();
        foreach ($rows as $i => $r) {
            $rows[$i]['settings'] = is_array($r['settings']) ? $r['settings'] : (Json::decodeIfJson((string)$r['settings']) ?: []);
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> Faro fields that came from Ether, for values still in Ether's shape */
    private function movedFields(): array
    {
        $rows = (new Query())->select(['id', 'uid', 'handle', 'name', 'settings'])->from(Table::FIELDS)
            ->where(['type' => SeoField::class])->all();
        foreach ($rows as $i => $r) {
            $rows[$i]['settings'] = is_array($r['settings']) ? $r['settings'] : (Json::decodeIfJson((string)$r['settings']) ?: []);
        }
        return $rows;
    }

    /**
     * Layout element UIDs per field UID, and the layouts each field is in.
     *
     * @param list<string> $fieldUids
     * @return array{elements: array<string, string>, layouts: array<int, true>, uses: array<string, int>}
     */
    private function layoutsFor(array $fieldUids): array
    {
        $elements = $layouts = $uses = [];
        $rows = (new Query())->select(['id', 'config'])->from(Table::FIELDLAYOUTS)->where(['dateDeleted' => null])->all();
        foreach ($rows as $row) {
            $config = is_array($row['config']) ? $row['config'] : (Json::decodeIfJson((string)$row['config']) ?: []);
            foreach ($config['tabs'] ?? [] as $tab) {
                foreach ($tab['elements'] ?? [] as $el) {
                    $fuid = $el['fieldUid'] ?? null;
                    if ($fuid && in_array($fuid, $fieldUids, true) && isset($el['uid'])) {
                        $elements[$el['uid']] = $fuid;
                        $layouts[(int)$row['id']] = true;
                        $uses[$fuid] = ($uses[$fuid] ?? 0) + 1;
                    }
                }
            }
        }
        return ['elements' => $elements, 'layouts' => $layouts, 'uses' => $uses];
    }

    /**
     * Walks every stored value of the given fields that is still in Ether's shape.
     *
     * @param list<array<string, mixed>> $fields
     * @param callable(array $row, string $layoutUid, array $value, array $field): void $each
     */
    private function eachEtherValue(array $fields, callable $each): void
    {
        if ($fields === []) {
            return;
        }
        $byUid = [];
        foreach ($fields as $f) {
            $byUid[$f['uid']] = $f;
        }
        $map = $this->layoutsFor(array_keys($byUid));
        if ($map['layouts'] === []) {
            return;
        }
        $query = (new Query())->select(['es.id', 'es.siteId', 'es.content'])
            ->from(['es' => Table::ELEMENTS_SITES])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[es.elementId]]')
            ->where(['e.fieldLayoutId' => array_keys($map['layouts'])])
            ->andWhere(['not', ['es.content' => null]])
            ->orderBy(['es.id' => SORT_ASC]);
        foreach ($query->each(500) as $row) {
            $content = is_array($row['content']) ? $row['content'] : (Json::decodeIfJson((string)$row['content']) ?: []);
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $key => $value) {
                if (!isset($map['elements'][$key])) {
                    continue;
                }
                if (is_string($value)) {
                    $value = Json::decodeIfJson($value);
                }
                if (is_array($value) && EtherMapping::isEtherShape($value)) {
                    $each($row + ['decoded' => $content], (string)$key, $value, $byUid[$map['elements'][$key]]);
                }
            }
        }
    }

    /** Step 1: read everything, write nothing but the report row. */
    public function scan(): array
    {
        $will = $check = $wont = [];
        $fields = $this->etherFields();
        $map = $this->layoutsFor(array_column($fields, 'uid'));
        foreach ($fields as $f) {
            $will[] = Craft::t('faro-seo', 'Field “{name}” ({handle}), used in {n, plural, =1{# layout} other{# layouts}}', ['name' => $f['name'], 'handle' => $f['handle'], 'n' => $map['uses'][$f['uid']] ?? 0]);
        }

        $values = 0;
        $sites = [];
        $this->eachEtherValue(array_merge($fields, $this->movedFields()), function($row, $key, $value, $field) use (&$values, &$sites, &$check) {
            $values++;
            $sites[(int)$row['siteId']] = true;
            $v = EtherMapping::value($value, $this->tokens($field));
            foreach ($v->needsCheck as $text) {
                $line = Craft::t('faro-seo', 'Text with code in it, kept as plain text: {text}', ['text' => StringHelper::truncate($text, 80)]);
                if (count($check) < 200 && !in_array($line, $check, true)) {
                    $check[] = $line;
                }
            }
        });
        if ($values > 0) {
            $will[] = Craft::t('faro-seo', '{n} page values on {sites} sites', ['n' => Craft::$app->getFormatter()->asInteger($values), 'sites' => count($sites)]);
        }

        $redirects = $this->hasTable(self::ETHER_REDIRECTS) ? (int)(new Query())->from(self::ETHER_REDIRECTS)->count() : 0;
        if ($redirects > 0) {
            $new = count($this->newRedirects());
            if ($new > 0) {
                $will[] = Craft::t('faro-seo', '{n} redirects', ['n' => Craft::$app->getFormatter()->asInteger($new)]);
            }
            if ($new < $redirects) {
                $will[] = Craft::t('faro-seo', '{n} redirects already moved, skipped', ['n' => $redirects - $new]);
            }
        }
        $sitemap = $this->sitemapRows();
        if ($sitemap['sources'] !== [] || $sitemap['urls'] !== []) {
            $will[] = Craft::t('faro-seo', 'Sitemap settings for {s} sections and groups, and {u} custom URLs', ['s' => count($sitemap['sources']), 'u' => count($sitemap['urls'])]);
        }
        foreach ($sitemap['missing'] as $m) {
            $wont[] = Craft::t('faro-seo', 'A sitemap row for a {group} that no longer exists ({id})', ['group' => $m[0], 'id' => $m[1]]);
        }

        $settingsCheck = [];
        $settings = EtherMapping::settings($this->etherSettings(), $settingsCheck);
        if ($settings !== []) {
            $will[] = Craft::t('faro-seo', 'Plugin settings: {list}', ['list' => implode(', ', array_keys($settings))]);
        }
        foreach ($settingsCheck as $c) {
            $check[] = str_starts_with($c, 'metaTemplate')
                ? Craft::t('faro-seo', 'Your custom meta template ({template}) is kept. Check it: it may use Ether-only properties.', ['template' => substr($c, 14)])
                : Craft::t('faro-seo', 'Your robots.txt uses Twig. Faro keeps it as text with {sitemapUrl} and {siteUrl}; check it on the Indexing tab.');
        }
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges && $fields !== []) {
            $check[] = Craft::t('faro-seo', 'Admin changes are off here, so fields and settings can’t change on this copy. Run the move on your local copy and deploy it, then run php craft faro-seo/ether/data on this one.');
        }

        $report = [
            'will' => $will,
            'check' => $check,
            'wont' => $wont,
            'templates' => $this->templateUses(),
            'counts' => ['fields' => count($fields), 'values' => $values, 'sites' => count($sites), 'redirects' => $redirects, 'urls' => count($sitemap['urls'])],
        ];
        $this->log($report, true);
        return $report;
    }

    /** Ether's settings, from project config so they're there even after Ether is switched off. */
    public function etherSettings(): array
    {
        $s = Craft::$app->getProjectConfig()->get('plugins.seo.settings');
        return is_array($s) ? $s : [];
    }

    /** @return list<array{file: string, line: int, text: string}> */
    public function templateUses(): array
    {
        $out = [];
        $root = Craft::$app->getPath()->getSiteTemplatesPath();
        if (!is_dir($root)) {
            return $out;
        }
        foreach (FileHelper::findFiles($root, ['only' => ['*.twig', '*.html'], 'except' => ['node_modules/']]) as $file) {
            $lines = @file($file) ?: [];
            foreach ($lines as $n => $line) {
                if (preg_match(self::TEMPLATE_PATTERN, $line)) {
                    $out[] = ['file' => substr($file, strlen($root) + 1), 'line' => $n + 1, 'text' => trim(StringHelper::truncate($line, 120))];
                    if (count($out) >= 500) {
                        return $out;
                    }
                }
            }
        }
        return $out;
    }

    /** @return list<\facilo\faro\Core\Redirect> Ether redirects not yet in Faro */
    public function newRedirects(): array
    {
        if (!$this->hasTable(self::ETHER_REDIRECTS)) {
            return [];
        }
        $have = Plugin::getInstance()->redirects->existingKeys();
        $out = [];
        $rows = (new Query())->from(self::ETHER_REDIRECTS)->orderBy(['order' => SORT_ASC, 'id' => SORT_ASC])->all();
        $prefixes = $this->sitePrefixes();
        foreach ($rows as $row) {
            $r = EtherMapping::redirect($row, $prefixes);
            $key = ($r->siteId ?? '*') . '|' . mb_strtolower($r->source);
            if (!isset($have[$key])) {
                $have[$key] = true;
                $out[] = $r;
            }
        }
        return $out;
    }

    /** @return array<int, string> site id => the path its URLs start with, relative to the primary site's */
    private function sitePrefixes(): array
    {
        $sites = Craft::$app->getSites();
        $base = trim((string)parse_url((string)$sites->getPrimarySite()->getBaseUrl(), PHP_URL_PATH), '/');
        $out = [];
        foreach ($sites->getAllSites(true) as $site) {
            $path = trim((string)parse_url((string)$site->getBaseUrl(), PHP_URL_PATH), '/');
            $out[(int)$site->id] = $base !== '' && str_starts_with($path . '/', $base . '/') ? trim(substr($path, strlen($base)), '/') : $path;
        }
        return $out;
    }

    /** @return array{sources: list<array<string, mixed>>, urls: list<array<string, mixed>>, missing: list<array{string, string}>} */
    public function sitemapRows(): array
    {
        if (!$this->hasTable(self::ETHER_SITEMAP)) {
            return ['sources' => [], 'urls' => [], 'missing' => []];
        }
        $missing = [];
        $uidOf = function(string $group, string $id) use (&$missing): ?string {
            $uid = match ($group) {
                'sections' => (new Query())->select('uid')->from(Table::SECTIONS)->where(['id' => (int)$id])->scalar(),
                'categories' => (new Query())->select('uid')->from(Table::CATEGORYGROUPS)->where(['id' => (int)$id])->scalar(),
                'productTypes' => $this->hasTable('{{%commerce_producttypes}}') ? (new Query())->select('uid')->from('{{%commerce_producttypes}}')->where(['id' => (int)$id])->scalar() : null,
                default => null,
            };
            if (!$uid) {
                $missing[] = [$group, $id];
            }
            return $uid ?: null;
        };
        $rows = (new Query())->from(self::ETHER_SITEMAP)->all();
        $out = EtherMapping::sitemap($rows, array_map('intval', Craft::$app->getSites()->getAllSiteIds()), $uidOf);
        return $out + ['missing' => $missing];
    }

    /**
     * Step 2. Runs in a queue job. $progress gets a number from 0 to 1 and a label.
     *
     * @param callable(float, string): void|null $progress
     */
    public function move(?callable $progress = null): array
    {
        $progress ??= fn() => null;
        $adminChanges = Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
        $counts = ['fields' => 0, 'values' => 0, 'sites' => 0, 'redirects' => 0, 'urls' => 0, 'skipped' => 0];

        $progress(0.05, Craft::t('faro-seo', 'Moving fields'));
        $etherFields = $this->etherFields();
        if ($adminChanges) {
            foreach ($etherFields as $f) {
                $counts['fields'] += $this->moveField($f) ? 1 : 0;
            }
        }
        $progress(0.2, Craft::t('faro-seo', 'Moving page values'));
        $data = $this->data(fn($p, $l) => $progress(0.2 + $p * 0.7, $l));
        $counts = array_merge($counts, array_intersect_key($data, array_flip(['values', 'sites', 'redirects', 'urls', 'skipped'])));

        if ($adminChanges) {
            $progress(0.95, Craft::t('faro-seo', 'Moving settings'));
            $this->moveSettings();
        }
        $progress(1, Craft::t('faro-seo', 'Done'));
        $this->log(['counts' => $counts, 'adminChanges' => $adminChanges], false);
        return $counts;
    }

    /**
     * What `php craft faro-seo/ether/data` does: page values, redirects and
     * sitemap rows, which project config doesn't carry.
     *
     * @param callable(float, string): void|null $progress
     */
    public function data(?callable $progress = null): array
    {
        $progress ??= fn() => null;
        $values = 0;
        $sites = [];
        $db = Craft::$app->getDb();
        $this->eachEtherValue(array_merge($this->etherFields(), $this->movedFields()), function($row, $key, $value, $field) use (&$values, &$sites, $db) {
            $content = $row['decoded'];
            $content[$key] = EtherMapping::value($value, $this->tokens($field))->toArray();
            $db->createCommand()->update(Table::ELEMENTS_SITES, ['content' => $content], ['id' => $row['id']], [], false)->execute();
            $values++;
            $sites[(int)$row['siteId']] = true;
        });
        $progress(0.6, Craft::t('faro-seo', 'Moving redirects'));

        $rules = $this->newRedirects();
        $total = $this->hasTable(self::ETHER_REDIRECTS) ? (int)(new Query())->from(self::ETHER_REDIRECTS)->count() : 0;
        $result = Plugin::getInstance()->redirects->import($rules, 'ether');
        $progress(0.85, Craft::t('faro-seo', 'Moving the sitemap'));

        $urls = 0;
        $sitemap = $this->sitemapRows();
        foreach ($sitemap['sources'] as $s) {
            // Unchanged rows are left alone, so a second move changes nothing (T46).
            $same = (new Query())->from(Install::SITEMAP_SOURCES)->where([
                'siteId' => $s['siteId'], 'sourceType' => $s['sourceType'], 'sourceUid' => $s['sourceUid'],
                'enabled' => $s['enabled'], 'changefreq' => $s['changefreq'], 'priority' => $s['priority'],
            ])->exists();
            if ($same) {
                continue;
            }
            Db::upsert(Install::SITEMAP_SOURCES, $s + ['uid' => StringHelper::UUID()], [
                'enabled' => $s['enabled'], 'changefreq' => $s['changefreq'], 'priority' => $s['priority'],
            ]);
        }
        foreach ($sitemap['urls'] as $u) {
            if (Plugin::getInstance()->sitemap->addCustom((int)$u['siteId'], (string)$u['url'], (string)$u['changefreq'], $u['priority'], (bool)$u['enabled'])) {
                $urls++;
            }
        }
        Plugin::getInstance()->sitemap->clear();
        Plugin::getInstance()->meta->clear();
        $progress(1, Craft::t('faro-seo', 'Moving the sitemap'));
        return [
            'values' => $values,
            'sites' => count($sites),
            'redirects' => $result['added'],
            'urls' => $urls,
            'skipped' => $total - count($rules) + $result['skipped'],
            'errors' => $result['errors'],
        ];
    }

    /** One Ether field becomes a Faro field with the same id, UID, handle and layouts. */
    public function moveField(array $row): bool
    {
        $fields = Craft::$app->getFields();
        $old = $fields->getFieldById((int)$row['id']);
        if ($old instanceof SeoField) {
            return false;
        }
        $settings = EtherMapping::fieldSettings($row['settings']);
        $field = $fields->createField([
            'type' => SeoField::class,
            'id' => (int)$row['id'],
            'uid' => $row['uid'],
            'name' => $old?->name ?? $row['name'],
            'handle' => $old?->handle ?? $row['handle'],
            'instructions' => $old?->instructions,
            'searchable' => $old?->searchable ?? true,
            'translationMethod' => $old?->translationMethod ?? 'site',
            'translationKeyFormat' => $old?->translationKeyFormat,
            'settings' => array_filter($settings, fn($v) => $v !== null),
        ]);
        if (!$fields->saveField($field)) {
            Craft::warning('Faro could not move field ' . $row['handle'] . ': ' . Json::encode($field->getErrors()), 'faro-seo');
            return false;
        }
        return true;
    }

    public function moveSettings(): void
    {
        $plugin = Plugin::getInstance();
        $check = [];
        $mapped = EtherMapping::settings($this->etherSettings(), $check);
        $current = $plugin->getSettings()->toArray();
        $siteUids = array_map(fn($s) => $s->uid, Craft::$app->getSites()->getAllSites());
        // Craft saves the array it is given as the whole settings, so start from what is there: a second move keeps what the first set (T46).
        $values = $current;
        foreach ($mapped as $key => $v) {
            switch ($key) {
                case 'sitemapName':
                    $values['sitemapName'] = $v . '.xml';
                    break;
                case 'titleFormat':
                    foreach ($siteUids as $uid) {
                        $values['titleFormats'][$uid] = $current['titleFormats'][$uid] ?? $v;
                    }
                    break;
                case 'defaultDescription':
                    foreach ($siteUids as $uid) {
                        $values['defaultDescriptions'][$uid] = ($current['defaultDescriptions'][$uid] ?? '') ?: $v;
                    }
                    break;
                case 'defaultImageId':
                    foreach ($siteUids as $uid) {
                        $values['defaultImages'][$uid] = ($current['defaultImages'][$uid] ?? null) ?: $v;
                    }
                    break;
                case 'robots':
                    $values['globalRobots'] = $v;
                    break;
                default:
                    if (($current[$key] ?? '') === '' || !in_array($key, ['robotsTxt', 'metaTemplate', 'facebookAppId', 'xHandle'], true)) {
                        $values[$key] = $v;
                    }
            }
        }
        if ($values !== []) {
            Craft::$app->getPlugins()->savePluginSettings($plugin, $values);
        }
    }

    /** @return array<int|string, array<string, mixed>> */
    private function tokens(array $field): array
    {
        $s = $field['settings'] ?? [];
        return is_array($s['title'] ?? null) ? $s['title'] : (is_array($s['etherTokens'] ?? null) ? $s['etherTokens'] : []);
    }

    private function log(array $report, bool $dryRun): void
    {
        // A scan only reads (T40): its report lives in the cache, not the database.
        if ($dryRun) {
            Craft::$app->getCache()->set(self::SCAN_CACHE_KEY, $report, 30 * 86400);
            return;
        }
        try {
            Db::insert(Install::MOVES, ['source' => 'ether', 'dryRun' => $dryRun, 'report' => $report]);
        } catch (\Throwable $e) {
            Craft::warning('Faro could not save the move report: ' . $e->getMessage(), 'faro-seo');
        }
    }

    public function lastReport(bool $dryRun): ?array
    {
        if ($dryRun) {
            $report = Craft::$app->getCache()->get(self::SCAN_CACHE_KEY);
            return is_array($report) ? $report : null;
        }
        // The last move that moved something; a later run that found nothing new is only noted.
        $rows = (new Query())->select(['report', 'dateCreated'])->from(Install::MOVES)
            ->where(['source' => 'ether', 'dryRun' => false])->orderBy(['id' => SORT_DESC])->limit(50)->all();
        $again = null;
        foreach ($rows as $row) {
            $report = $row['report'] ? Json::decode($row['report']) : null;
            // Rows saved before 1.0.0 were encoded twice.
            $report = is_string($report) ? Json::decode($report) : $report;
            if (!is_array($report)) {
                continue;
            }
            $c = $report['counts'] ?? [];
            if (($c['fields'] ?? 0) + ($c['values'] ?? 0) + ($c['redirects'] ?? 0) + ($c['urls'] ?? 0) === 0) {
                $again ??= $row['dateCreated'];
                continue;
            }
            return $report + ['movedOn' => $row['dateCreated'], 'againOn' => $again];
        }
        return null;
    }

    /** The report as Markdown, for download. */
    public function markdown(array $report): string
    {
        $md = "# Faro SEO: move from Ether SEO\n\n";
        foreach (['will' => 'Will move', 'check' => 'Please check', 'wont' => 'Won’t move'] as $key => $heading) {
            $md .= '## ' . Craft::t('faro-seo', $heading) . "\n\n";
            $items = $report[$key] ?? [];
            $md .= $items === [] ? '- ' . Craft::t('faro-seo', 'Nothing') . "\n" : implode('', array_map(fn($i) => "- $i\n", $items));
            $md .= "\n";
        }
        $md .= '## ' . Craft::t('faro-seo', 'Templates using Ether SEO') . "\n\n";
        foreach ($report['templates'] ?? [] as $t) {
            $md .= "- `{$t['file']}:{$t['line']}` " . str_replace('`', "'", $t['text']) . "\n";
        }
        return $md;
    }
}
