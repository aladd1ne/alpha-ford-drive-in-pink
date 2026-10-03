<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/admin/login', name: 'admin_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin');
        }

        return $this->render('security/login.html.twig', [
            'error'         => $authUtils->getLastAuthenticationError(),
            'last_username' => $authUtils->getLastUsername(),
        ]);
    }

    #[Route('/admin/logout', name: 'admin_logout')]
    public function logout(): never
    {
        throw new \LogicException('This route is handled by the firewall.');
    }
}
