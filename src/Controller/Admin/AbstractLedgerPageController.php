<?php

namespace Base\Ledger\Controller\Admin;

use Base\Admin\Context\AdminContext;
use Base\Admin\Menu\MenuBuilder;
use Base\Ledger\Entity\Book;
use Base\Ledger\Security\Voter\LedgerVoter;
use Base\Contract\UnitOfWork;
use Base\Database\Repository\Repositories;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The ledger's back-office pages that are not a CRUD (reconciliation,
 * statement upload, bank connection, reports): rendered in the admin's
 * layout with its menus, as forge's refund page is.
 */
abstract class AbstractLedgerPageController extends AbstractController
{
    protected UnitOfWork $unitOfWork;
    protected Repositories $repositories;
    private AdminContext $adminContext;
    private MenuBuilder $menuBuilder;

    #[Required]
    public function setLedgerServices(UnitOfWork $unitOfWork, Repositories $repositories, AdminContext $adminContext, MenuBuilder $menuBuilder): void
    {
        $this->unitOfWork = $unitOfWork;
        $this->repositories = $repositories;
        $this->adminContext = $adminContext;
        $this->menuBuilder = $menuBuilder;
    }

    protected function page(string $template, array $parameters = []): Response
    {
        if ([] === $this->adminContext->getMainMenu()) {
            $this->adminContext->setMainMenu($this->menuBuilder->buildDefault());
        }
        if ([] === $this->adminContext->getUserMenu()) {
            $this->adminContext->setUserMenu($this->menuBuilder->buildUserMenuDefault($this->getUser()));
        }

        return $this->render($template, ['admin_context' => $this->adminContext] + $parameters);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function load(string $class, int $id, string $attribute = LedgerVoter::VIEW): object
    {
        $entity = $this->repositories->of($class)->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted($attribute, $entity);

        return $entity;
    }

    /** The book a page without one in its path works on: ?book=, else the first. */
    protected function bookFrom(Request $request, string $attribute = LedgerVoter::VIEW): Book
    {
        $id = $request->query->getInt('book');
        $book = $id > 0 ? $this->repositories->of(Book::class)->find($id) : $this->repositories->of(Book::class)->findOneBy([], ['id' => 'ASC']);
        if (null === $book) {
            throw $this->createNotFoundException('No book: create one first.');
        }
        $this->denyAccessUnlessGranted($attribute, $book);

        return $book;
    }

    /** POST forms carry their token in _token. */
    protected function assertPostToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
    }

    /** Links that act (the CRUD actions) carry their token in the query, as forge's do. */
    protected function assertLinkToken(Request $request, string $action, int $id): void
    {
        if (!$this->isCsrfTokenValid($action.'_'.$id, (string) $request->query->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
    }

    /**
     * Back to the back-office page the button was on - and only to one: opened as a
     * website-in-website panel over the site (transparent.js nest), the back office's
     * Referer is the site page under the panel, which would then load inside it.
     */
    protected function back(Request $request, ?string $fallback = null): RedirectResponse
    {
        $admin = $this->generateUrl('admin');
        $referer = (string) $request->headers->get('referer');
        $path = parse_url($referer, \PHP_URL_PATH);
        $within = parse_url($referer, \PHP_URL_HOST) === $request->getHost() && \is_string($path) && str_starts_with($path, $admin);

        return $this->redirect($within ? $referer : ($fallback ?? $admin));
    }
}
