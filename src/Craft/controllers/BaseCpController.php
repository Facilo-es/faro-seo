<?php

namespace facilo\faro\Craft\controllers;

use Craft;
use craft\web\Controller;
use facilo\faro\Plugin;
use yii\web\Response;

/** Shared parts of the Faro screens: the plugin, the sites and the Pro gate. */
abstract class BaseCpController extends Controller
{
    protected function plugin(): Plugin
    {
        return Plugin::getInstance();
    }

    /** @param array<string, mixed> $vars */
    protected function page(string $template, array $vars = []): Response
    {
        return $this->renderTemplate('faro-seo/' . $template, $vars + [
            'isPro' => $this->plugin()->proActive(),
            'proEdition' => $this->plugin()->isPro(),
            'buyUrl' => 'https://plugins.craftcms.com/faro-seo',
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /** Pro screens show the licence notice instead of their content (F14). */
    protected function proOrNotice(string $template, string $title, callable $vars): Response
    {
        if (!$this->plugin()->proActive()) {
            return $this->page('_pro-notice', ['title' => $title]);
        }
        return $this->page($template, $vars() + ['title' => $title]);
    }

    protected function requireProActive(): void
    {
        if (!$this->plugin()->proActive()) {
            throw new \yii\web\ForbiddenHttpException(Craft::t('faro-seo', 'Broken links and redirect health need Faro SEO Pro. Your redirects keep working.'));
        }
    }
}
