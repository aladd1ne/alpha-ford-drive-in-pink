<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FundContribution;
use App\Service\SolidarityFund;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public "Cagnotte" page and the read-only endpoint the site polls to keep every
 * fund counter live (see public/js/fund-live.js). Amounts only change through
 * TestDriveValidator, which credits each test drive once.
 */
class FundController extends AbstractController
{
    public function __construct(
        private readonly SolidarityFund $fund,
    ) {
    }

    #[Route('/cagnotte', name: 'fund', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('fund/index.html.twig', [
            'fund' => $this->fund->snapshot(),
            'alphaFordShare' => FundContribution::ALPHA_FORD_SHARE,
        ]);
    }

    /**
     * Current figures as JSON. Revalidated on every poll: the ETag lets an unchanged
     * fund answer with an empty 304.
     */
    #[Route('/cagnotte/etat', name: 'fund_state', methods: ['GET'])]
    public function state(Request $request): Response
    {
        $snapshot = $this->fund->snapshot();

        $response = new JsonResponse($snapshot->toArray());
        $response->setEtag($snapshot->version());
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');
        $response->isNotModified($request);

        return $response;
    }
}
