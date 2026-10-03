<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReservationController extends AbstractController
{
    // Entry point for the test drive booking flow (forms come in the next step).
    #[Route('/reservation', name: 'reservation', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('reservation/index.html.twig');
    }
}
