<?php

namespace App\Controller\Admin;

use App\Entity\FundContribution;
use App\Enum\ContributionSource;
use App\Enum\Experience;
use App\Form\Admin\FundContributionType;
use App\Repository\FundContributionRepository;
use App\Repository\ReservationRepository;
use App\Service\Reservation\SlotSchedule;
use App\Service\SolidarityFund;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\ColorScheme;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractDashboardController
{
    public const ADD_TO_FUND_ROUTE = 'admin_fund_add';

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly SolidarityFund $fund,
        private readonly SlotSchedule $schedule,
        private readonly ClockInterface $clock,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly FundContributionRepository $contributions,
        private readonly EntityManagerInterface $entityManager,
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

    /**
     * Every profile: adds an amount to the cagnotte outside any test drive: a donation from
     * someone registered on the site or not, or the sale of products (price × quantity). Declared on the dashboard so it gets the
     * back-office layout without the cashier-only cagnotte history.
     */
    #[Route('/admin/ajouter-a-la-cagnotte', name: self::ADD_TO_FUND_ROUTE, methods: ['GET', 'POST'])]
    public function addToFund(Request $request): Response
    {
        $form = $this->createForm(FundContributionType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $by = $this->getUser()?->getUserIdentifier() ?? 'inconnu';
            $donor = FundContributionType::donor($data);
            $contribution = ContributionSource::Product === $data['source']
                ? FundContribution::productSale($donor, $data['product'], (int) $data['quantity'], $data['note'] ?? null, $this->clock->now(), $by)
                : FundContribution::donation($donor, (int) $data['amount'], $data['note'] ?? null, $this->clock->now(), $by);
            $this->entityManager->persist($contribution);
            $this->entityManager->flush();

            // Shown as a toast with the new total (templates/admin/flash_messages.html.twig).
            $this->addFlash('cagnotte', [
                'amount' => $contribution->getAmount(),
                'total' => $this->fund->total(),
                'title' => sprintf('%s — cagnotte créditée', $data['source']->label()),
            ]);

            return $this->redirectToRoute(self::ADD_TO_FUND_ROUTE);
        }

        return $this->render('admin/fund/add.html.twig', [
            'form' => $form,
            'total' => $this->fund->total(),
            'latest' => $this->contributions->latestManual(10),
            'donors' => $this->reservations->registeredEmails(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
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
        yield MenuItem::section('Réservations')->setPermission('ROLE_RESERVATIONS_ACCESS');
        yield MenuItem::linkTo(ReservationCrudController::class, 'Toutes les réservations', 'fas fa-calendar-check')
            ->setPermission('ROLE_RESERVATIONS_ACCESS');
        yield MenuItem::linkToUrl('Demandes d’octobre', 'fas fa-calendar-plus', $this->octoberRequestsUrl())
            ->setPermission('ROLE_RESERVATIONS_ACCESS');
        yield MenuItem::linkToRoute('Ajouter un test drive', 'fas fa-plus', ReservationCrudController::ADD_ROUTE)
            ->setPermission('ROLE_RESERVATIONS');
        yield MenuItem::section('Encaissement')->setPermission('ROLE_CASHIER');
        yield MenuItem::linkTo(TestDriveCrudController::class, 'Test drives à encaisser', 'fas fa-cash-register')
            ->setPermission('ROLE_CASHIER');
        yield MenuItem::linkTo(FundContributionCrudController::class, 'Historique de la cagnotte', 'fas fa-coins')
            ->setPermission('ROLE_CASHIER');
        yield MenuItem::section('Cagnotte');
        yield MenuItem::linkToRoute('Ajouter un don / une vente', 'fas fa-hand-holding-dollar', self::ADD_TO_FUND_ROUTE);
        yield MenuItem::linkTo(ProductCrudController::class, 'Produits', 'fas fa-tags')
            ->setPermission('ROLE_ADMIN');
        yield MenuItem::section('Utilisateurs')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(CommercialUserCrudController::class, 'Équipe', 'fas fa-user-tie')
            ->setPermission('ROLE_ADMIN');
        yield MenuItem::section('');
        yield MenuItem::linkToRoute('Cagnotte en direct', 'fas fa-hand-holding-heart', 'fund')->setLinkTarget('_blank');
        yield MenuItem::linkToRoute('Voir le site', 'fas fa-globe', 'home')->setLinkTarget('_blank');
    }

    /**
     * Reservation list filtered on the "autres jours d'octobre" experience.
     */
    private function octoberRequestsUrl(): string
    {
        return $this->adminUrlGenerator->unsetAll()
            ->setController(ReservationCrudController::class)
            ->setAction('index')
            ->set('filters', ['experience' => ['comparison' => '=', 'value' => Experience::October->value]])
            ->generateUrl();
    }

    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addCssFile('css/admin.css')
            ->addCssFile('css/toast.css')
            ->addJsFile(Asset::new('js/toast.js')->defer())
            ->addJsFile(Asset::new('js/fund-live.js')->defer())
            ->addJsFile(Asset::new('js/admin-modal.js')->defer());
    }
}
