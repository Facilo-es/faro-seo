<?php

namespace facilo\faro\Craft\controllers;

use Craft;
use craft\db\Query;
use craft\web\UploadedFile;
use facilo\faro\Core\Csv;
use facilo\faro\Core\Redirect;
use facilo\faro\migrations\Install;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/** Faro SEO → Redirects (F7, F13). */
class RedirectsController extends BaseCpController
{
    private const MAX_CSV_BYTES = 10 * 1024 * 1024;

    public function beforeAction($action): bool
    {
        $this->requirePermission('faro-seo:redirects');
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $req = Craft::$app->getRequest();
        $siteId = $req->getQueryParam('site') ? (int)$req->getQueryParam('site') : null;
        $search = trim((string)$req->getQueryParam('q', ''));
        return $this->page('redirects/index', [
            'title' => Craft::t('faro-seo', 'Redirects'),
            'rows' => $this->plugin()->redirects->all($siteId, $search),
            'siteId' => $siteId,
            'search' => $search,
        ]);
    }

    public function actionEdit(?int $id = null, ?Redirect $redirect = null, ?string $error = null): Response
    {
        if ($redirect === null && $id !== null) {
            $row = (new Query())->from(Install::REDIRECTS)->where(['id' => $id])->one();
            if (!$row) {
                throw new NotFoundHttpException();
            }
            $redirect = \facilo\faro\Craft\CraftPorts::redirect($row);
        }
        $req = Craft::$app->getRequest();
        $redirect ??= new Redirect((string)$req->getQueryParam('from', ''), (string)$req->getQueryParam('to', ''));
        return $this->page('redirects/edit', [
            'title' => $redirect->id ? Craft::t('faro-seo', 'Edit redirect') : Craft::t('faro-seo', 'New redirect'),
            'redirect' => $redirect,
            'error' => $error,
            'codes' => [...Redirect::FREE_CODES, ...Redirect::PRO_CODES],
            'allowedCodes' => $this->plugin()->redirects->allowedCodes(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $req = Craft::$app->getRequest();
        $id = $req->getBodyParam('id') ? (int)$req->getBodyParam('id') : null;
        $siteId = $req->getBodyParam('siteId') ? (int)$req->getBodyParam('siteId') : null;
        $sort = $id ? (int)(new Query())->select('sortOrder')->from(Install::REDIRECTS)->where(['id' => $id])->scalar() : 0;
        $r = new Redirect(
            (string)$req->getBodyParam('source', ''),
            (string)$req->getBodyParam('destination', ''),
            in_array($m = (string)$req->getBodyParam('matchType'), Redirect::MATCH_TYPES, true) ? $m : Redirect::EXACT,
            (int)$req->getBodyParam('statusCode', 301),
            $siteId,
            in_array($q = (string)$req->getBodyParam('queryMode'), Redirect::QUERY_MODES, true) ? $q : Redirect::QUERY_KEEP,
            $id,
            (bool)$req->getBodyParam('enabled', true),
            $sort,
        );
        $result = $this->plugin()->redirects->save($r);
        if (is_string($result)) {
            if ($req->getAcceptsJson()) {
                return $this->asFailure($result);
            }
            $this->setFailFlash($result);
            Craft::$app->getUrlManager()->setRouteParams(['redirect' => $r, 'error' => $result]);
            return null;
        }
        return $this->asSuccess(Craft::t('faro-seo', 'Redirect saved.'), ['id' => $result], 'faro-seo/redirects');
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->plugin()->redirects->delete((int)Craft::$app->getRequest()->getRequiredBodyParam('id'));
        return $this->asSuccess(Craft::t('faro-seo', 'Redirect deleted.'));
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $ids = array_map('intval', (array)Craft::$app->getRequest()->getRequiredBodyParam('ids'));
        $this->plugin()->redirects->reorder($ids);
        return $this->asSuccess();
    }

    public function actionExport(): Response
    {
        $rows = array_map(fn(array $r) => [\facilo\faro\Craft\CraftPorts::redirect($r), (int)($r['hits'] ?? 0)], $this->plugin()->redirects->all());
        return Craft::$app->getResponse()->sendContentAsFile(Csv::export($rows), 'faro-redirects-' . date('Y-m-d') . '.csv', ['mimeType' => 'text/csv']);
    }

    /** CSV import (Pro, F13): a dry run first, then the real one. */
    public function actionImport(): Response
    {
        $this->requireAdmin(false);
        $req = Craft::$app->getRequest();
        if (!$req->getIsPost() && !$this->plugin()->proActive()) {
            return $this->page('_pro-notice', ['title' => Craft::t('faro-seo', 'Import redirects')]);
        }
        $this->requireProActive();
        $foreign = \facilo\faro\Craft\ForeignImport::available();
        if (!$req->getIsPost()) {
            return $this->page('redirects/import', ['title' => Craft::t('faro-seo', 'Import redirects'), 'result' => null, 'foreign' => $foreign, 'source' => 'csv']);
        }
        $siteId = $req->getBodyParam('siteId') ? (int)$req->getBodyParam('siteId') : null;
        $source = (string)$req->getBodyParam('source', 'csv');
        $csv = '';
        $dryRun = !$req->getBodyParam('confirm');
        if (isset($foreign[$source])) {
            $parsed = ['rows' => \facilo\faro\Craft\ForeignImport::rules($source), 'errors' => []];
            $result = $this->plugin()->redirects->import($parsed['rows'], $source, $dryRun);
        } else {
            $source = 'csv';
            $file = UploadedFile::getInstanceByName('csv');
            if ($file && $file->size > self::MAX_CSV_BYTES) {
                Craft::$app->getSession()->setError(Craft::t('faro-seo', 'The file is larger than 10 MB. Split it and import each part.'));
                return $this->page('redirects/import', ['title' => Craft::t('faro-seo', 'Import redirects'), 'result' => null, 'foreign' => $foreign, 'source' => 'csv']);
            }
            $csv = $file ? (string)file_get_contents($file->tempName) : (string)Craft::$app->getSession()->get('faro-import-csv', '');
            $parsed = Csv::import($csv, $siteId, $this->plugin()->redirects->allowedCodes());
            $result = $this->plugin()->redirects->import($parsed['rows'], 'csv', $dryRun);
        }
        $result['errors'] = array_merge($parsed['errors'], $result['errors']);
        if ($dryRun && $source === 'csv') {
            Craft::$app->getSession()->set('faro-import-csv', $csv);
        } else {
            Craft::$app->getSession()->remove('faro-import-csv');
            Craft::$app->getSession()->setNotice(Craft::t('faro-seo', 'Added {n} redirects.', ['n' => $result['added']]));
        }
        return $this->page('redirects/import', [
            'title' => Craft::t('faro-seo', 'Import redirects'),
            'result' => $result,
            'dryRun' => $dryRun,
            'siteId' => $siteId,
            'foreign' => $foreign,
            'source' => $source,
        ]);
    }
}
