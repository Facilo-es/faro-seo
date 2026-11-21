<?php

namespace facilo\faro\Craft;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Queue;
use craft\web\View;
use facilo\faro\Core\Redirect;
use facilo\faro\Core\RedirectHealth;
use facilo\faro\Craft\jobs\LinkCheckJob;
use facilo\faro\migrations\Install;
use facilo\faro\Plugin;
use yii\base\Component;

/**
 * Redirect health (F12, Pro): chains, loops made by patterns, destinations
 * that lead to a missing page, and rules unused for 12 months.
 */
class Health extends Component
{
    public const EXTERNAL_CACHE = 'faro-health-external';

    /**
     * @return array{chains: list<array{rule: Redirect, hops: int, final: string}>, loops: list<Redirect>, missing: list<Redirect>, unused: list<Redirect>, checked: ?string}
     */
    public function report(): array
    {
        $chains = $loops = $missing = [];
        $seen = [];
        foreach ($this->siteIds() as $siteId) {
            $rules = Plugin::getInstance()->redirects->rulesFor($siteId);
            foreach (RedirectHealth::chains($rules) as $c) {
                if (!isset($seen['c' . $c['rule']->id])) {
                    $seen['c' . $c['rule']->id] = true;
                    $chains[] = $c;
                }
            }
            $samples = (new Query())->select('uri')->from('{{%elements_sites}}')->where(['siteId' => $siteId])->andWhere(['not', ['uri' => null]])->limit(500)->column();
            foreach (RedirectHealth::loops($rules, $samples) as $r) {
                if (!isset($seen['l' . $r->id])) {
                    $seen['l' . $r->id] = true;
                    $loops[] = $r;
                }
            }
            foreach ($rules as $r) {
                if (!isset($seen['m' . $r->id]) && $this->leadsToMissing($r, $siteId)) {
                    $seen['m' . $r->id] = true;
                    $missing[] = $r;
                }
            }
        }
        $cached = Craft::$app->getCache()->get(self::EXTERNAL_CACHE);
        return [
            'chains' => $chains,
            'loops' => $loops,
            'missing' => $missing,
            'unused' => $this->unused(),
            'checked' => is_array($cached) ? ($cached['at'] ?? null) : null,
        ];
    }

    /** @return list<int> */
    private function siteIds(): array
    {
        return array_map('intval', Craft::$app->getSites()->getAllSiteIds());
    }

    /** True when a rule's destination is a page this site doesn't have. Outside URLs come from the last link check. */
    public function leadsToMissing(Redirect $r, int $siteId): bool
    {
        if ($r->isGone() || $r->matchType === Redirect::REGEX && str_contains($r->destination, '$')) {
            return false;
        }
        if (preg_match('~^https?://~i', $r->destination)) {
            $site = Craft::$app->getSites()->getSiteById($siteId);
            $base = $site ? Urls::root($site) : '';
            if ($base === '' || !str_starts_with(strtolower($r->destination), strtolower($base))) {
                $cached = Craft::$app->getCache()->get(self::EXTERNAL_CACHE);
                return is_array($cached) && in_array($r->destination, $cached['missing'] ?? [], true);
            }
            $path = substr($r->destination, strlen($base));
        } else {
            $path = $r->destination;
        }
        $path = trim((string)parse_url($path, PHP_URL_PATH), '/');
        return !$this->pathExists($path, $siteId);
    }

    public function pathExists(string $path, int $siteId): bool
    {
        if ($path === '') {
            return true;
        }
        $uri = $path;
        if ((new Query())->from('{{%elements_sites}}')->where(['siteId' => $siteId, 'uri' => $uri, 'enabled' => true])->exists()) {
            return true;
        }
        // A redirect from there counts as found: it's a chain, listed on its own.
        if (Plugin::getInstance()->redirects->find($uri, $siteId) !== null) {
            return true;
        }
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            if ($view->doesTemplateExist($path) && !str_starts_with(basename($path), '_')) {
                return true;
            }
        } finally {
            $view->setTemplateMode($mode);
        }
        foreach (array_keys(Craft::$app->getRoutes()->getProjectConfigRoutes()) as $pattern) {
            $regex = '~^' . preg_replace('~<\w+(?::([^>]+))?>~', '(?:$1|[^/]+)', preg_quote((string)$pattern, '~')) . '$~';
            if (@preg_match($regex, $path)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<Redirect> */
    public function unused(): array
    {
        $rows = [];
        foreach ((new Query())->from(Install::REDIRECTS)->where(['enabled' => true])->all() as $row) {
            $rows[] = [
                'rule' => CraftPorts::redirect($row),
                'lastHit' => $row['lastHit'] ? new \DateTimeImmutable($row['lastHit'], new \DateTimeZone('UTC')) : null,
                'created' => new \DateTimeImmutable($row['dateCreated'], new \DateTimeZone('UTC')),
            ];
        }
        return RedirectHealth::unused($rows, new \DateTimeImmutable());
    }

    /**
     * "Fix all chains": each rule in a chain points straight at the end.
     * Pattern rules that build their destination from the match are left alone.
     */
    public function fixChains(): int
    {
        $fixed = 0;
        foreach ($this->report()['chains'] as $c) {
            $r = $c['rule'];
            if ($r->matchType === Redirect::REGEX && str_contains($r->destination, '$')) {
                continue;
            }
            Db::update(Install::REDIRECTS, ['destination' => $c['final']], ['id' => $r->id]);
            $fixed++;
        }
        Plugin::getInstance()->redirects->clearCache();
        return $fixed;
    }

    public function disable(array $ids): void
    {
        Db::update(Install::REDIRECTS, ['enabled' => false], ['id' => array_map('intval', $ids)]);
        Plugin::getInstance()->redirects->clearCache();
    }

    /** Outside destinations, for the link check job. @return list<string> */
    public function externalDestinations(): array
    {
        $bases = [];
        foreach (Craft::$app->getSites()->getAllSites() as $s) {
            $bases[] = strtolower(Urls::root($s));
        }
        $out = [];
        foreach ((new Query())->select('destination')->distinct()->from(Install::REDIRECTS)->where(['enabled' => true])->column() as $d) {
            if (!preg_match('~^https?://~i', $d) || str_contains($d, '$')) {
                continue;
            }
            foreach ($bases as $b) {
                if ($b !== '' && str_starts_with(strtolower($d), $b)) {
                    continue 2;
                }
            }
            $out[] = $d;
        }
        return $out;
    }

    public function queueLinkCheck(): void
    {
        Queue::push(new LinkCheckJob());
    }
}
