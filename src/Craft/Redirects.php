<?php

namespace facilo\faro\Craft;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use facilo\faro\Core\Paths;
use facilo\faro\Core\Redirect;
use facilo\faro\Core\RedirectHealth;
use facilo\faro\Core\RedirectMatch;
use facilo\faro\Core\RedirectMatcher;
use facilo\faro\migrations\Install;
use facilo\faro\Plugin;
use yii\base\Component;
use yii\caching\TagDependency;

/**
 * Redirects (F7, F12, F13). Looked up only when Craft is about to answer 404,
 * from a compiled list cached per site.
 */
class Redirects extends Component
{
    public const ORIGINS = ['manual', 'ether', 'csv', 'retour', 'sprout', 'dolphiq', 'uri-change', '404'];

    /** @var array<string, string> "elementId:siteId" => URI before the save */
    private array $oldUris = [];

    public function matcher(int $siteId): RedirectMatcher
    {
        $cache = Craft::$app->getCache();
        $key = "faro-redirects:$siteId";
        $m = $cache->get($key);
        if (!$m instanceof RedirectMatcher) {
            $m = new RedirectMatcher((new CraftPorts())->forSite($siteId));
            $cache->set($key, $m, 0, new TagDependency(['tags' => [CraftPorts::TAG, CraftPorts::TAG . ':redirects']]));
        }
        return $m;
    }

    public function clearCache(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), CraftPorts::TAG . ':redirects');
    }

    /** The request's URI without the site's base path, with its query string. */
    public function requestUri(): string
    {
        $request = Craft::$app->getRequest();
        $q = $request->getQueryStringWithoutPath();
        return Paths::normalize($request->getPathInfo() . ($q !== '' ? '?' . $q : ''));
    }

    public function find(string $uri, ?int $siteId = null): ?RedirectMatch
    {
        $siteId ??= (int)Craft::$app->getSites()->getCurrentSite()->id;
        return $this->matcher($siteId)->find($uri);
    }

    /** Craft is about to show a 404: redirect if a rule matches, else log it (Pro). */
    public function handleNotFound(): void
    {
        $uri = $this->requestUri();
        $match = $this->find($uri);
        if ($match !== null) {
            $this->send($match);
            return;
        }
        Plugin::getInstance()->notFound->record($uri, Craft::$app->getRequest()->getReferrer());
    }

    public function handleBeforeRouting(): void
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsActionRequest() || $request->getIsLivePreview() || $request->getIsPreview()) {
            return;
        }
        $match = $this->find($this->requestUri());
        if ($match !== null) {
            $this->send($match);
        }
    }

    private function send(RedirectMatch $match): void
    {
        $this->hit((int)$match->redirect->id);
        $response = Craft::$app->getResponse();
        if ($match->statusCode === 410) {
            $response->setStatusCode(410);
            $response->content = Craft::t('faro-seo', 'This page has been removed.');
        } else {
            $url = $match->isAbsolute() ? $match->destination : UrlHelper::siteUrl(ltrim($match->destination, '/'));
            $response->redirect($url, $match->statusCode);
        }
        Craft::$app->end();
    }

    public function hit(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        Db::update(Install::REDIRECTS, ['hits' => new \yii\db\Expression('[[hits]] + 1'), 'lastHit' => Db::prepareDateForDb(new \DateTime())], ['id' => $id], [], false);
    }

    /** @return list<int> status codes this install may use */
    public function allowedCodes(): array
    {
        return Plugin::getInstance()->proActive() ? [...Redirect::FREE_CODES, ...Redirect::PRO_CODES] : Redirect::FREE_CODES;
    }

    /**
     * Checks a redirect before saving it. Returns an error message, or null.
     */
    public function validate(Redirect $r): ?string
    {
        if (!in_array($r->statusCode, $this->allowedCodes(), true)) {
            return Craft::t('faro-seo', 'Status {code} needs Faro SEO Pro.', ['code' => $r->statusCode]);
        }
        if ($r->source === '') {
            return Craft::t('faro-seo', 'Enter the address to redirect from.');
        }
        if ($r->destination === '' && !$r->isGone()) {
            return Craft::t('faro-seo', 'Enter the address to send visitors to.');
        }
        $others = array_values(array_filter($this->rulesFor($r->siteId), fn(Redirect $o) => $o->id !== $r->id));
        $result = RedirectHealth::validate($r, $others);
        return match ($result) {
            RedirectHealth::OK => null,
            RedirectHealth::SELF => Craft::t('faro-seo', 'This redirect sends visitors to the same address.'),
            RedirectHealth::BAD_REGEX => Craft::t('faro-seo', 'This pattern isn\'t a valid regular expression.'),
            default => Craft::t('faro-seo', 'This would send visitors in a circle: {chain}.', ['chain' => $this->chainText($r, $others)]),
        };
    }

    /** @param list<Redirect> $others */
    private function chainText(Redirect $r, array $others): string
    {
        $m = new RedirectMatcher([$r, ...$others]);
        $start = $r->matchType === Redirect::EXACT ? $r->source : $r->destination;
        $steps = [$start];
        foreach (RedirectHealth::follow($start, $m) ?? [] as $hop) {
            $steps[] = $hop->destination;
            if (count($steps) > 6) {
                break;
            }
        }
        return implode(' → ', array_map(fn($s) => '/' . ltrim($s, '/'), $steps));
    }

    /** @return list<Redirect> enabled rules for a site (or for all sites), in order */
    public function rulesFor(?int $siteId): array
    {
        $q = (new Query())->from(Install::REDIRECTS)->where(['enabled' => true])->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC]);
        if ($siteId !== null) {
            $q->andWhere(['or', ['siteId' => null], ['siteId' => $siteId]]);
        }
        return array_map([CraftPorts::class, 'redirect'], $q->all());
    }

    /**
     * Saves a redirect. Returns the id, or an error message.
     */
    public function save(Redirect $r, string $origin = 'manual', bool $validate = true): int|string
    {
        if ($validate && ($error = $this->validate($r)) !== null) {
            return $error;
        }
        $row = [
            'siteId' => $r->siteId,
            'source' => $r->source,
            'matchType' => $r->matchType,
            'destination' => $r->destination,
            'statusCode' => $r->statusCode,
            'queryMode' => $r->queryMode,
            'enabled' => $r->enabled,
        ];
        if ($r->id) {
            Db::update(Install::REDIRECTS, $row, ['id' => $r->id]);
            $id = $r->id;
        } else {
            $row['origin'] = in_array($origin, self::ORIGINS, true) ? $origin : 'manual';
            $row['sortOrder'] = $r->sortOrder ?: ((int)(new Query())->from(Install::REDIRECTS)->max('[[sortOrder]]') + 1);
            $row['uid'] = StringHelper::UUID();
            Db::insert(Install::REDIRECTS, $row);
            $id = (int)Craft::$app->getDb()->getLastInsertID(Install::REDIRECTS);
        }
        $this->clearCache();
        return $id;
    }

    public function delete(int $id): void
    {
        Db::delete(Install::REDIRECTS, ['id' => $id]);
        $this->clearCache();
    }

    /** @param list<int> $ids in their new order */
    public function reorder(array $ids): void
    {
        foreach (array_values($ids) as $i => $id) {
            Db::update(Install::REDIRECTS, ['sortOrder' => $i + 1], ['id' => (int)$id], [], false);
        }
        $this->clearCache();
    }

    /** Exact rules already present, as "siteId|source" keys, to skip duplicates on import and move. */
    public function existingKeys(): array
    {
        $keys = [];
        foreach ((new Query())->select(['siteId', 'source'])->from(Install::REDIRECTS)->all() as $row) {
            $keys[($row['siteId'] ?? '*') . '|' . mb_strtolower((string)$row['source'])] = true;
        }
        return $keys;
    }

    /**
     * Saves many redirects, skipping ones whose source is already there.
     *
     * @param list<Redirect> $rules
     * @return array{added: int, skipped: int, errors: list<string>}
     */
    public function import(array $rules, string $origin, bool $dryRun = false): array
    {
        $keys = $this->existingKeys();
        $added = $skipped = 0;
        $errors = [];
        foreach ($rules as $r) {
            $k = ($r->siteId ?? '*') . '|' . mb_strtolower($r->source);
            if (isset($keys[$k])) {
                $skipped++;
                continue;
            }
            $keys[$k] = true;
            if ($dryRun) {
                $added++;
                continue;
            }
            $res = $this->save($r, $origin, $origin !== 'ether');
            if (is_string($res)) {
                $errors[] = $r->source . ': ' . $res;
            } else {
                $added++;
            }
        }
        return ['added' => $added, 'skipped' => $skipped, 'errors' => $errors];
    }

    // F13: a 301 from the old URI when an entry's URI changes (Pro).

    public function rememberUri(mixed $element): void
    {
        if (!$element instanceof ElementInterface || !$element->id || $element->getIsDraft() || $element->getIsRevision()) {
            return;
        }
        $old = (new Query())->select('uri')->from('{{%elements_sites}}')
            ->where(['elementId' => $element->id, 'siteId' => $element->siteId])->scalar();
        if (is_string($old) && $old !== '') {
            $this->oldUris[$element->id . ':' . $element->siteId] = $old;
        }
    }

    public function afterUriChange(mixed $element): void
    {
        if (!$element instanceof ElementInterface || !$element->id) {
            return;
        }
        $key = $element->id . ':' . $element->siteId;
        $old = $this->oldUris[$key] ?? null;
        unset($this->oldUris[$key]);
        $plugin = Plugin::getInstance();
        $new = (string)$element->uri;
        if ($old === null || $new === '' || $old === $new || !$plugin->proActive() || !$plugin->getSettings()->uriChangeRedirects) {
            return;
        }
        if (!$element->enabled || str_starts_with($new, '__temp_')) {
            return;
        }
        $this->onUriChange($old === '__home__' ? '' : $old, $new === '__home__' ? '' : $new, (int)$element->siteId);
    }

    /** Point the old URI at the new one, and point older redirects straight there, so no chain forms. */
    public function onUriChange(string $old, string $new, int $siteId): void
    {
        $old = Paths::normalize($old);
        $new = Paths::normalize($new);
        if ($old === $new) {
            return;
        }
        // Anything that pointed at the old URI now points at the new one.
        Db::update(Install::REDIRECTS, ['destination' => $new], ['and', ['destination' => [$old, '/' . $old]], ['or', ['siteId' => null], ['siteId' => $siteId]]]);
        // The page moved back to an address a redirect sends away from: that redirect would loop.
        Db::delete(Install::REDIRECTS, ['and', ['source' => $new, 'matchType' => Redirect::EXACT, 'siteId' => $siteId, 'origin' => 'uri-change']]);
        $exists = (new Query())->from(Install::REDIRECTS)->where(['source' => $old, 'siteId' => $siteId, 'matchType' => Redirect::EXACT])->exists();
        if ($exists) {
            Db::update(Install::REDIRECTS, ['destination' => $new, 'enabled' => true], ['source' => $old, 'siteId' => $siteId, 'matchType' => Redirect::EXACT]);
        } else {
            $this->save(new Redirect($old, $new, Redirect::EXACT, 301, $siteId), 'uri-change', false);
        }
        $this->clearCache();
    }

    /** Rows for the Redirects screen. */
    public function all(?int $siteId = null, string $search = ''): array
    {
        $q = (new Query())->from(Install::REDIRECTS)->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC]);
        if ($siteId) {
            $q->andWhere(['siteId' => $siteId]);
        }
        if ($search !== '') {
            $q->andWhere(['or', ['like', 'source', $search], ['like', 'destination', $search]]);
        }
        return $q->limit(5000)->all();
    }

    public function count(): int
    {
        return (int)(new Query())->from(Install::REDIRECTS)->count();
    }

    public function totalHits(): int
    {
        return (int)(new Query())->from(Install::REDIRECTS)->sum('[[hits]]');
    }
}
