<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Repository\FundContributionRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admins manage the products sold for the cause and their price: selling one on the
 * "Ajouter à la cagnotte" page credits the cagnotte with its price times the quantity.
 * A product already sold can only be deactivated, not deleted (the cagnotte history
 * keeps pointing to it).
 *
 * @extends AbstractCrudController<Product>
 */
#[IsGranted('ROLE_ADMIN')]
#[AdminRoute(path: '/produits', name: 'product')]
class ProductCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly FundContributionRepository $contributions,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Produit')
            ->setEntityLabelInPlural('Produits')
            ->setPageTitle(Crud::PAGE_NEW, 'Ajouter un produit')
            ->setHelp(Crud::PAGE_INDEX, 'Produits vendus pour la cause. À la vente, la cagnotte est créditée du prix × la quantité ; changer un prix ne modifie pas les ventes déjà enregistrées. Un produit déjà vendu peut être désactivé mais pas supprimé.')
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $contributions = $this->contributions;

        return $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, static fn (Action $action): Action => $action->setLabel('Ajouter un produit'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $action): Action => $action
                ->displayIf(static fn (Product $product): bool => !$contributions->isProductSold($product)))
            ->disable(Action::DETAIL, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');
        yield IntegerField::new('price', 'Prix (DT)')
            ->setRequired(false)
            ->setFormTypeOption('attr', ['min' => 0, 'max' => Product::MAX_PRICE, 'step' => 1])
            ->setHelp('0 ou vide pour un produit offert.');
        yield BooleanField::new('active', 'En vente')
            ->renderAsSwitch(false)
            ->setHelp('Décochez pour ne plus le proposer à la vente.');
        yield DateTimeField::new('createdAt', 'Créé le')->setFormat('d MMM yyyy HH:mm')->hideOnForm();
    }

    public function delete(AdminContext $context)
    {
        $product = $context->getEntity()->getInstance();
        if ($product instanceof Product && $this->contributions->isProductSold($product)) {
            throw $this->createAccessDeniedException('Ce produit a déjà été vendu : désactivez-le plutôt.');
        }

        return parent::delete($context);
    }
}
