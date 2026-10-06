<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Admins manage the staff accounts: reservations management (ROLE_RESERVATIONS) and/or
 * cashier (ROLE_CASHIER). Only staff accounts can be listed, edited or deleted: admin
 * accounts stay out of reach of this page.
 *
 * @extends AbstractCrudController<AdminUser>
 */
#[IsGranted(AdminUser::ROLE_ADMIN)]
#[AdminRoute(path: '/commerciaux', name: 'commercial_user')]
class CommercialUserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return AdminUser::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Membre de l’équipe')
            ->setEntityLabelInPlural('Équipe')
            ->setPageTitle(Crud::PAGE_NEW, 'Ajouter un membre de l’équipe')
            ->setHelp(Crud::PAGE_INDEX, 'Gestion des réservations : consulter, valider, modifier et archiver les réservations, et planifier les demandes des autres jours d’octobre. Encaissement : encaisser les test drives des journées événement et ajouter des montants à la cagnotte. Aucun des deux n’a accès à la gestion des comptes.')
            ->setDefaultSort(['email' => 'ASC'])
            ->setSearchFields(['email'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DETAIL, Action::BATCH_DELETE)
            ->update(Crud::PAGE_INDEX, Action::NEW, static fn (Action $action): Action => $action->setLabel('Ajouter un membre'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email', 'E-mail');
        yield ChoiceField::new('staffRoles', 'Rôles')
            ->setChoices(array_flip(AdminUser::STAFF_ROLES))
            ->allowMultipleChoices()
            ->renderExpanded()
            ->renderAsBadges()
            ->setFormTypeOption('constraints', [new Assert\Count(min: 1, minMessage: 'Choisissez au moins un rôle.')])
            ->setHelp('Un membre peut avoir les deux rôles.');

        $passwordConstraints = [new Assert\Length(min: AdminUser::MIN_PASSWORD_LENGTH, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.')];
        if (Crud::PAGE_NEW === $pageName) {
            array_unshift($passwordConstraints, new Assert\NotBlank(message: 'Veuillez saisir un mot de passe.'));
        }

        yield TextField::new('plainPassword', 'Mot de passe')
            ->onlyOnForms()
            ->setFormType(RepeatedType::class)
            ->setFormTypeOptions([
                'type' => PasswordType::class,
                'first_options' => ['label' => 'Mot de passe', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirmer le mot de passe', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'Les mots de passe ne correspondent pas.',
                'required' => Crud::PAGE_NEW === $pageName,
                'constraints' => $passwordConstraints,
            ])
            ->setHelp(Crud::PAGE_EDIT === $pageName ? 'Laisser vide pour conserver le mot de passe actuel.' : sprintf('%d caractères minimum.', AdminUser::MIN_PASSWORD_LENGTH));

        yield DateTimeField::new('createdAt', 'Créé le')->setFormat('d MMM yyyy HH:mm')->hideOnForm();
    }

    public function createEntity(string $entityFqcn): AdminUser
    {
        return (new AdminUser())->setRoles([AdminUser::ROLE_CASHIER]);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        // Roles are stored as JSON text: match the encoded role names.
        return $qb
            ->andWhere(sprintf('(%1$s.roles LIKE :reservations OR %1$s.roles LIKE :cashier OR %1$s.roles LIKE :commercial) AND %1$s.roles NOT LIKE :admin', $qb->getRootAliases()[0]))
            ->setParameter('reservations', '%"' . AdminUser::ROLE_RESERVATIONS . '"%')
            ->setParameter('cashier', '%"' . AdminUser::ROLE_CASHIER . '"%')
            ->setParameter('commercial', '%"' . AdminUser::ROLE_COMMERCIAL . '"%')
            ->setParameter('admin', '%"' . AdminUser::ROLE_ADMIN . '"%');
    }

    public function edit(AdminContext $context)
    {
        $this->denyUnlessCommercial($context);

        return parent::edit($context);
    }

    public function delete(AdminContext $context)
    {
        $this->denyUnlessCommercial($context);

        return parent::delete($context);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function hashPassword(AdminUser $user): void
    {
        $plain = $user->getPlainPassword();
        if (null !== $plain && '' !== $plain) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $plain));
        }
        $user->eraseCredentials();
    }

    /**
     * This page only manages commercial accounts, whatever id is put in the URL.
     *
     * @param AdminContext<AdminUser> $context
     */
    private function denyUnlessCommercial(AdminContext $context): void
    {
        $user = $context->getEntity()->getInstance();
        if (!$user instanceof AdminUser || !$user->isCommercialOnly()) {
            throw $this->createAccessDeniedException('Seuls les comptes de l’équipe peuvent être gérés ici.');
        }
    }
}
