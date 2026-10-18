<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\FundContribution;
use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\Vehicle;
use App\Service\Reservation\SlotSchedule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Back-office: a test drive added by hand (walk-in), on any date from the first day of the
 * campaign month, October (today by default). The controller builds the
 * Reservation from this data and validates it with the entity's own constraints.
 */
final class ManualTestDriveType extends AbstractType
{
    /** Fields named like Reservation properties: the entity's violations are shown on them. */
    public const ENTITY_FIELDS = ['fullName', 'phone', 'email', 'vehicle'];

    public function __construct(
        private readonly SlotSchedule $schedule,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $first = $this->schedule->requestPeriod()[0]->format('Y-m-d');

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
            ->add('date', DateType::class, [
                'label' => 'Date du test drive',
                'widget' => 'single_text',
                // A plain Y-m-d day: the controller reads it in the schedule's time zone.
                'input' => 'string',
                'input_format' => 'Y-m-d',
                'attr' => ['min' => $first],
                'constraints' => [
                    new Assert\NotNull(message: 'Veuillez choisir une date.'),
                    // Y-m-d strings compare like dates.
                    new Assert\GreaterThanOrEqual(value: $first, message: sprintf('La date doit être à partir du %s.', (new \DateTimeImmutable($first))->format('d/m/Y'))),
                ],
            ])
            ->add('vehicle', EnumType::class, [
                'class' => Vehicle::class,
                'label' => 'Véhicule',
                'choice_label' => static fn (Vehicle $vehicle): string => $vehicle->label(),
            ])
            ->add('completed', CheckboxType::class, [
                'label' => 'Test drive déjà effectué et encaissé',
                'help' => sprintf('Coché : le test drive est validé tout de suite et la cagnotte reçoit %d DT (%d DT du client + %d DT d’Alpha Ford).', FundContribution::TEST_DRIVE_AMOUNT, FundContribution::CLIENT_SHARE, FundContribution::ALPHA_FORD_SHARE),
                'required' => false,
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
