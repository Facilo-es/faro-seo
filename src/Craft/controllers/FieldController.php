<?php

namespace facilo\faro\Craft\controllers;

use Craft;
use craft\web\Controller;
use facilo\faro\Core\PageChecks;
use facilo\faro\Core\PreviewCut;
use facilo\faro\Core\SeoValue;
use facilo\faro\Plugin;
use yii\web\Response;

/**
 * The field's preview and checks (F1). The page body comes from the live page
 * when it has one, cached for ten minutes, so checks see what visitors see.
 */
class FieldController extends Controller
{
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $req = Craft::$app->getRequest();
        $element = Craft::$app->getElements()->getElementById((int)$req->getRequiredBodyParam('elementId'), null, (int)$req->getBodyParam('siteId') ?: null);
        if ($element === null) {
            return $this->asFailure();
        }
        if (!Craft::$app->getElements()->canView($element)) {
            throw new \yii\web\ForbiddenHttpException();
        }
        $meta = Plugin::getInstance()->meta;
        $field = $meta->fieldOf($element);
        $value = SeoValue::from((array)$req->getBodyParam('value', []), $field?->etherTokens ?? []);
        $data = $meta->previewData($element, $value, $field);
        $url = (string)$element->getUrl();
        $host = (string)parse_url($url, PHP_URL_HOST);
        $checks = PageChecks::run($value->keywords, $data['title'], $data['description'], (string)$element->uri, $this->pageHtml($element, $url), $host);
        $data['checks'] = array_map(fn(array $c) => $c + ['text' => self::checkText($c)], $checks['checks']);
        $data['score'] = PageChecks::points($checks['checks']);
        $data['titleCut'] = PreviewCut::title($data['title']);
        $data['descriptionCut'] = PreviewCut::description($data['description']);
        $data['titleBand'] = PreviewCut::band($data['title'], PreviewCut::TITLE_PX, PreviewCut::TITLE_FONT);
        $data['descriptionBand'] = PreviewCut::band($data['description'], PreviewCut::DESCRIPTION_PX, PreviewCut::DESCRIPTION_FONT);
        return $this->asJson($data);
    }

    private function pageHtml(\craft\base\ElementInterface $element, string $url): string
    {
        if ($url === '' || !$element->enabled || $element->getStatus() !== 'live') {
            return '';
        }
        $key = 'faro-page-html:' . $element->id . ':' . $element->siteId . ':' . ($element->dateUpdated?->getTimestamp() ?? 0);
        return Craft::$app->getCache()->getOrSet($key, function() use ($url) {
            try {
                $res = Craft::createGuzzleClient(['timeout' => 5, 'http_errors' => false])->get($url);
                return $res->getStatusCode() === 200 ? substr((string)$res->getBody(), 0, 2_000_000) : '';
            } catch (\Throwable) {
                return '';
            }
        }, 600);
    }

    /** One line per check: what's wrong and how to fix it, or what's good. */
    public static function checkText(array $c): string
    {
        $good = $c['status'] === PageChecks::GOOD;
        $v = $c['value'] ?? 0;
        return match ($c['id']) {
            'keywordInTitle' => $good ? Craft::t('faro-seo', 'Good: the focus keyword is in the title.') : Craft::t('faro-seo', 'The focus keyword isn’t in the title. Add it near the start.'),
            'keywordInDescription' => $good ? Craft::t('faro-seo', 'Good: the focus keyword is in the description.') : Craft::t('faro-seo', 'The focus keyword isn’t in the description. Use it once, in a natural sentence.'),
            'keywordInUrl' => $good ? Craft::t('faro-seo', 'Good: the focus keyword is in the URL.') : Craft::t('faro-seo', 'The focus keyword isn’t in the URL. Put it in the slug if the page is new.'),
            'keywordInFirstParagraph' => $good ? Craft::t('faro-seo', 'Good: the focus keyword is in the first paragraph.') : Craft::t('faro-seo', 'The focus keyword isn’t in the first paragraph. Mention it early.'),
            'keywordInHeading' => $good ? Craft::t('faro-seo', 'Good: the focus keyword is in a heading.') : Craft::t('faro-seo', 'No heading has the focus keyword. Use it in one subheading.'),
            'titleLength' => $good ? Craft::t('faro-seo', 'Good: the title is {n} characters.', ['n' => $v]) : ($v === 0 ? Craft::t('faro-seo', 'The page has no title. Add one.') : Craft::t('faro-seo', 'The title is {n} characters. Aim for 30 to 60, so Google shows all of it.', ['n' => $v])),
            'descriptionLength' => $good ? Craft::t('faro-seo', 'Good: the description is {n} characters.', ['n' => $v]) : ($v === 0 ? Craft::t('faro-seo', 'The page has no description. Write one or two sentences that make people click.') : Craft::t('faro-seo', 'The description is {n} characters. Aim for 70 to 160.', ['n' => $v])),
            'imageAlt' => $good ? Craft::t('faro-seo', 'Good: every image has alt text.') : Craft::t('faro-seo', '{n} images have no alt text. Describe them for search and screen readers.', ['n' => $v]),
            'internalLink' => $good ? Craft::t('faro-seo', 'Good: the page links to other pages on your site.') : Craft::t('faro-seo', 'The page doesn’t link to your other pages. Add a link or two.'),
            'textLength' => $good ? Craft::t('faro-seo', 'Good: the page has {n} words.', ['n' => $v]) : Craft::t('faro-seo', 'The page has {n} words. Pages with 300 or more tend to do better.', ['n' => $v]),
            default => (string)$c['id'],
        };
    }
}
