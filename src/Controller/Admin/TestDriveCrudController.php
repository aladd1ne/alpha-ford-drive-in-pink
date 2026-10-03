<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\FundContribution;
use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\ReservationStatus;
use App\Enum\TestDriveStatus;
use App\Enum\Vehicle;
use App\Repository\ReservationRepository;
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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Commercial space: today's registrations and the "Test drive effectué" confirmation,
 * which credits the solidarity fund (see TestDriveValidator).
 *
 * @extends AbstractCrudController<Reservation>
 */
#[IsGranted('ROLE_COMMERCIAL')]
#[AdminRoute(path: '/test-drives', name: 'test_drive')]
class TestDriveCrudController extends AbstractCrudController
{
    public const VALIDATE_ACTION = 'validateTestDrive';
    public const VALIDATE_ROUTE = 'admin_test_drive_validate';

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly SlotSchedule $schedule,
        private readonly ClockInterface $clock,
        private readonly TestDriveValidator $validator,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly UrlGeneratorInterface $urlGenerator,
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
            ->setEntityLabelInSingular('Test drive')
            ->setEntityLabelInPlural('Test drives')
            ->setPageTitle(Crud::PAGE_INDEX, 'Test drives du ' . $this->today()->format('d/m/Y'))
            ->setHelp(Crud::PAGE_INDEX, 'Personnes inscrites aujourd’hui. Confirmez chaque essai réalisé : 30 DT sont alors ajoutés à la cagnotte (10 DT client + 20 DT Alpha Ford).')
            ->setSearchFields(['fullName', 'email', 'phone'])
            ->setPaginatorPageSize(100)
            ->showEntityActionsInlined();
    }

    /**
     * "Test drive effectué" button, shared with the admin reservation list. It always posts
     * to this controller's validate route, so the same checks apply wherever it is shown.
     * $allowFutureDate must match what the route accepts for the user (admins only).
     */
    public static function validateAction(UrlGeneratorInterface $urlGenerator, \DateTimeImmutable $today, bool $allowFutureDate = false): Action
    {
        return Action::new(self::VALIDATE_ACTION, 'Test drive effectué', 'fas fa-check')
            ->linkToUrl(static fn (Reservation $reservation): string => $urlGenerator->generate(self::VALIDATE_ROUTE, ['entityId' => $reservation->getId()]))
            ->setTemplatePath('admin/action/validate_test_drive.html.twig')
            ->displayIf(static fn (Reservation $reservation): bool => $reservation->canValidateTestDrive($today, $allowFutureDate));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, self::validateAction($this->urlGenerator, $this->today()))
            ->setPermission(self::VALIDATE_ACTION, 'ROLE_COMMERCIAL')
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
        ]);
        yield ChoiceField::new('testDriveStatus', 'Test drive')->renderAsBadges([
            TestDriveStatus::ToValidate->name => 'warning',
            TestDriveStatus::Completed->name => 'success',
        ]);
        yield DateTimeField::new('testDriveCompletedAt', 'Validé le')->setFormat('HH:mm');
        yield TextField::new('testDriveValidatedBy', 'Validé par');
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return $this->reservations->applyDayScope(
            parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters),
            $this->today(),
        );
    }

    #[IsGranted('ROLE_COMMERCIAL')]
    #[AdminRoute(path: '/{entityId}/validate', name: 'validate', options: ['methods' => ['POST'], 'requirements' => ['entityId' => '\d+']])]
    public function validateTestDrive(Request $request): RedirectResponse
    {
        $reservationId = (int) $request->attributes->get('entityId', $request->query->get('entityId'));

        if (!$this->isCsrfTokenValid('validate-test-drive-' . $reservationId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide, veuillez réessayer.');

            return $this->redirect($this->returnUrl($request));
        }

        try {
            // Admins may confirm a test drive before its planned date; commercials may not.
            $outcome = $this->validator->validate(
                $reservationId,
                $this->getUser()?->getUserIdentifier() ?? 'inconnu',
                $this->isGranted('ROLE_ADMIN'),
            );
        } catch (ReservationNotFoundException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        } catch (TestDriveNotEligibleException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirect($this->returnUrl($request));
        }

        if (ValidationOutcome::Validated === $outcome) {
            // Shown as a toast with the new total (templates/admin/flash_messages.html.twig).
            $this->addFlash('cagnotte', ['amount' => FundContribution::TEST_DRIVE_AMOUNT, 'total' => $this->fund->total()]);
        } else {
            $this->addFlash('info', 'Ce test drive était déjà validé : aucun montant ajouté.');
        }

        return $this->redirect($this->returnUrl($request));
    }

    private function today(): \DateTimeImmutable
    {
        return $this->schedule->dayOf($this->clock->now());
    }

    /**
     * Back to the list the action was posted from (commercial or admin list), or today's list.
     * Only back-office paths are accepted, so the field cannot be used as an open redirect.
     */
    private function returnUrl(Request $request): string
    {
        $return = (string) $request->request->get('_return');
        if (str_starts_with($return, '/admin/') && !str_contains($return, '\\')) {
            return $return;
        }

        return $this->indexUrl();
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
