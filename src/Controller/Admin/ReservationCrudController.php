<?php

namespace App\Controller\Admin;

use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\ReservationStatus;
use App\Enum\TestDriveStatus;
use App\Enum\Vehicle;
use App\Form\Admin\ReservationEditType;
use App\Repository\ReservationRepository;
use App\Service\Reservation\ReservationChangeException;
use App\Service\Reservation\ReservationManager;
use App\Service\Reservation\SlotSchedule;
use App\Service\SolidarityFund;
use App\Service\TestDrive\ReservationNotFoundException;
use App\Service\TestDrive\TestDriveNotEligibleException;
use App\Service\TestDrive\TestDriveValidator;
use App\Service\TestDrive\ValidationOutcome;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
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
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reservations team: every reservation (event days and "autres jours d'octobre"
 * requests), which they confirm, edit (contact, date, vehicle, slot) and archive.
 * Archived reservations are hidden unless the status filter asks for them.
 *
 * The cashier (ROLE_CASHIER, which includes ROLE_RESERVATIONS_ACCESS) gets the same pages
 * but can only view and validate (no Modifier / Archiver); on a
 * reservation whose test drive can be cashed in (any day), "Valider" also takes the total
 * received (validated test drive + cagnotte credit, see TestDriveValidator), and a confirmed one
 * not cashed in yet has the "Encaisser" action of the cashier space.
 *
 * @extends AbstractCrudController<Reservation>
 */
#[IsGranted('ROLE_RESERVATIONS_ACCESS')]
class ReservationCrudController extends AbstractCrudController
{
    use ExportsReservationsToExcel;
    use RedirectsToBackOfficeList;

    public const CONFIRM_ACTION = 'confirmReservation';
    public const EDIT_ACTION = 'editReservation';
    public const ARCHIVE_ACTION = 'archiveReservation';
    public const CONFIRM_CASH_IN_ACTION = 'confirmAndCashIn';
    public const CASH_IN_ACTION = 'cashInTestDrive';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly ClockInterface $clock,
        private readonly ReservationRepository $reservations,
        private readonly ReservationManager $manager,
        private readonly TestDriveValidator $validator,
        private readonly SlotSchedule $schedule,
        private readonly SolidarityFund $fund,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Reservation::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réservation')
            ->setEntityLabelInPlural('Réservations')
            ->setDefaultSort(['date' => 'ASC', 'slot' => 'ASC', 'createdAt' => 'ASC'])
            ->setSearchFields(['fullName', 'email', 'phone'])
            ->overrideTemplate('crud/detail', 'admin/reservation/detail.html.twig')
            ->setHelp(Crud::PAGE_INDEX, 'Les demandes « Autres jours d’octobre » se valident une fois leur date et leur véhicule définis (Modifier). Les réservations archivées sont masquées : filtre Statut = Archivée pour les revoir.')
            ->setPaginatorPageSize(50)
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        // A reservation the current user may cash in (cashier or admin, any day).
        $cashier = $this->isGranted('ROLE_CASHIER');
        $today = $this->schedule->dayOf($this->clock->now());
        $cashable = static fn (Reservation $reservation): bool => $cashier && $reservation->canValidateTestDrive($today, true);

        $confirm = $this->rowAction(self::CONFIRM_ACTION, 'Valider', 'fas fa-check', 'btn btn-sm btn-primary', 'reservation-confirm-', 'Valider la réservation de %name% ?')
            ->displayIf(static fn (Reservation $reservation): bool => ReservationStatus::Pending === $reservation->getStatus() && !$cashable($reservation));
        $confirmCashIn = $this->rowAction(self::CONFIRM_CASH_IN_ACTION, 'Valider', 'fas fa-check', 'btn btn-sm btn-primary', 'reservation-confirm-', '')
            ->setTemplatePath('admin/action/confirm_cash_in.html.twig')
            ->displayIf(static fn (Reservation $reservation): bool => ReservationStatus::Pending === $reservation->getStatus() && $cashable($reservation));
        $archive = $this->rowAction(self::ARCHIVE_ACTION, 'Archiver', 'fas fa-box-archive', 'btn btn-sm btn-outline-danger', 'reservation-archive-', 'Archiver la réservation de %name% ? Son créneau sera libéré.')
            ->displayIf(static fn (Reservation $reservation): bool => ReservationStatus::Archived !== $reservation->getStatus());
        $urlGenerator = $this->urlGenerator;
        $edit = Action::new(self::EDIT_ACTION, 'Modifier', 'fas fa-pen')
            ->setCssClass('btn btn-sm btn-outline-secondary')
            ->setTemplatePath('admin/action/link_action.html.twig')
            ->linkToUrl(static fn (Reservation $reservation): string => $urlGenerator->generate('admin_reservation_modify', ['entityId' => $reservation->getId()]))
            ->displayIf(static fn (Reservation $reservation): bool => $reservation->getStatus()->isActive());
        $cashIn = Action::new(self::CASH_IN_ACTION, 'Encaisser', 'fas fa-cash-register')
            ->linkToUrl(static fn (Reservation $reservation): string => $urlGenerator->generate(TestDriveCrudController::VALIDATE_ROUTE, ['entityId' => $reservation->getId()]))
            ->setTemplatePath('admin/action/cash_in.html.twig')
            ->displayIf(static fn (Reservation $reservation): bool => ReservationStatus::Pending !== $reservation->getStatus() && $cashable($reservation));

        // Same order on every row: the main action (Valider / Encaisser), then Modifier,
        // Archiver and the detail link; only one of the first three is shown at a time.
        $order = [self::CONFIRM_ACTION, self::CONFIRM_CASH_IN_ACTION, self::CASH_IN_ACTION, self::EDIT_ACTION, self::ARCHIVE_ACTION];

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, self::exportExcelAction())
            ->add(Crud::PAGE_INDEX, $confirm)
            ->add(Crud::PAGE_INDEX, $confirmCashIn)
            ->add(Crud::PAGE_INDEX, $cashIn)
            ->add(Crud::PAGE_INDEX, $edit)
            ->add(Crud::PAGE_INDEX, $archive)
            ->add(Crud::PAGE_DETAIL, $confirm)
            ->add(Crud::PAGE_DETAIL, $confirmCashIn)
            ->add(Crud::PAGE_DETAIL, $cashIn)
            ->add(Crud::PAGE_DETAIL, $edit)
            ->add(Crud::PAGE_DETAIL, $archive)
            ->setPermission(self::CONFIRM_CASH_IN_ACTION, 'ROLE_CASHIER')
            ->setPermission(self::CASH_IN_ACTION, 'ROLE_CASHIER')
            ->setPermission(self::EDIT_ACTION, 'ROLE_RESERVATIONS')
            ->setPermission(self::ARCHIVE_ACTION, 'ROLE_RESERVATIONS')
            ->update(Crud::PAGE_INDEX, Action::DETAIL, static fn (Action $action): Action => $action->setIcon('fas fa-eye')->setLabel(false)->setCssClass('btn btn-sm btn-outline-secondary')->setTemplatePath('admin/action/link_action.html.twig')->setHtmlAttributes(['title' => 'Voir le détail']))
            ->reorder(Crud::PAGE_INDEX, [...$order, Action::DETAIL])
            ->reorder(Crud::PAGE_DETAIL, $order)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('experience', 'Expérience')->setChoices(self::choices(Experience::cases())))
            ->add(DateTimeFilter::new('date', 'Date'))
            ->add(ChoiceFilter::new('vehicle', 'Véhicule')->setChoices(self::choices(Vehicle::cases())))
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices(self::choices(ReservationStatus::cases())))
            ->add(ChoiceFilter::new('testDriveStatus', 'Test drive')->setChoices(self::choices(TestDriveStatus::cases())));
    }

    public function configureFields(string $pageName): iterable
    {
        // Choices come from the enum types; labels from their TranslatableInterface.
        // The list keeps what is needed to find and call a client; the rest is on the detail page.
        yield TextField::new('fullName', 'Client');
        yield TelephoneField::new('phone', 'Téléphone');
        yield EmailField::new('email', 'E-mail')->hideOnIndex();
        yield ChoiceField::new('experience', 'Expérience');
        yield ChoiceField::new('vehicle', 'Véhicule');
        yield DateField::new('date', 'Date')->setFormat(Crud::PAGE_INDEX === $pageName ? 'EEE d MMM' : 'EEEE d MMMM yyyy');
        yield TextField::new('slot', 'Créneau');
        yield ChoiceField::new('status', 'Statut')->renderAsBadges([
            ReservationStatus::Pending->name => 'warning',
            ReservationStatus::Confirmed->name => 'success',
            ReservationStatus::Cancelled->name => 'secondary',
            ReservationStatus::Archived->name => 'secondary',
        ]);
        yield ChoiceField::new('testDriveStatus', 'Test drive')->renderAsBadges([
            TestDriveStatus::ToValidate->name => 'warning',
            TestDriveStatus::Completed->name => 'success',
        ]);
        yield DateTimeField::new('testDriveCompletedAt', 'Test drive encaissé le')->setFormat('d MMM yyyy HH:mm')->hideOnIndex();
        yield TextField::new('testDriveValidatedBy', 'Encaissé par')->hideOnIndex();
        yield TextField::new('createdBy', 'Ajouté par')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Reçue le')->setFormat('d MMM yyyy HH:mm')->hideOnIndex();
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        // Archived reservations only appear when the status filter is used.
        if (!\array_key_exists('status', $searchDto->getAppliedFilters())) {
            $qb->andWhere(sprintf('%s.status != :archived', $qb->getRootAliases()[0]))
                ->setParameter('archived', ReservationStatus::Archived);
        }

        return $qb;
    }

    /**
     * Confirms the booking. With an amount (cashier only), the test drive is cashed in
     * instead: the reservation is confirmed and the cagnotte credited in one go.
     */
    #[AdminRoute(path: '/{entityId}/confirmer', name: 'confirm', options: ['methods' => ['POST'], 'requirements' => ['entityId' => '\d+']])]
    public function confirmReservation(Request $request): RedirectResponse
    {
        $amount = trim((string) $request->request->get('amount'));

        if ('' === $amount) {
            return $this->postAction($request, 'reservation-confirm-', function (Reservation $reservation): void {
                $this->manager->confirm($reservation);
                $this->addFlash('success', sprintf('Réservation de %s validée.', $reservation->getFullName()));
            });
        }

        $this->denyAccessUnlessGranted('ROLE_CASHIER');

        return $this->postAction($request, 'reservation-confirm-', function (Reservation $reservation) use ($amount): void {
            $clientAmount = TestDriveCrudController::clientAmountOf($amount)
                ?? throw new ReservationChangeException(TestDriveCrudController::invalidAmountMessage());

            try {
                $outcome = $this->validator->validate(
                    (int) $reservation->getId(),
                    $this->getUser()?->getUserIdentifier() ?? 'inconnu',
                    true,
                    $clientAmount,
                );
            } catch (ReservationNotFoundException $e) {
                throw $this->createNotFoundException($e->getMessage(), $e);
            } catch (TestDriveNotEligibleException $e) {
                throw new ReservationChangeException($e->getMessage(), null, $e);
            }

            if (ValidationOutcome::Validated === $outcome) {
                // Shown as a toast with the new total (templates/admin/flash_messages.html.twig).
                $this->addFlash('cagnotte', ['amount' => (int) $amount, 'total' => $this->fund->total()]);
            } else {
                $this->addFlash('info', 'Ce test drive était déjà encaissé : aucun montant ajouté.');
            }
        });
    }

    #[AdminRoute(path: '/{entityId}/archiver', name: 'archive', options: ['methods' => ['POST'], 'requirements' => ['entityId' => '\d+']])]
    #[IsGranted('ROLE_RESERVATIONS')]
    public function archiveReservation(Request $request): RedirectResponse
    {
        return $this->postAction($request, 'reservation-archive-', function (Reservation $reservation): void {
            $this->manager->archive($reservation);
            $this->addFlash('success', sprintf('Réservation de %s archivée.', $reservation->getFullName()));
        });
    }

    #[AdminRoute(path: '/{entityId}/modifier', name: 'modify', options: ['methods' => ['GET', 'POST'], 'requirements' => ['entityId' => '\d+']])]
    #[IsGranted('ROLE_RESERVATIONS')]
    public function editReservation(Request $request): Response
    {
        $reservation = $this->findReservation($request);
        $form = $this->createForm(ReservationEditType::class, $reservation, ['reservation' => $reservation]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $date = $form->get('date')->getData();
            $slot = $form->has('slot') ? $form->get('slot')->getData() : null;

            try {
                $this->manager->update(
                    $reservation,
                    null === $date || '' === $date ? null : new \DateTimeImmutable($date),
                    $form->get('vehicle')->getData(),
                    $slot,
                );
                $this->addFlash('success', sprintf('Réservation de %s modifiée.', $reservation->getFullName()));

                return $this->redirect($this->detailUrl($reservation));
            } catch (ReservationChangeException $e) {
                $target = null !== $e->field && $form->has($e->field) ? $form->get($e->field) : $form;
                $target->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('admin/reservation/edit.html.twig', [
            'form' => $form,
            'reservation' => $reservation,
            'backUrl' => $this->detailUrl($reservation),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @param AdminContext<Reservation> $context
     */
    #[AdminRoute(path: '/export', name: 'export')]
    public function exportExcel(AdminContext $context): StreamedResponse
    {
        return $this->exportReservations($context, 'reservations');
    }

    private function rowAction(string $name, string $label, string $icon, string $cssClass, string $csrfPrefix, string $question): Action
    {
        $urlGenerator = $this->urlGenerator;
        $route = self::ARCHIVE_ACTION === $name ? 'admin_reservation_archive' : 'admin_reservation_confirm';

        return Action::new($name, $label, $icon)
            ->linkToUrl(static fn (Reservation $reservation): string => $urlGenerator->generate($route, ['entityId' => $reservation->getId()]))
            ->setTemplatePath('admin/action/post_action.html.twig')
            ->setCssClass($cssClass)
            ->setHtmlAttributes(['data-csrf' => $csrfPrefix, 'data-confirm' => $question]);
    }

    /**
     * @param callable(Reservation): void $operation
     */
    private function postAction(Request $request, string $csrfPrefix, callable $operation): RedirectResponse
    {
        $reservation = $this->findReservation($request);
        $return = $this->returnUrl($request, $this->indexUrl());

        if (!$this->isCsrfTokenValid($csrfPrefix . $reservation->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide, veuillez réessayer.');

            return $this->redirect($return);
        }

        try {
            $operation($reservation);
        } catch (ReservationChangeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($return);
    }

    private function findReservation(Request $request): Reservation
    {
        $id = (int) $request->attributes->get('entityId');

        return $this->reservations->find($id) ?? throw $this->createNotFoundException(sprintf('Reservation #%d not found.', $id));
    }

    private function indexUrl(): string
    {
        return $this->adminUrlGenerator->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl();
    }

    private function detailUrl(Reservation $reservation): string
    {
        return $this->adminUrlGenerator->unsetAll()->setController(self::class)->setAction(Action::DETAIL)->setEntityId($reservation->getId())->generateUrl();
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
