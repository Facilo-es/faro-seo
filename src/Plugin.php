<?php

namespace facilo\faro;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\ElementEvent;
use craft\events\ModelEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterCpAlertsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\utilities\ClearCaches;
use craft\web\ErrorHandler;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use facilo\faro\Craft\CraftPorts;
use facilo\faro\Craft\fields\SeoField;
use facilo\faro\Craft\Health;
use facilo\faro\Craft\MetaService;
use facilo\faro\Craft\Mover;
use facilo\faro\Craft\NotFound;
use facilo\faro\Craft\Redirects;
use facilo\faro\Craft\Settings;
use facilo\faro\Craft\Sitemap;
use facilo\faro\Craft\TwigExtension;
use facilo\faro\Craft\TwigVariable;
use facilo\faro\Craft\utilities\MoveFromEther;
use facilo\faro\Craft\widgets\BrokenLinksWidget;
use yii\base\Event;
use yii\web\NotFoundHttpException;

/**
 * Faro SEO: titles, share images, sitemap and redirects, and a move from Ether SEO.
 *
 * @property-read MetaService $meta
 * @property-read Redirects $redirects
 * @property-read Sitemap $sitemap
 * @property-read NotFound $notFound
 * @property-read Health $health
 * @property-read Mover $mover
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_FREE = 'free';
    public const EDITION_PRO = 'pro';
    public const PRO_URL = 'https://plugins.craftcms.com/faro-seo';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function editions(): array
    {
        return [self::EDITION_FREE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return ['components' => [
            'meta' => MetaService::class,
            'redirects' => Redirects::class,
            'sitemap' => Sitemap::class,
            'notFound' => NotFound::class,
            'health' => Health::class,
            'mover' => Mover::class,
        ]];
    }

    /** Pro is the edition in use. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO);
    }

    /**
     * Pro is in use and may record (F14). A trial counts on non-production
     * environments, as Craft allows; on production only a valid licence does.
     * Meta, sitemap and redirects never depend on this.
     */
    public function proActive(): bool
    {
        if (!$this->isPro()) {
            return false;
        }
        // getPluginLicenseKeyStatus() reads stored info, which never holds the status in Craft 5,
        // so it always says "unknown". getPluginInfo() reads what the licence server sent.
        $status = (string)(Craft::$app->getPlugins()->getPluginInfo($this->handle)['licenseKeyStatus'] ?? 'unknown');
        if ($status === 'valid') {
            return true;
        }
        return $status === 'trial' && !$this->isProduction() || $status === 'unknown';
    }

    public function isProduction(): bool
    {
        return !\facilo\faro\Core\Indexing::blocksIndexing(Craft::$app->env, $this->getSettings()->productionList());
    }

    public function init(): void
    {
        parent::init();
        $this->controllerNamespace = Craft::$app->getRequest()->getIsConsoleRequest()
            ? 'facilo\faro\Craft\console'
            : 'facilo\faro\Craft\controllers';
        Craft::setAlias('@faro', __DIR__);

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = SeoField::class;
        });
        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = MoveFromEther::class;
        });
        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = BrokenLinksWidget::class;
        });
        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_QUERIES, function(\craft\events\RegisterGqlQueriesEvent $event) {
            $event->queries += \facilo\faro\Craft\gql\FaroQueries::getQueries();
        });
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => 'Faro SEO',
                'permissions' => [
                    'faro-seo:redirects' => ['label' => Craft::t('faro-seo', 'Manage redirects')],
                    'faro-seo:broken-links' => ['label' => Craft::t('faro-seo', 'Manage broken links')],
                    'faro-seo:sitemap' => ['label' => Craft::t('faro-seo', 'Manage the sitemap')],
                ],
            ];
        });
        Event::on(ClearCaches::class, ClearCaches::EVENT_REGISTER_TAG_OPTIONS, function(RegisterCacheOptionsEvent $event) {
            $event->options[] = ['tag' => CraftPorts::TAG, 'label' => Craft::t('faro-seo', 'Faro SEO meta, sitemaps and redirects')];
        });

        // Twig: craft.faro, and Ether SEO's calls only while Ether itself is gone (F9).
        $etherActive = Craft::$app->getPlugins()->isPluginEnabled('seo');
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) use ($etherActive) {
            $event->sender->set('faro', TwigVariable::class);
            if (!$etherActive) {
                $event->sender->set('seo', \facilo\faro\Craft\EtherVariable::class);
            }
            if (!Craft::$app->getPlugins()->isPluginEnabled('seomate')) {
                $event->sender->set('schema', \facilo\faro\Craft\SchemaVariable::class);
            }
        });
        Craft::$app->getView()->registerTwigExtension(new TwigExtension(!$etherActive));

        // Cache clearing (F5).
        Event::on(Element::class, Element::EVENT_AFTER_SAVE, function(ModelEvent $event) {
            /** @var Element $element */
            $element = $event->sender;
            if ($element->getIsDraft() || $element->getIsRevision()) {
                return;
            }
            $this->meta->clear();
            $this->sitemap->queueIndexNow($element);
        });
        Event::on(Element::class, Element::EVENT_BEFORE_SAVE, function(ModelEvent $event) {
            $this->redirects->rememberUri($event->sender);
        });
        Event::on(Element::class, Element::EVENT_AFTER_PROPAGATE, function(ModelEvent $event) {
            $this->redirects->afterUriChange($event->sender);
        });
        // Child pages get their new URI without being saved, when a parent's slug changes.
        Event::on(Elements::class, Elements::EVENT_BEFORE_UPDATE_SLUG_AND_URI, function(ElementEvent $event) {
            $this->redirects->rememberUri($event->element);
        });
        Event::on(Elements::class, Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI, function(ElementEvent $event) {
            $this->redirects->afterUriChange($event->element);
        });
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->notFound->prune();
        });

        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest()) {
            return;
        }

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['faro-seo'] = 'faro-seo/overview/index';
            $event->rules['faro-seo/redirects'] = 'faro-seo/redirects/index';
            $event->rules['faro-seo/redirects/new'] = 'faro-seo/redirects/edit';
            $event->rules['faro-seo/redirects/import'] = 'faro-seo/redirects/import';
            $event->rules['faro-seo/redirects/<id:\d+>'] = 'faro-seo/redirects/edit';
            $event->rules['faro-seo/broken-links'] = 'faro-seo/broken-links/index';
            $event->rules['faro-seo/health'] = 'faro-seo/health/index';
            $event->rules['faro-seo/sitemap'] = 'faro-seo/sitemap/index';
            $event->rules['faro-seo/settings'] = 'faro-seo/settings/index';
            $event->rules['faro-seo/settings/<tab:[\w-]+>'] = 'faro-seo/settings/index';
        });

        if ($request->getIsSiteRequest()) {
            Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
                $s = $this->getSettings();
                $base = preg_quote(substr($s->sitemapName, 0, -4), '~');
                $event->rules[$s->sitemapName] = 'faro-seo/site/sitemap';
                $event->rules["<base:$base>-<source:[a-z]+>-<id:[\\w-]+>-<page:\\d+>.xml"] = 'faro-seo/site/sitemap-page';
                $event->rules['robots.txt'] = 'faro-seo/site/robots';
                if ($s->indexNow && $s->indexNowKey !== '') {
                    $event->rules[$s->indexNowKey . '.txt'] = 'faro-seo/site/index-now-key';
                }
            });
            // F5: meta into {{ head() }}, unless a hook or a template already printed it.
            Event::on(View::class, View::EVENT_END_PAGE, function() {
                $this->meta->registerHead();
                $this->meta->sendHeaders();
            });
            // F7: redirects only when Craft is about to show a 404; F11: then the log.
            Event::on(ErrorHandler::class, ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION, function(\craft\events\ExceptionEvent $event) {
                $e = $event->exception;
                while ($e !== null && !$e instanceof NotFoundHttpException) {
                    $e = $e->getPrevious();
                }
                if ($e !== null) {
                    $this->redirects->handleNotFound();
                }
            });
            if ($this->getSettings()->checkBeforeRouting) {
                Craft::$app->on(\yii\base\Application::EVENT_BEFORE_REQUEST, function() {
                    $this->redirects->handleBeforeRouting();
                });
            }
            // F4: staging sites ask search engines to stay away, on every response.
            if (!$this->isProduction()) {
                Craft::$app->getResponse()->getHeaders()->set('X-Robots-Tag', \facilo\faro\Core\Indexing::NON_PRODUCTION_HEADER);
            }
        }

        if ($request->getIsCpRequest()) {
            Event::on(Cp::class, Cp::EVENT_REGISTER_ALERTS, function(RegisterCpAlertsEvent $event) {
                if (!$this->isProduction() && Craft::$app->getUser()->getIsAdmin() && str_starts_with(Craft::$app->getRequest()->getPathInfo(), 'faro-seo')) {
                    $event->alerts[] = \craft\helpers\Html::encode(self::nonProductionText());
                }
            });
        }
    }

    public static function nonProductionText(): string
    {
        $env = (string)Craft::$app->env;
        return Craft::t('faro-seo', 'Search engines are told not to index this site, because its environment is \'{env}\'. If this is your live site, add \'{env}\' under Settings → Indexing.', ['env' => $env === '' ? '(none)' : $env]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Faro SEO';
        $item['icon'] = '@faro/icon-mask.svg';
        $pro = $this->isPro() ? '' : ' · Pro';
        $item['subnav'] = [
            'overview' => ['label' => Craft::t('faro-seo', 'Overview'), 'url' => 'faro-seo'],
            'redirects' => ['label' => Craft::t('faro-seo', 'Redirects'), 'url' => 'faro-seo/redirects'],
            'broken-links' => ['label' => Craft::t('faro-seo', 'Broken links') . $pro, 'url' => 'faro-seo/broken-links'],
            'health' => ['label' => Craft::t('faro-seo', 'Redirect health') . $pro, 'url' => 'faro-seo/health'],
            'sitemap' => ['label' => Craft::t('faro-seo', 'Sitemap'), 'url' => 'faro-seo/sitemap'],
        ];
        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = ['label' => Craft::t('faro-seo', 'Settings'), 'url' => 'faro-seo/settings'];
        }
        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('faro-seo/settings'));
    }

    protected function afterInstall(): void
    {
        // Every section, category group and product type starts in the sitemap.
        $this->sitemap->syncSources();
    }
}
