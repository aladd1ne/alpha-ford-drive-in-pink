<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\FundContribution;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Cagnotte history: every credit (validated test drives and amounts added by hand
 * earlier). Only admins can delete one, and only a manual one (test drive credits are
 * tied to their validation).
 *
 * @extends AbstractCrudController<FundContribution>
 */
#[IsGranted('ROLE_CASHIER')]
#[AdminRoute(path: '/cagnotte', name: 'fund_contribution')]
class FundContributionCrudController extends AbstractCrudController
{
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
}
