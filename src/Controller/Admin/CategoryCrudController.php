<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Form\Admin\BringItemEmbedType;
use App\Form\Admin\ExtraEmbedType;
use App\Form\Admin\HighlightEmbedType;
use App\Form\Admin\IncludedItemEmbedType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;

class CategoryCrudController extends AbstractCrudController
{
    public function __construct(private readonly AdminUrlGenerator $adminUrlGenerator) {}

    public static function getEntityFqcn(): string { return Category::class; }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Expérience')
            ->setEntityLabelInPlural('Expériences')
            ->setDefaultSort(['id' => 'ASC'])
            ->setSearchFields(['name', 'slug', 'teaser'])
            ->setHelp(Crud::PAGE_EDIT, 'Pour l\'image, entrez une URL (https://…) ou le nom d\'un fichier déposé dans <code>public/images/categories/</code>.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');
        yield TextField::new('slug', 'Slug URL')->setHelp('/experience/<strong>slug</strong>');
        yield TextField::new('teaser', 'Accroche courte (max 120 car.)')->hideOnIndex();
        yield TextareaField::new('description', 'Description complète')->hideOnIndex()->setNumOfRows(6);
        yield NumberField::new('basePrice', 'Prix de base (TND)')->setNumDecimals(2)->setStoredAsString(true);
        yield BooleanField::new('archived', 'Archivée')->renderAsSwitch(true);

        yield TextField::new('heroImage', 'Image principale')
            ->setHelp('URL (https://…) ou nom de fichier dans public/images/categories/')
            ->hideOnIndex();

        yield TextField::new('panelImage', 'Image panneau accueil')
            ->setHelp('Idem — laisser vide pour utiliser la même image que l\'image principale')
            ->hideOnIndex();

        if ($pageName !== Crud::PAGE_INDEX) {
            yield CollectionField::new('extras', 'Options / Extras')
                ->setEntryType(ExtraEmbedType::class)
                ->allowAdd()->allowDelete()
                ->setFormTypeOptions(['by_reference' => false, 'entry_options' => ['label' => false]]);

            yield CollectionField::new('highlights', 'Points forts')
                ->setEntryType(HighlightEmbedType::class)
                ->allowAdd()->allowDelete()
                ->setFormTypeOptions(['by_reference' => false, 'entry_options' => ['label' => false]]);

            yield CollectionField::new('includedItems', 'Ce qui est inclus dans le prix')
                ->setEntryType(IncludedItemEmbedType::class)
                ->allowAdd()->allowDelete()
                ->setFormTypeOptions(['by_reference' => false, 'entry_options' => ['label' => false]]);

            yield CollectionField::new('bringItems', 'Ce que les participants doivent apporter')
                ->setEntryType(BringItemEmbedType::class)
                ->allowAdd()->allowDelete()
                ->setFormTypeOptions(['by_reference' => false, 'entry_options' => ['label' => false]]);
        }
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(BooleanFilter::new('archived', 'Archivée'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $archive = Action::new('toggleArchive', 'Archiver / Restaurer', 'fas fa-archive')
            ->linkToCrudAction('toggleArchive')
            ->setCssClass('btn btn-sm');

        return $actions
            ->add(Crud::PAGE_INDEX, $archive)
            ->add(Crud::PAGE_DETAIL, $archive)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function toggleArchive(AdminContext $context): RedirectResponse
    {
        /** @var Category $category */
        $category = $context->getEntity()->getInstance();
        $category->setArchived(!$category->isArchived());
        $this->container->get('doctrine')->getManager()->flush();

        $this->addFlash('success', $category->isArchived() ? 'Expérience archivée.' : 'Expérience restaurée.');

        return $this->redirect(
            $this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl()
        );
    }
}
