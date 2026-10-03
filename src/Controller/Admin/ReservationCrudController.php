<?php

namespace App\Controller\Admin;

use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\ReservationStatus;
use App\Enum\Vehicle;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

/**
 * Read-only reservation list. Commercial validation (confirm / cancel) comes in the next step.
 *
 * @extends AbstractCrudController<Reservation>
 */
class ReservationCrudController extends AbstractCrudController
{
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
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('experience', 'Expérience')->setChoices(self::choices(Experience::cases())))
            ->add(DateTimeFilter::new('date', 'Date'))
            ->add(ChoiceFilter::new('vehicle', 'Véhicule')->setChoices(self::choices(Vehicle::cases())))
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices(self::choices(ReservationStatus::cases())));
    }

    public function configureFields(string $pageName): iterable
    {
        // Choices come from the enum types; labels from their TranslatableInterface.
        yield ChoiceField::new('experience', 'Expérience');
        yield DateField::new('date', 'Date')->setFormat('EEE d MMM');
        yield TextField::new('slot', 'Créneau');
        yield ChoiceField::new('vehicle', 'Véhicule');
        yield TextField::new('fullName', 'Nom et prénom');
        yield TelephoneField::new('phone', 'Téléphone');
        yield EmailField::new('email', 'E-mail');
        yield ChoiceField::new('status', 'Statut')->renderAsBadges([
            ReservationStatus::Pending->name => 'warning',
            ReservationStatus::Confirmed->name => 'success',
            ReservationStatus::Cancelled->name => 'secondary',
        ]);
        yield DateTimeField::new('createdAt', 'Reçue le')->setFormat('d MMM yyyy HH:mm');
    }

    /**
     * @param list<Experience|Vehicle|ReservationStatus> $cases
     *
     * @return array<string, Experience|Vehicle|ReservationStatus> label => case
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
