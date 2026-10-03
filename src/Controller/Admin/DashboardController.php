<?php

namespace App\Controller\Admin;

use App\Entity\Booking;
use App\Entity\Category;
use App\Service\StatsService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\ColorScheme;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;

class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly StatsService $statsService,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        $overview   = $this->statsService->getOverviewStats();
        $chartData  = $this->statsService->getChartData();
        $extraStats = $this->statsService->getMostPopularExtras();

        return $this->render('admin/dashboard.html.twig', [
            'overview'   => $overview,
            'chartData'  => $chartData,
            'extraStats' => $extraStats,
        ]);
    }

    #[Route('/admin/customers', name: 'admin_customers')]
    public function customers(): Response
    {
        $rows = $this->em->createQuery(
            'SELECT b.email, b.customerName, b.phone,
                    COUNT(b.id) AS bookingCount,
                    COALESCE(SUM(b.totalPrice), 0) AS totalSpent,
                    MIN(b.createdAt) AS firstBooking,
                    MAX(b.createdAt) AS lastBooking
             FROM App\Entity\Booking b
             WHERE b.archived = 0
             GROUP BY b.email, b.customerName, b.phone
             ORDER BY bookingCount DESC'
        )->getResult();

        return $this->render('admin/customers.html.twig', ['customers' => $rows]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<b>DRIVE IN PINK</b>&nbsp;<small>admin</small>')
            ->setDefaultColorScheme(ColorScheme::DARK)
            ->setFaviconPath('favicon.ico');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fas fa-chart-bar');
        yield MenuItem::section('Contenu');
        yield MenuItem::linkToCrud('Expériences', 'fas fa-mountain', Category::class);
        yield MenuItem::section('Réservations');
        yield MenuItem::linkToCrud('Toutes les réservations', 'fas fa-calendar-check', Booking::class);
        yield MenuItem::linkToRoute('Clients', 'fas fa-users', 'admin_customers');
        yield MenuItem::section('');
        yield MenuItem::linkToRoute('Voir le site', 'fas fa-globe', 'home')->setLinkTarget('_blank');
        yield MenuItem::linkToRoute('Déconnexion', 'fas fa-right-from-bracket', 'admin_logout');
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        return parent::configureUserMenu($user)
            ->setName($user->getUserIdentifier())
            ->displayUserName(true);
    }

    public function configureAssets(): Assets
    {
        return Assets::new()->addCssFile('css/admin.css');
    }
}
