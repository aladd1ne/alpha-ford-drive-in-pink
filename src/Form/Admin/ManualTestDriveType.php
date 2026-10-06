<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Controller\Admin\TestDriveCrudController;
use App\Entity\FundContribution;
use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\Vehicle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Back-office: a test drive added by hand for today (walk-in). The controller builds the
 * Reservation from this data and validates it with the entity's own constraints.
 */
final class ManualTestDriveType extends AbstractType
{
    /** Fields named like Reservation properties: the entity's violations are shown on them. */
    public const ENTITY_FIELDS = ['fullName', 'phone', 'email', 'vehicle'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, ['label' => 'Nom et prénom'])
            ->add('phone', TelType::class, ['label' => 'Téléphone', 'attr' => ['placeholder' => '+216 XX XXX XXX']])
            ->add('email', EmailType::class, ['label' => 'E-mail'])
            ->add('experience', EnumType::class, [
                'class' => Experience::class,
                'label' => 'Expérience',
                'constraints' => [new Assert\NotNull(message: 'Veuillez choisir une expérience.')],
                'choice_label' => static fn (Experience $experience): string => $experience->label(),
            ])
            ->add('vehicle', EnumType::class, [
                'class' => Vehicle::class,
                'label' => 'Véhicule',
                'choice_label' => static fn (Vehicle $vehicle): string => $vehicle->label(),
            ])
            ->add('completed', CheckboxType::class, [
                'label' => 'Test drive déjà effectué et encaissé',
                'help' => sprintf('Coché : le test drive est validé tout de suite et la cagnotte reçoit le montant ci-dessous + %d DT d’Alpha Ford.', FundContribution::ALPHA_FORD_SHARE),
                'required' => false,
            ])
            ->add('amount', IntegerType::class, [
                'label' => 'Montant reçu (DT)',
                'attr' => ['min' => 0, 'max' => TestDriveCrudController::MAX_RECEIVED_AMOUNT, 'step' => 1],
                'constraints' => [
                    new Assert\NotNull(message: 'Saisissez le montant reçu.'),
                    new Assert\Range(min: 0, max: TestDriveCrudController::MAX_RECEIVED_AMOUNT, notInRangeMessage: 'Le montant doit être compris entre {{ min }} et {{ max }} DT.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_token_id' => 'manual-test-drive',
        ]);
    }

    /**
     * @param array{fullName?: ?string, phone?: ?string, email?: ?string, vehicle?: ?Vehicle} $data submitted form data
     */
    public static function apply(Reservation $reservation, array $data): Reservation
    {
        return $reservation
            ->setFullName($data['fullName'] ?? null)
            ->setPhone($data['phone'] ?? null)
            ->setEmail($data['email'] ?? null)
            ->setVehicle($data['vehicle'] ?? null);
    }
}
