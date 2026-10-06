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
 * @extends AbstractCrudController<Reservation>
 */
#[IsGranted('ROLE_RESERVATIONS')]
class ReservationCrudController extends AbstractCrudController
{
    use ExportsReservationsToExcel;
    use RedirectsToBackOfficeList;

    public const CONFIRM_ACTION = 'confirmReservation';
    public const EDIT_ACTION = 'editReservation';
    public const ARCHIVE_ACTION = 'archiveReservation';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly ClockInterface $clock,
        private readonly ReservationRepository $reservations,
        private readonly ReservationManager $manager,
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
        $confirm = $this->rowAction(self::CONFIRM_ACTION, 'Valider', 'fas fa-check', 'btn btn-sm btn-primary', 'reservation-confirm-', 'Valider la réservation de %name% ?')
            ->displayIf(static fn (Reservation $reservation): bool => ReservationStatus::Pending === $reservation->getStatus());
        $archive = $this->rowAction(self::ARCHIVE_ACTION, 'Archiver', 'fas fa-box-archive', 'btn btn-sm btn-outline-secondary', 'reservation-archive-', 'Archiver la réservation de %name% ? Son créneau sera libéré.')
            ->displayIf(static fn (Reservation $reservation): bool => ReservationStatus::Archived !== $reservation->getStatus());
        $urlGenerator = $this->urlGenerator;
        $edit = Action::new(self::EDIT_ACTION, 'Modifier', 'fas fa-pen')
            ->linkToUrl(static fn (Reservation $reservation): string => $urlGenerator->generate('admin_reservation_modify', ['entityId' => $reservation->getId()]))
            ->displayIf(static fn (Reservation $reservation): bool => $reservation->getStatus()->isActive());

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, self::exportExcelAction())
            ->add(Crud::PAGE_INDEX, $confirm)
            ->add(Crud::PAGE_INDEX, $edit)
            ->add(Crud::PAGE_INDEX, $archive)
            ->add(Crud::PAGE_DETAIL, $confirm)
            ->add(Crud::PAGE_DETAIL, $edit)
            ->add(Crud::PAGE_DETAIL, $archive)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, static fn (Action $action): Action => $action->setIcon('fas fa-eye')->setLabel(false)->setHtmlAttributes(['title' => 'Voir le détail']))
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

    #[AdminRoute(path: '/{entityId}/confirmer', name: 'confirm', options: ['methods' => ['POST'], 'requirements' => ['entityId' => '\d+']])]
    public function confirmReservation(Request $request): RedirectResponse
    {
        return $this->postAction($request, 'reservation-confirm-', function (Reservation $reservation): void {
            $this->manager->confirm($reservation);
            $this->addFlash('success', sprintf('Réservation de %s validée.', $reservation->getFullName()));
        });
    }

    #[AdminRoute(path: '/{entityId}/archiver', name: 'archive', options: ['methods' => ['POST'], 'requirements' => ['entityId' => '\d+']])]
    public function archiveReservation(Request $request): RedirectResponse
    {
        return $this->postAction($request, 'reservation-archive-', function (Reservation $reservation): void {
            $this->manager->archive($reservation);
            $this->addFlash('success', sprintf('Réservation de %s archivée.', $reservation->getFullName()));
        });
    }

    #[AdminRoute(path: '/{entityId}/modifier', name: 'modify', options: ['methods' => ['GET', 'POST'], 'requirements' => ['entityId' => '\d+']])]
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
