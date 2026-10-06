<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\FundContribution;
use App\Form\Admin\ManualContributionType;
use App\Service\SolidarityFund;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Cagnotte history: every credit (validated test drives and amounts added by hand).
 * Cashiers can add an amount by hand; only admins can delete one, and only a manual
 * one (test drive credits are tied to their validation).
 *
 * @extends AbstractCrudController<FundContribution>
 */
#[IsGranted('ROLE_CASHIER')]
#[AdminRoute(path: '/cagnotte', name: 'fund_contribution')]
class FundContributionCrudController extends AbstractCrudController
{
    public const ADD_ACTION = 'addAmount';
    public const ADD_ROUTE = 'admin_fund_contribution_add';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SolidarityFund $fund,
        private readonly ClockInterface $clock,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return FundContribution::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Montant')
            ->setEntityLabelInPlural('Cagnotte')
            ->setPageTitle(Crud::PAGE_INDEX, 'Cagnotte : historique des montants')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['note', 'validatedBy'])
            ->setPaginatorPageSize(100)
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::new(self::ADD_ACTION, 'Ajouter un montant', 'fas fa-plus')
                ->linkToUrl($this->urlGenerator->generate(self::ADD_ROUTE))
                ->addCssClass('btn btn-primary')
                ->createAsGlobalAction())
            ->setPermission(self::ADD_ACTION, 'ROLE_CASHIER')
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $action): Action => $action
                ->displayIf(static fn (FundContribution $contribution): bool => $contribution->isManual()))
            ->setPermission(Action::DELETE, 'ROLE_ADMIN')
            ->disable(Action::NEW, Action::EDIT, Action::DETAIL, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', 'Date')->setFormat('d MMM yyyy HH:mm');
        yield IntegerField::new('amount', 'Montant (DT)');
        yield IntegerField::new('clientAmount', 'Reçu du client (DT)');
        yield TextField::new('note', 'Origine')
            ->formatValue(static fn ($value, FundContribution $contribution): string => $contribution->isManual()
                ? (string) $contribution->getNote()
                : sprintf('Test drive : %s', $contribution->getReservation()?->getFullName()));
        yield TextField::new('validatedBy', 'Ajouté par');
    }

    public function delete(AdminContext $context)
    {
        $contribution = $context->getEntity()->getInstance();
        if (!$contribution instanceof FundContribution || !$contribution->isManual()) {
            throw $this->createAccessDeniedException('Seuls les montants ajoutés à la main peuvent être supprimés.');
        }

        return parent::delete($context);
    }

    #[IsGranted('ROLE_CASHIER')]
    #[AdminRoute(path: '/ajouter', name: 'add', options: ['methods' => ['GET', 'POST']])]
    public function addAmount(Request $request): Response
    {
        $form = $this->createForm(ManualContributionType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $this->entityManager->persist(FundContribution::manual(
                (int) $data['amount'],
                (string) $data['note'],
                $this->clock->now(),
                $this->getUser()?->getUserIdentifier() ?? 'inconnu',
            ));
            $this->entityManager->flush();

            // Shown as a toast with the new total (templates/admin/flash_messages.html.twig).
            $this->addFlash('cagnotte', [
                'amount' => (int) $data['amount'],
                'total' => $this->fund->total(),
                'title' => 'Montant ajouté — cagnotte créditée',
            ]);

            return $this->redirect($this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
        }

        return $this->render('admin/fund/add.html.twig', [
            'form' => $form,
            'total' => $this->fund->total(),
            'backUrl' => $this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
