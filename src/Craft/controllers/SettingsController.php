<?php

namespace facilo\faro\Craft\controllers;

use Craft;
use facilo\faro\Core\Indexing;
use facilo\faro\Core\SeoValue;
use facilo\faro\Core\TitleFormat;
use facilo\faro\Craft\Urls;
use facilo\faro\Plugin;
use yii\web\Response;

/** Faro SEO → Settings, in four tabs (§5). Admins only; saved to project config. */
class SettingsController extends BaseCpController
{
    public const TABS = ['titles', 'sections', 'social', 'indexing'];

    public function beforeAction($action): bool
    {
        $this->requireAdmin(false);
        return parent::beforeAction($action);
    }

    public function actionIndex(?string $tab = null): Response
    {
        $tab = in_array($tab, self::TABS, true) ? $tab : 'titles';
        $s = $this->plugin()->getSettings();
        $sites = Craft::$app->getSites()->getAllSites();
        $images = [];
        foreach ($s->defaultImages as $uid => $id) {
            $images[$uid] = $id ? Craft::$app->getAssets()->getAssetById((int)$id) : null;
        }
        $primary = Craft::$app->getSites()->getPrimarySite();
        $sitemapUrl = Urls::root($primary) . '/' . $s->sitemapName;
        return $this->page('settings/' . $tab, [
            'title' => Craft::t('faro-seo', 'Settings'),
            'tab' => $tab,
            'settings' => $s,
            'images' => $images,
            'logo' => $s->organizationLogo ? Craft::$app->getAssets()->getAssetById($s->organizationLogo) : null,
            'separators' => TitleFormat::SEPARATORS,
            'robotsOptions' => SeoValue::ROBOTS,
            'sources' => $tab === 'sections' ? $this->sources() : [],
            'defaultRobotsTxt' => Indexing::defaultRobotsTxt($sitemapUrl),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'nonProduction' => !$this->plugin()->isProduction() ? Plugin::nonProductionText() : null,
            'allSites' => $sites,
        ]);
    }

    /**
     * Sections, category groups and product types, each with the fields
     * that can give a title, description or image.
     *
     * @return list<array{key: string, name: string, text: array<string, string>, images: array<string, string>}>
     */
    private function sources(): array
    {
        $out = [];
        $add = function(string $key, string $name, array $layouts) use (&$out) {
            $text = $images = [];
            foreach ($layouts as $layout) {
                foreach ($layout?->getCustomFields() ?? [] as $f) {
                    if ($f instanceof \craft\fields\Assets) {
                        $images[$f->handle] = $f->name;
                    /** @phpstan-ignore-next-line CKEditor and Redactor are optional */
                    } elseif ($f instanceof \craft\fields\PlainText || in_array(get_class($f), ['craft\\ckeditor\\Field', 'craft\\redactor\\Field'], true)) {
                        $text[$f->handle] = $f->name;
                    }
                }
            }
            $out[] = ['key' => $key, 'name' => $name, 'text' => ['title' => 'Title'] + $text, 'images' => $images];
        };
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $add('section:' . $section->uid, $section->name, array_map(fn($t) => $t->getFieldLayout(), $section->getEntryTypes()));
        }
        foreach (Craft::$app->getCategories()->getAllGroups() as $g) {
            $add('categoryGroup:' . $g->uid, $g->name, [$g->getFieldLayout()]);
        }
        if (Craft::$app->getPlugins()->isPluginEnabled('commerce')) {
            /** @phpstan-ignore-next-line Commerce is optional */
            foreach (\craft\commerce\Plugin::getInstance()->getProductTypes()->getAllProductTypes() as $t) {
                $add('productType:' . $t->uid, $t->name, [$t->getFieldLayout()]);
            }
        }
        return $out;
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $req = Craft::$app->getRequest();
        $plugin = $this->plugin();
        $current = $plugin->getSettings()->toArray();
        $posted = (array)$req->getBodyParam('settings', []);

        // Per-tab clean-up of the shapes the form posts.
        if (isset($posted['defaultImages'])) {
            foreach ($posted['defaultImages'] as $uid => $v) {
                $posted['defaultImages'][$uid] = is_array($v) ? (isset($v[0]) ? (int)$v[0] : null) : ($v ? (int)$v : null);
            }
        }
        if (array_key_exists('organizationLogo', $posted)) {
            $v = $posted['organizationLogo'];
            $posted['organizationLogo'] = is_array($v) ? (isset($v[0]) ? (int)$v[0] : null) : ($v ? (int)$v : null);
        }
        foreach (['globalRobots', 'sameAs', 'botPatterns'] as $list) {
            if (array_key_exists($list, $posted)) {
                $v = $posted[$list];
                $v = is_string($v) ? preg_split('~\R~', $v) : (array)$v;
                $posted[$list] = array_values(array_filter(array_map('trim', $v ?: []), fn($x) => $x !== ''));
            }
        }
        if (isset($posted['fallbackMaps'])) {
            foreach ($posted['fallbackMaps'] as $key => $map) {
                foreach (['title', 'description', 'image'] as $part) {
                    $posted['fallbackMaps'][$key][$part] = array_values(array_filter((array)($map[$part] ?? [])));
                }
            }
        }
        foreach (['jsonLd', 'indexNow', 'sitemapAlternates', 'checkBeforeRouting', 'uriChangeRedirects', 'log404s'] as $bool) {
            if (array_key_exists($bool, $posted)) {
                $posted[$bool] = (bool)$posted[$bool];
            }
        }
        if (($posted['indexNow'] ?? false) && ($posted['indexNowKey'] ?? $current['indexNowKey']) === '') {
            $posted['indexNowKey'] = bin2hex(random_bytes(16));
        }

        $settings = array_replace($current, $posted);
        $model = clone $plugin->getSettings();
        $model->setAttributes($settings, false);
        if (!$model->validate()) {
            $this->setFailFlash(implode(' ', $model->getFirstErrors()));
            return null;
        }
        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            return $this->asFailure(Craft::t('faro-seo', 'Couldn’t save the settings.'));
        }
        Plugin::getInstance()->meta->clear();
        Plugin::getInstance()->sitemap->clear();
        return $this->asSuccess(Craft::t('faro-seo', 'Settings saved.'));
    }
}
