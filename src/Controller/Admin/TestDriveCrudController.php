<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\FundContribution;
use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\ReservationStatus;
use App\Enum\TestDriveStatus;
use App\Enum\Vehicle;
use App\Form\Admin\ManualTestDriveType;
use App\Repository\ReservationRepository;
use App\Service\Reservation\SlotSchedule;
use App\Service\SolidarityFund;
use App\Service\TestDrive\ReservationNotFoundException;
use App\Service\TestDrive\TestDriveNotEligibleException;
use App\Service\TestDrive\TestDriveValidator;
use App\Service\TestDrive\ValidationOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Cashier space ("Encaissement"): the reservations of one event day (a tab per day,
 * today by default) and the "Encaisser" action, where the cashier enters the amount
 * received; the test drive is then validated and the cagnotte credited with that amount
 * plus Alpha Ford's share (see TestDriveValidator). Walk-ins can be added by hand for
 * today (addTestDrive()).
 *
 * @extends AbstractCrudController<Reservation>
 */
#[IsGranted('ROLE_CASHIER')]
#[AdminRoute(path: '/test-drives', name: 'test_drive')]
class TestDriveCrudController extends AbstractCrudController
{
    use ExportsReservationsToExcel;
    use RedirectsToBackOfficeList;

    public const VALIDATE_ACTION = 'validateTestDrive';
    public const VALIDATE_ROUTE = 'admin_test_drive_validate';
    public const ADD_ACTION = 'addTestDrive';
    public const ADD_ROUTE = 'admin_test_drive_add';
    /** Highest amount a cashier can enter for one test drive, in DT. */
    public const MAX_RECEIVED_AMOUNT = 10000;

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly SlotSchedule $schedule,
        private readonly ClockInterface $clock,
        private readonly TestDriveValidator $validator,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly SolidarityFund $fund,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $entityValidator,
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Reservation::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Test drive')
            ->setEntityLabelInPlural('Encaissement')
            ->setPageTitle(Crud::PAGE_INDEX, 'Encaissement du ' . $this->selectedDay()->format('d/m/Y'))
            ->setHelp(Crud::PAGE_INDEX, sprintf('« Encaisser » puis saisissez le montant total (%d DT par défaut, dont %d DT d’Alpha Ford) : le test drive est validé et la cagnotte reçoit ce montant.', FundContribution::TEST_DRIVE_AMOUNT, FundContribution::ALPHA_FORD_SHARE))
            ->overrideTemplate('crud/index', 'admin/test_drive/index.html.twig')
            ->setSearchFields(['fullName', 'email', 'phone'])
            ->setPaginatorPageSize(100)
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $urlGenerator = $this->urlGenerator;
        $today = $this->today();
        // Cashiers (and admins) may cash in a test drive before its planned date.
        $allowFutureDate = $this->isGranted('ROLE_CASHIER');

        return $actions
            ->add(Crud::PAGE_INDEX, Action::new(self::VALIDATE_ACTION, 'Encaisser', 'fas fa-cash-register')
                ->linkToUrl(static fn (Reservation $reservation): string => $urlGenerator->generate(self::VALIDATE_ROUTE, ['entityId' => $reservation->getId()]))
                ->setTemplatePath('admin/action/cash_in.html.twig')
                ->displayIf(static fn (Reservation $reservation): bool => $reservation->canValidateTestDrive($today, $allowFutureDate)))
            ->add(Crud::PAGE_INDEX, self::exportExcelAction())
            ->add(Crud::PAGE_INDEX, Action::new(self::ADD_ACTION, 'Ajouter un test drive', 'fas fa-plus')
                ->linkToUrl($this->urlGenerator->generate(self::ADD_ROUTE))
                ->addCssClass('btn btn-primary')
                ->createAsGlobalAction())
            ->setPermission(self::VALIDATE_ACTION, 'ROLE_CASHIER')
            ->setPermission(self::ADD_ACTION, 'ROLE_ADMIN')
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('testDriveStatus', 'Statut du test drive')->setChoices(self::choices(TestDriveStatus::cases())));
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('fullName', 'Nom et prénom');
        yield TelephoneField::new('phone', 'Téléphone');
        yield EmailField::new('email', 'E-mail');
        yield ChoiceField::new('experience', 'Expérience');
        yield ChoiceField::new('vehicle', 'Véhicule');
        yield DateField::new('date', 'Date')->setFormat('EEE d MMM');
        yield TextField::new('slot', 'Créneau');
        yield ChoiceField::new('status', 'Réservation')->renderAsBadges([
            ReservationStatus::Pending->name => 'warning',
            ReservationStatus::Confirmed->name => 'success',
            ReservationStatus::Cancelled->name => 'secondary',
            ReservationStatus::Archived->name => 'secondary',
        ]);
        yield ChoiceField::new('testDriveStatus', 'Test drive')->renderAsBadges([
            TestDriveStatus::ToValidate->name => 'warning',
            TestDriveStatus::Completed->name => 'success',
        ]);
        yield DateTimeField::new('testDriveCompletedAt', 'Encaissé le')->setFormat('HH:mm');
        yield TextField::new('testDriveValidatedBy', 'Encaissé par');
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        $selected = $this->selectedDay()->format('Y-m-d');
        $tabs = [];
        foreach ($this->days() as $day) {
            $tabs[] = [
                'label' => $day->format('d/m'),
                'today' => $day->format('Y-m-d') === $this->today()->format('Y-m-d'),
                'active' => $day->format('Y-m-d') === $selected,
                'url' => $this->adminUrlGenerator->unsetAll()->setController(self::class)->setAction(Action::INDEX)->set('day', $day->format('Y-m-d'))->generateUrl(),
            ];
        }

        $responseParameters->set('dayTabs', $tabs);

        return $responseParameters;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return $this->reservations->applyDayScope(
            parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters),
            $this->selectedDay(),
        );
    }

    /**
     * Cash-in: the amount received is required; the cagnotte gets it plus Alpha Ford's share.
     */
    #[IsGranted('ROLE_CASHIER')]
    #[AdminRoute(path: '/{entityId}/validate', name: 'validate', options: ['methods' => ['POST'], 'requirements' => ['entityId' => '\d+']])]
    public function validateTestDrive(Request $request): RedirectResponse
    {
        $reservationId = (int) $request->attributes->get('entityId', $request->query->get('entityId'));
        $return = $this->returnUrl($request, $this->indexUrl());

        if (!$this->isCsrfTokenValid('validate-test-drive-' . $reservationId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide, veuillez réessayer.');

            return $this->redirect($return);
        }

        $amount = trim((string) $request->request->get('amount'));
        $clientAmount = self::clientAmountOf($amount);
        if (null === $clientAmount) {
            $this->addFlash('danger', self::invalidAmountMessage());

            return $this->redirect($return);
        }

        try {
            $outcome = $this->validator->validate(
                $reservationId,
                $this->getUser()?->getUserIdentifier() ?? 'inconnu',
                $this->isGranted('ROLE_CASHIER'),
                $clientAmount,
            );
        } catch (ReservationNotFoundException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        } catch (TestDriveNotEligibleException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirect($return);
        }

        if (ValidationOutcome::Validated === $outcome) {
            // Shown as a toast with the new total (templates/admin/flash_messages.html.twig).
            $this->addFlash('cagnotte', ['amount' => (int) $amount, 'total' => $this->fund->total()]);
        } else {
            $this->addFlash('info', 'Ce test drive était déjà encaissé : aucun montant ajouté.');
        }

        return $this->redirect($return);
    }

    /**
     * The cash-in modals take the total received, Alpha Ford's share included
     * (FundContribution::TEST_DRIVE_AMOUNT by default): returns the client's part of it,
     * or null when it is not a whole number between that share and MAX_RECEIVED_AMOUNT.
     */
    public static function clientAmountOf(string $total): ?int
    {
        if (!ctype_digit($total) || (int) $total < FundContribution::ALPHA_FORD_SHARE || (int) $total > self::MAX_RECEIVED_AMOUNT) {
            return null;
        }

        return (int) $total - FundContribution::ALPHA_FORD_SHARE;
    }

    public static function invalidAmountMessage(): string
    {
        return sprintf('Saisissez le montant reçu (total, dont %d DT d’Alpha Ford), entre %d et %d DT.', FundContribution::ALPHA_FORD_SHARE, FundContribution::ALPHA_FORD_SHARE, self::MAX_RECEIVED_AMOUNT);
    }

    /**
     * Walk-in test drive, added by hand for today (no slot). When "déjà effectué" is ticked,
     * it is cashed in right away with the amount received, through TestDriveValidator.
     * Admins only.
     */
    #[IsGranted('ROLE_ADMIN')]
    #[AdminRoute(path: '/ajouter', name: 'add', options: ['methods' => ['GET', 'POST']])]
    public function addTestDrive(Request $request): Response
    {
        $form = $this->createForm(ManualTestDriveType::class, ['completed' => true, 'amount' => FundContribution::CLIENT_SHARE]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $user = $this->getUser()?->getUserIdentifier() ?? 'inconnu';
            $reservation = ManualTestDriveType::apply(
                Reservation::createManual($data['experience'], $this->today(), $user, $this->clock->now()),
                $data,
            );

            $violations = $this->entityValidator->validate($reservation);
            foreach ($violations as $violation) {
                $path = $violation->getPropertyPath();
                $field = \in_array($path, ManualTestDriveType::ENTITY_FIELDS, true) ? $form->get($path) : $form;
                $field->addError(new FormError((string) $violation->getMessage()));
            }

            if (0 === \count($violations)) {
                $this->entityManager->persist($reservation);
                $this->entityManager->flush();

                $amount = (int) $data['amount'];
                if ($data['completed'] && ValidationOutcome::Validated === $this->validator->validate((int) $reservation->getId(), $user, clientAmount: $amount)) {
                    $this->addFlash('cagnotte', ['amount' => $amount + FundContribution::ALPHA_FORD_SHARE, 'total' => $this->fund->total()]);
                } else {
                    $this->addFlash('success', sprintf('Test drive de %s ajouté.', $reservation->getFullName()));
                }

                return $this->redirect($this->indexUrl());
            }
        }

        return $this->render('admin/test_drive/add.html.twig', [
            'form' => $form,
            'today' => $this->today(),
            'backUrl' => $this->indexUrl(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * The selected day's list, as shown to the cashier (createIndexQueryBuilder() applies the day scope).
     *
     * @param AdminContext<Reservation> $context
     */
    #[IsGranted('ROLE_CASHIER')]
    #[AdminRoute(path: '/export', name: 'export')]
    public function exportExcel(AdminContext $context): StreamedResponse
    {
        return $this->exportReservations($context, 'encaissement-' . $this->selectedDay()->format('Y-m-d'));
    }

    private function today(): \DateTimeImmutable
    {
        return $this->schedule->dayOf($this->clock->now());
    }

    /**
     * Tabs: every event day of the slot-based experiences, plus today (walk-ins, planned
     * October requests).
     *
     * @return list<\DateTimeImmutable>
     */
    private function days(): array
    {
        $days = [$this->today()->format('Y-m-d') => $this->today()];
        foreach (Experience::cases() as $experience) {
            foreach ($this->schedule->dates($experience) as $date) {
                $days[$date->format('Y-m-d')] = $date;
            }
        }
        ksort($days);

        return array_values($days);
    }

    /**
     * Day chosen with the "day" query parameter (one of the tabs), today otherwise.
     */
    private function selectedDay(): \DateTimeImmutable
    {
        $requested = (string) $this->requestStack->getCurrentRequest()?->query->get('day');
        foreach ($this->days() as $day) {
            if ($day->format('Y-m-d') === $requested) {
                return $day;
            }
        }

        return $this->today();
    }

    private function indexUrl(): string
    {
        return $this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->unset('entityId')->generateUrl();
    }

    /**
     * @param list<Experience|Vehicle|ReservationStatus|TestDriveStatus> $cases
     *
     * @return array<string, Experience|Vehicle|ReservationStatus|TestDriveStatus> label => case
     */
    private static function choices(array $cases): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[$case->label()] = $case;
        }

        return $choices;
    }
}
