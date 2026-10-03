<?php

namespace App\Controller;

use App\Enum\Experience;
use App\Service\SolidarityFund;
use App\Service\Reservation\SlotAvailability;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly SolidarityFund $fund,
    ) {}

    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(SlotAvailability $availability): Response
    {
        return $this->render('home/index.html.twig', [
            'fundAmount' => $this->fund->total(),
            'full' => [
                Experience::EverestRanger->value => $availability->isFull(Experience::EverestRanger),
                Experience::Territory->value => $availability->isFull(Experience::Territory),
            ],
        ]);
    }
}
