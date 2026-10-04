<?php

namespace App\Controller\Admin;

use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\ReservationStatus;
use App\Enum\TestDriveStatus;
use App\Enum\Vehicle;
use App\Service\Reservation\SlotSchedule;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only list of all reservations (admins). Admins can confirm a test drive from here
 * too; the action posts to TestDriveCrudController, which does the validation.
 *
 * @extends AbstractCrudController<Reservation>
 */
#[IsGranted('ROLE_ADMIN')]
class ReservationCrudController extends AbstractCrudController
{
    use ExportsReservationsToExcel;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly SlotSchedule $schedule,
        private readonly ClockInterface $clock,
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
            ->setPaginatorPageSize(50)
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, self::exportExcelAction())
            ->add(Crud::PAGE_INDEX, $this->validateAction())
            ->add(Crud::PAGE_DETAIL, $this->validateAction())
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
        yield ChoiceField::new('status', 'Statut')->hideOnIndex()->renderAsBadges([
            ReservationStatus::Pending->name => 'warning',
            ReservationStatus::Confirmed->name => 'success',
            ReservationStatus::Cancelled->name => 'secondary',
        ]);
        yield ChoiceField::new('testDriveStatus', 'Test drive')->renderAsBadges([
            TestDriveStatus::ToValidate->name => 'warning',
            TestDriveStatus::Completed->name => 'success',
        ]);
        yield DateTimeField::new('testDriveCompletedAt', 'Test drive validé le')->setFormat('d MMM yyyy HH:mm')->hideOnIndex();
        yield TextField::new('testDriveValidatedBy', 'Validé par')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Reçue le')->setFormat('d MMM yyyy HH:mm')->hideOnIndex();
    }

    /**
     * @param AdminContext<Reservation> $context
     */
    #[AdminRoute(path: '/export', name: 'export')]
    public function exportExcel(AdminContext $context): StreamedResponse
    {
        return $this->exportReservations($context, 'reservations');
    }

    private function validateAction(): Action
    {
        return TestDriveCrudController::validateAction(
            $this->urlGenerator,
            $this->schedule->dayOf($this->clock->now()),
            // This list is admin-only, and admins may validate before the planned date.
            allowFutureDate: true,
        );
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
