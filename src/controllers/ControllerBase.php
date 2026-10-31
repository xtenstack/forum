<?php
declare(strict_types=1);

namespace Forum\Controllers;

use Phalcon\Mvc\Controller;

/**
 * HTML/admin controller base — mirrors the backend module's
 * auth/CSRF/role gate, the same copy the contacts and announcements
 * plugins carry. Both Forum screens are admin and operator only; the
 * game registry's write is admin only (requireAdmin()).
 */
class ControllerBase extends Controller
{
    protected ?array $allowedRoles = null;

    protected function onConstruct()
    {
        $this->allowedRoles = \Roles::idsByNames(['admin', 'operator']);

        $this->preventCaching();
        $this->enforceCsrf();

        if (!$this->auth->isLoggedIn()) {
            $this->response->redirect($this->url->get('backend/session'))->send();
            exit;
        }

        if (!$this->currentPrincipal->hasAnyRole($this->allowedRoles)) {
            $this->response->setStatusCode(403, 'Forbidden');
            $this->response->setContent('<h1>403 Forbidden</h1><p>You do not have access to this page.</p>');
            $this->response->send();
            exit;
        }

        $this->view->setVar('isAdmin', $this->isAdmin());
    }

    protected function forum(): \Forum\ForumServiceInterface
    {
        return $this->getDI()->getShared('forum');
    }

    protected function isAdmin(): bool
    {
        return $this->currentPrincipal->hasAnyRole(\Roles::idsByNames(['admin']));
    }

    protected function requireAdmin(string $what = 'This action'): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $this->view->disable();
        $this->response->setStatusCode(403, 'Forbidden');
        $this->response->setContent('<h1>403 Forbidden</h1><p>' . htmlspecialchars($what, ENT_QUOTES) . ' is admin-only.</p>');

        return false;
    }

    private function preventCaching(): void
    {
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Pragma', 'no-cache');
    }

    /**
     * Same central CSRF rule as the backend module (and the contacts
     * plugin): a POST needs a token the session actually seeded;
     * destroyIfValid false so a page with several forms keeps working.
     */
    private function enforceCsrf(): void
    {
        if (!$this->request->isPost()) {
            return;
        }

        if ($this->security->getSessionToken() && $this->security->checkToken(null, null, false)) {
            return;
        }

        $this->flash->error('Your session expired or the form was resubmitted — please try again.');

        $referer = $this->request->getHTTPReferer();
        $this->response->redirect($referer ?: $this->url->get('backend'))->send();
        exit;
    }
}
