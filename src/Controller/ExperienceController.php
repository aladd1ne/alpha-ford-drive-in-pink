<?php

namespace App\Controller;

use App\Repository\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ExperienceController extends AbstractController
{
    #[Route('/experience/{slug}', name: 'experience_show')]
    public function show(string $slug, CategoryRepository $categoryRepository): Response
    {
        $category = $categoryRepository->findOneBySlug($slug);

        if (!$category) {
            throw $this->createNotFoundException('Expérience introuvable.');
        }

        return $this->render('experience/show.html.twig', [
            'category' => $category,
        ]);
    }
}
