<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Product;
use App\Entity\Reservation;
use App\Entity\UnregisteredDonor;
use App\Enum\ContributionSource;
use App\Repository\ProductRepository;
use App\Repository\ReservationRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Back-office, every profile: an amount added to the cagnotte outside any test drive.
 * Both a donation and a product sale name the person (donor or buyer) by the e-mail of
 * their registration on the site (picked from a searchable list, see
 * templates/admin/fund/add.html.twig), or, when "newDonor" is checked, by the name, phone and
 * optional e-mail of someone not registered (see donor()). A donation gives the amount; a product sale picks a
 * product (see ProductCrudController) and a quantity, the amount being its price times the quantity. Only the fields of the chosen source
 * and of the chosen kind of person are validated.
 */
final class FundContributionType extends AbstractType
{
    public const MAX_AMOUNT = 100000;
    public const MAX_QUANTITY = 1000;
    public const NOTE_MAX_LENGTH = 255;

    /** Validation groups of the person: picked among the registrations, or typed by hand. */
    private const REGISTERED_DONOR = 'registered_donor';
    private const NEW_DONOR = 'new_donor';

    public function __construct(
        private readonly ReservationRepository $reservations,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('source', EnumType::class, [
                'class' => ContributionSource::class,
                'label' => 'Origine',
                'expanded' => true,
                'choice_label' => static fn (ContributionSource $source): string => $source->label(),
                'constraints' => [new Assert\NotNull(message: 'Choisissez l’origine du montant.')],
            ])
            ->add('donor', HiddenType::class, [
                'required' => false,
                'error_bubbling' => false,
                'invalid_message' => 'Aucune inscription avec cette adresse e-mail.',
                'attr' => ['data-donor-value' => ''],
                'constraints' => [new Assert\NotNull(message: 'Choisissez la personne parmi les inscrits.', groups: [self::REGISTERED_DONOR])],
            ])
            ->add('newDonor', CheckboxType::class, [
                'label' => 'Personne non inscrite sur le site',
                'required' => false,
                'attr' => ['data-new-donor-toggle' => ''],
            ])
            ->add('donorName', TextType::class, [
                'label' => 'Nom et prénom',
                'required' => false,
                'attr' => ['maxlength' => 120],
                'constraints' => [
                    new Assert\NotBlank(message: 'Veuillez saisir le nom et prénom.', groups: [self::NEW_DONOR]),
                    new Assert\Length(min: 3, max: 120, minMessage: 'Le nom doit contenir au moins {{ limit }} caractères.', maxMessage: 'Le nom ne doit pas dépasser {{ limit }} caractères.', groups: [self::NEW_DONOR]),
                ],
            ])
            ->add('donorPhone', TelType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'attr' => ['placeholder' => '+216 XX XXX XXX'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Veuillez saisir le numéro de téléphone.', groups: [self::NEW_DONOR]),
                    new Assert\Regex(pattern: Reservation::PHONE_PATTERN, message: 'Numéro de téléphone invalide.', groups: [self::NEW_DONOR]),
                ],
            ])
            ->add('donorEmail', EmailType::class, [
                'label' => 'E-mail',
                'required' => false,
                'help' => 'Facultatif.',
                'constraints' => [
                    new Assert\Length(max: 180, groups: [self::NEW_DONOR]),
                    new Assert\Email(message: 'Adresse e-mail invalide.', groups: [self::NEW_DONOR]),
                ],
            ])
            ->add('amount', IntegerType::class, [
                'label' => 'Montant (DT)',
                'required' => false,
                'attr' => ['min' => 1, 'max' => self::MAX_AMOUNT, 'step' => 1, 'data-fund-source' => ContributionSource::Donation->value],
                'constraints' => [
                    new Assert\NotBlank(message: 'Veuillez saisir un montant.', groups: [ContributionSource::Donation->value]),
                    new Assert\Range(min: 1, max: self::MAX_AMOUNT, notInRangeMessage: 'Le montant doit être compris entre {{ min }} et {{ max }} DT.', groups: [ContributionSource::Donation->value]),
                ],
            ])
            ->add('product', EntityType::class, [
                'class' => Product::class,
                'label' => 'Produit',
                'required' => false,
                'placeholder' => 'Choisissez un produit',
                'query_builder' => static fn (ProductRepository $products) => $products->createActiveQueryBuilder(),
                'choice_label' => static fn (Product $product): string => sprintf('%s — %d DT', $product->getName(), $product->getPrice()),
                'choice_attr' => static fn (Product $product): array => ['data-price' => $product->getPrice()],
                'attr' => ['data-fund-source' => ContributionSource::Product->value],
                'constraints' => [new Assert\NotNull(message: 'Choisissez le produit vendu.', groups: [ContributionSource::Product->value])],
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'Quantité',
                'required' => false,
                'data' => 1,
                'attr' => ['min' => 1, 'max' => self::MAX_QUANTITY, 'step' => 1, 'data-fund-source' => ContributionSource::Product->value],
                'constraints' => [
                    new Assert\NotBlank(message: 'Veuillez saisir la quantité.', groups: [ContributionSource::Product->value]),
                    new Assert\Range(min: 1, max: self::MAX_QUANTITY, notInRangeMessage: 'La quantité doit être comprise entre {{ min }} et {{ max }}.', groups: [ContributionSource::Product->value]),
                ],
            ])
            ->add('note', TextType::class, [
                'label' => 'Détail',
                'required' => false,
                'help' => 'Facultatif.',
                'attr' => ['maxlength' => self::NOTE_MAX_LENGTH],
                'constraints' => [new Assert\Length(max: self::NOTE_MAX_LENGTH)],
            ]);

        // The donor is typed as the e-mail of a registration and read back as that registration.
        $builder->get('donor')->addModelTransformer(new CallbackTransformer(
            static fn (?Reservation $donor): string => (string) $donor?->getEmail(),
            function (?string $email): ?Reservation {
                if (null === $email || '' === trim($email)) {
                    return null;
                }

                return $this->reservations->findLatestByEmail($email)
                    ?? throw new TransformationFailedException(sprintf('No registration with e-mail "%s".', $email));
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_token_id' => 'fund-contribution',
            // Default constraints, plus the ones of the chosen source and kind of person.
            'validation_groups' => static function (FormInterface $form): array {
                $source = $form->get('source')->getData();
                $groups = ['Default', $form->get('newDonor')->getData() ? self::NEW_DONOR : self::REGISTERED_DONOR];

                return $source instanceof ContributionSource ? [...$groups, $source->value] : $groups;
            },
        ]);
    }

    /**
     * The donor or buyer of the submitted form: the registration picked, or the person typed by hand.
     *
     * @param array{donor?: ?Reservation, newDonor?: ?bool, donorName?: ?string, donorPhone?: ?string, donorEmail?: ?string} $data submitted form data
     */
    public static function donor(array $data): Reservation|UnregisteredDonor
    {
        if ($data['newDonor'] ?? false) {
            return new UnregisteredDonor($data['donorName'] ?? null, $data['donorPhone'] ?? null, $data['donorEmail'] ?? null);
        }

        return $data['donor'] ?? throw new \LogicException('A registered donor is validated by the form.');
    }
}
