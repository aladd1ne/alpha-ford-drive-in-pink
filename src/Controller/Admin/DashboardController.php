<?php

namespace App\Controller\Admin;

use App\Enum\Experience;
use App\Repository\ReservationRepository;
use App\Service\Reservation\SlotSchedule;
use App\Service\SolidarityFund;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\ColorScheme;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly SolidarityFund $fund,
        private readonly SlotSchedule $schedule,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'counts' => $this->reservations->countByExperience(),
            'experiences' => Experience::cases(),
            'fund' => $this->fund->snapshot(),
            'toValidateToday' => $this->reservations->countToValidate($this->schedule->dayOf($this->clock->now())),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<img src="/assets/images/logo.png" alt="Ford Drive in Pink" class="dp-admin-logo">&nbsp;<small>admin</small>')
            ->setFaviconPath('assets/images/favicon.png')
            ->setDefaultColorScheme(ColorScheme::LIGHT)
            ->disableDarkMode();
    }

    public function configureCrud(): Crud
    {
        // Cagnotte credits are shown as toasts (js/toast.js), other messages as alerts.
        return Crud::new()
            ->overrideTemplate('flash_messages', 'admin/flash_messages.html.twig');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fas fa-chart-bar');
        yield MenuItem::section('Test drives');
        yield MenuItem::linkTo(TestDriveCrudController::class, 'Test drives du jour', 'fas fa-flag-checkered')
            ->setPermission('ROLE_COMMERCIAL');
        yield MenuItem::linkTo(ReservationCrudController::class, 'Réservations', 'fas fa-calendar-check')
            ->setPermission('ROLE_ADMIN');
        yield MenuItem::section('Utilisateurs')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(CommercialUserCrudController::class, 'Commerciaux', 'fas fa-user-tie')
            ->setPermission('ROLE_ADMIN');
        yield MenuItem::section('');
        yield MenuItem::linkToRoute('Cagnotte en direct', 'fas fa-hand-holding-heart', 'fund')->setLinkTarget('_blank');
        yield MenuItem::linkToRoute('Voir le site', 'fas fa-globe', 'home')->setLinkTarget('_blank');
    }

    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addCssFile('css/admin.css')
            ->addCssFile('css/toast.css')
            ->addJsFile(Asset::new('js/toast.js')->defer())
            ->addJsFile(Asset::new('js/fund-live.js')->defer());
    }
}
