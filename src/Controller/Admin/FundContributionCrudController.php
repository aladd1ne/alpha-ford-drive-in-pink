<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\FundContribution;
use App\Form\Admin\FundContributionType;
use App\Repository\FundContributionRepository;
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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Cagnotte history: every credit (validated test drives and amounts added by hand
 * earlier). Only admins can correct the amount of one in case of a mistake ("Corriger",
 * with an optional reason, who did it and when kept on it) or delete one, and only a manual one (test drive
 * credits are tied to their validation).
 *
 * @extends AbstractCrudController<FundContribution>
 */
#[IsGranted('ROLE_CASHIER')]
#[AdminRoute(path: '/cagnotte', name: 'fund_contribution')]
class FundContributionCrudController extends AbstractCrudController
{
    use RedirectsToBackOfficeList;

    public const CORRECT_ACTION = 'correctContribution';
    public const CORRECT_ROUTE = 'admin_fund_contribution_correct';
    public const REASON_MAX_LENGTH = 255;

    public function __construct(
        private readonly FundContributionRepository $contributions,
        private readonly EntityManagerInterface $entityManager,
        private readonly SolidarityFund $fund,
        private readonly ClockInterface $clock,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly UrlGeneratorInterface $urlGenerator,
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
            ->setSearchFields(['note', 'validatedBy', 'correctionReason', 'correctedBy'])
            ->setPaginatorPageSize(100)
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $urlGenerator = $this->urlGenerator;

        return $actions
            ->add(Crud::PAGE_INDEX, Action::new(self::CORRECT_ACTION, 'Corriger', 'fas fa-pen')
                ->linkToUrl(static fn (FundContribution $contribution): string => $urlGenerator->generate(self::CORRECT_ROUTE, ['entityId' => $contribution->getId()]))
                ->setTemplatePath('admin/action/correct_contribution.html.twig'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $action): Action => $action
                ->displayIf(static fn (FundContribution $contribution): bool => $contribution->isManual()))
            ->setPermission(self::CORRECT_ACTION, 'ROLE_ADMIN')
            ->setPermission(Action::DELETE, 'ROLE_ADMIN')
            ->disable(Action::NEW, Action::EDIT, Action::DETAIL, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', 'Date')->setFormat('d MMM yyyy HH:mm');
        yield IntegerField::new('amount', 'Montant (DT)');
        yield IntegerField::new('clientAmount', 'Reçu du client (DT)');
        yield IntegerField::new('alphaFordAmount', 'Alpha Ford (DT)');
        yield TextField::new('note', 'Origine')
            ->formatValue(static fn ($value, FundContribution $contribution): string => $contribution->origin());
        yield TextField::new('validatedBy', 'Ajouté par');
        yield TextField::new('correctedBy', 'Modifié par')
            ->formatValue(static fn ($value, FundContribution $contribution): string => $contribution->isCorrected()
                ? sprintf('%s (%s)', $contribution->getCorrectedBy(), $contribution->getCorrectedAt()?->format('d/m/Y H:i'))
                : '');
        yield TextField::new('correctionReason', 'Raison de la correction');
    }

    /**
     * Admin correction of a credit: for a test drive, the amount received from the client
     * and the amount given by Alpha Ford (the total is their sum); for a manual amount,
     * the amount itself.
     */
    #[IsGranted('ROLE_ADMIN')]
    #[AdminRoute(path: '/{entityId}/correct', name: 'correct', options: ['methods' => ['POST'], 'requirements' => ['entityId' => '\d+']])]
    public function correctContribution(Request $request): RedirectResponse
    {
        $id = (int) $request->attributes->get('entityId', $request->query->get('entityId'));
        $return = $this->returnUrl($request, $this->indexUrl());

        $contribution = $this->contributions->find($id);
        if (!$contribution instanceof FundContribution) {
            throw $this->createNotFoundException('Montant introuvable.');
        }

        if (!$this->isCsrfTokenValid('correct-contribution-' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide, veuillez réessayer.');

            return $this->redirect($return);
        }

        if ($contribution->isManual()) {
            $amount = self::amountOf((string) $request->request->get('amount'), 1, FundContributionType::MAX_AMOUNT);
            if (null === $amount) {
                $this->addFlash('danger', sprintf('Saisissez un montant entre 1 et %d DT.', FundContributionType::MAX_AMOUNT));

                return $this->redirect($return);
            }
        } else {
            $clientAmount = self::amountOf((string) $request->request->get('client_amount'), 0, TestDriveCrudController::MAX_RECEIVED_AMOUNT);
            $alphaFordAmount = self::amountOf((string) $request->request->get('alpha_ford_amount'), 0, TestDriveCrudController::MAX_RECEIVED_AMOUNT);
            if (null === $clientAmount || null === $alphaFordAmount) {
                $this->addFlash('danger', sprintf('Saisissez le montant reçu du client et celui d’Alpha Ford, entre 0 et %d DT chacun.', TestDriveCrudController::MAX_RECEIVED_AMOUNT));

                return $this->redirect($return);
            }
        }

        $reason = trim((string) $request->request->get('reason'));
        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            $this->addFlash('danger', sprintf('La raison de la correction est limitée à %d caractères.', self::REASON_MAX_LENGTH));

            return $this->redirect($return);
        }

        $before = $contribution->getAmount();
        $by = $this->getUser()?->getUserIdentifier() ?? 'inconnu';
        if ($contribution->isManual()) {
            $contribution->correctManual($amount, $reason, $this->clock->now(), $by);
        } else {
            $contribution->correctTestDrive($clientAmount, $alphaFordAmount, $reason, $this->clock->now(), $by);
        }
        $this->entityManager->flush();

        $difference = $contribution->getAmount() - $before;
        // Shown as a toast with the new total (templates/admin/flash_messages.html.twig).
        $this->addFlash('cagnotte', [
            'amount' => $difference,
            'badge' => sprintf('%s%s DT', $difference >= 0 ? '+' : '−', number_format(abs($difference), 0, ',', ' ')),
            'title' => sprintf('Montant corrigé : %d DT → %d DT', $before, $contribution->getAmount()),
            'total' => $this->fund->total(),
        ]);

        return $this->redirect($return);
    }

    public function delete(AdminContext $context)
    {
        $contribution = $context->getEntity()->getInstance();
        if (!$contribution instanceof FundContribution || !$contribution->isManual()) {
            throw $this->createAccessDeniedException('Seuls les montants ajoutés à la main peuvent être supprimés.');
        }

        return parent::delete($context);
    }

    /**
     * The submitted amount in DT, or null when it is not a whole number between $min and $max.
     */
    private static function amountOf(string $amount, int $min, int $max): ?int
    {
        $amount = trim($amount);
        if (!ctype_digit($amount) || (int) $amount < $min || (int) $amount > $max) {
            return null;
        }

        return (int) $amount;
    }

    private function indexUrl(): string
    {
        return $this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->unset('entityId')->generateUrl();
    }
}
