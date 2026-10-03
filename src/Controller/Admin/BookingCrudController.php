<?php

namespace App\Controller\Admin;

use App\Entity\Booking;
use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BookingCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return Booking::class; }

    public function __construct(private readonly AdminUrlGenerator $adminUrlGenerator) {}

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réservation')
            ->setEntityLabelInPlural('Réservations')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['reference', 'customerName', 'phone', 'email']);
    }

    private const STATUS_LABELS = [
        'En attente'  => 'pending',
        'Confirmée'   => 'confirmed',
        'Terminée'    => 'completed',
        'Annulée'     => 'cancelled',
    ];

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('reference', 'Réf.')->setMaxLength(12);
        yield TextField::new('customerName', 'Client');
        yield TextField::new('phone', 'Téléphone')->hideOnIndex();
        yield TextField::new('email', 'E-mail')->hideOnIndex();
        yield AssociationField::new('category', 'Expérience');
        yield NumberField::new('peopleCount', 'Pers.')->hideOnDetail(false);
        yield DateField::new('preferredDate', 'Date souhaitée');
        yield NumberField::new('totalPrice', 'Total TND')->setNumDecimals(2)->setStoredAsString(true);
        yield NumberField::new('depositAmount', 'Acompte')->setNumDecimals(0)->setStoredAsString(true)->hideOnIndex();
        yield ChoiceField::new('status', 'Statut')
            ->setChoices(self::STATUS_LABELS)
            ->renderAsBadges([
                'pending'   => 'warning',
                'confirmed' => 'success',
                'completed' => 'primary',
                'cancelled' => 'danger',
            ]);
        yield BooleanField::new('archived', 'Archivée')->renderAsSwitch(false)->hideOnForm();
        yield DateTimeField::new('createdAt', 'Créée le')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Modifiée le')->hideOnForm()->hideOnIndex();

        if ($pageName === Crud::PAGE_DETAIL) {
            yield TextField::new('selectedExtras', 'Options choisies')
                ->formatValue(fn ($v) => empty($v) ? '—' : implode(', ', array_column($v, 'name')));
        }
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices(self::STATUS_LABELS))
            ->add(EntityFilter::new('category', 'Expérience'))
            ->add(DateTimeFilter::new('preferredDate', 'Date souhaitée'))
            ->add(DateTimeFilter::new('createdAt', 'Créée le'))
            ->add(TextFilter::new('customerName', 'Nom client'))
            ->add(TextFilter::new('phone', 'Téléphone'))
            ->add(BooleanFilter::new('archived', 'Archivée'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $confirm = Action::new('confirm', 'Confirmer', 'fas fa-check')
            ->linkToCrudAction('confirmBooking')
            ->setCssClass('btn btn-sm text-success')
            ->displayIf(fn(Booking $b) => $b->getStatus() === 'pending');

        $complete = Action::new('complete', 'Terminer', 'fas fa-flag-checkered')
            ->linkToCrudAction('completeBooking')
            ->setCssClass('btn btn-sm text-primary')
            ->displayIf(fn(Booking $b) => $b->getStatus() === 'confirmed');

        $cancel = Action::new('cancel', 'Annuler', 'fas fa-times')
            ->linkToCrudAction('cancelBooking')
            ->setCssClass('btn btn-sm text-danger')
            ->displayIf(fn(Booking $b) => in_array($b->getStatus(), ['pending', 'confirmed'], true));

        $archive = Action::new('toggleArchive', 'Archiver / Restaurer', 'fas fa-box-archive')
            ->linkToCrudAction('toggleArchive')
            ->setCssClass('btn btn-sm');

        $exportCsv = Action::new('exportCsv', 'Export CSV', 'fas fa-file-csv')
            ->linkToRoute('admin_bookings_csv')
            ->createAsGlobalAction()
            ->setCssClass('btn btn-sm btn-secondary');

        return $actions
            ->disable(Action::DELETE, Action::EDIT)
            ->add(Crud::PAGE_INDEX, $confirm)
            ->add(Crud::PAGE_INDEX, $complete)
            ->add(Crud::PAGE_INDEX, $cancel)
            ->add(Crud::PAGE_INDEX, $archive)
            ->add(Crud::PAGE_INDEX, $exportCsv)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $confirm)
            ->add(Crud::PAGE_DETAIL, $complete)
            ->add(Crud::PAGE_DETAIL, $cancel);
    }

    public function confirmBooking(AdminContext $context): RedirectResponse
    {
        return $this->changeStatus($context, 'confirmed', 'Réservation confirmée.');
    }

    public function completeBooking(AdminContext $context): RedirectResponse
    {
        return $this->changeStatus($context, 'completed', 'Réservation marquée comme terminée.');
    }

    public function cancelBooking(AdminContext $context): RedirectResponse
    {
        return $this->changeStatus($context, 'cancelled', 'Réservation annulée.');
    }

    public function toggleArchive(AdminContext $context): RedirectResponse
    {
        /** @var Booking $booking */
        $booking = $context->getEntity()->getInstance();
        $booking->setArchived(!$booking->isArchived());
        $this->container->get('doctrine')->getManager()->flush();
        $this->addFlash('success', $booking->isArchived() ? 'Réservation archivée.' : 'Réservation restaurée.');
        return $this->redirect($this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
    }

    private function changeStatus(AdminContext $context, string $status, string $message): RedirectResponse
    {
        /** @var Booking $booking */
        $booking = $context->getEntity()->getInstance();
        $booking->setStatus($status);
        $booking->setUpdatedAt(new \DateTime());
        $this->container->get('doctrine')->getManager()->flush();
        $this->addFlash('success', $message);
        $referrer = $context->getReferrer();
        return $this->redirect(
            $referrer ?? $this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl()
        );
    }

    #[Route('/admin/bookings/export.csv', name: 'admin_bookings_csv')]
    public function exportCsv(BookingRepository $repo): Response
    {
        $bookings = $repo->findBy(['archived' => false], ['createdAt' => 'DESC']);

        $rows = ["Référence,Nom,Téléphone,Email,Expérience,Personnes,Date,Total,Acompte,Statut,Créé le"];
        foreach ($bookings as $b) {
            $rows[] = implode(',', [
                $b->getReference(),
                '"' . str_replace('"', '""', $b->getCustomerName()) . '"',
                $b->getPhone(),
                $b->getEmail(),
                '"' . str_replace('"', '""', $b->getCategory()->getName()) . '"',
                $b->getPeopleCount(),
                $b->getPreferredDate()?->format('Y-m-d') ?? '',
                $b->getTotalPrice(),
                $b->getDepositAmount(),
                $b->getStatus(),
                $b->getCreatedAt()?->format('Y-m-d H:i:s') ?? '',
            ]);
        }

        return new Response(implode("\n", $rows), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="reservations-' . date('Y-m-d') . '.csv"',
        ]);
    }
}
