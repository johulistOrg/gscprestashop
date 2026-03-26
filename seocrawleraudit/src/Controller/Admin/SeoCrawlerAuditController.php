<?php

namespace PrestaShop\Module\Seocrawleraudit\Controller\Admin;

use Configuration;
use PrestaShop\Bundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShop\Module\Seocrawleraudit\Repository\AuditResultRepository;
use PrestaShop\Module\Seocrawleraudit\Service\SeoCrawlerService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SeoCrawlerAuditController extends FrameworkBundleAdminController
{
    private SeoCrawlerService $crawler;
    private AuditResultRepository $repository;

    public function __construct(SeoCrawlerService $crawler, AuditResultRepository $repository)
    {
        $this->crawler = $crawler;
        $this->repository = $repository;
    }

    public function indexAction(Request $request): Response
    {
        $issueType = (string) $request->query->get('issue_type', '');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 100;

        $filters = [
            'issue_type' => $issueType,
            'offset' => ($page - 1) * $limit,
            'limit' => $limit,
        ];

        return $this->render('@Modules/seocrawleraudit/views/templates/admin/audit.html.twig', [
            'results' => $this->repository->search($filters),
            'issueTypes' => $this->repository->getDistinctIssueTypes(),
            'selectedIssueType' => $issueType,
            'total' => $this->repository->count($filters),
            'page' => $page,
            'limit' => $limit,
            'runRoute' => $this->generateUrl('seocrawleraudit_run'),
            'indexRoute' => $this->generateUrl('seocrawleraudit_index'),
            'token' => $this->getAdminTokenLite('AdminSeoCrawlerAudit'),
            'thinContentWords' => (int) Configuration::get('SEOCRAWLER_THIN_CONTENT_MIN_WORDS', 150),
        ]);
    }

    public function runAction(Request $request): RedirectResponse
    {
        $token = (string) $request->request->get('token');

        if ($token !== $this->getAdminTokenLite('AdminSeoCrawlerAudit')) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectToRoute('seocrawleraudit_index');
        }

        $thinContentWords = max(50, (int) $request->request->get('thin_content_words', 150));
        Configuration::updateValue('SEOCRAWLER_THIN_CONTENT_MIN_WORDS', $thinContentWords);

        try {
            $count = $this->crawler->runAudit($thinContentWords);
            $this->addFlash('success', sprintf('Audit completed. %d issues found.', $count));
        } catch (\Throwable $exception) {
            $this->addFlash('error', sprintf('Audit failed: %s', $exception->getMessage()));
        }

        return $this->redirectToRoute('seocrawleraudit_index');
    }
}
