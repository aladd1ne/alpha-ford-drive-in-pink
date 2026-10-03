<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\Vehicle;
use App\Service\Reservation\SlotAvailability;
use App\Service\Reservation\SlotSchedule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One form for the three journeys; fields depend on the "experience" option.
 */
final class ReservationType extends AbstractType
{
    public function __construct(
        private readonly SlotSchedule $schedule,
        private readonly SlotAvailability $availability,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Experience $experience */
        $experience = $options['experience'];

        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Nom et prénom',
                'attr' => ['autocomplete' => 'name', 'minlength' => 3, 'maxlength' => 120, 'placeholder' => 'Votre nom et prénom'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Numéro de téléphone',
                'attr' => [
                    'autocomplete' => 'tel',
                    'inputmode' => 'tel',
                    'placeholder' => '+216 XX XXX XXX',
                    'pattern' => '\+?([\s.\-]?\d){8,15}',
                    'title' => '8 à 15 chiffres, éventuellement précédés de +',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'attr' => ['autocomplete' => 'email', 'maxlength' => 180, 'placeholder' => 'votre@email.com'],
            ]);

        if ($experience->hasSlots()) {
            $builder->add('date', ChoiceType::class, [
                'label' => 'Date',
                'choices' => $this->schedule->dates($experience),
                'choice_value' => static fn (?\DateTimeInterface $date): ?string => $date?->format('Y-m-d'),
                'choice_label' => fn (\DateTimeInterface $date): string => $this->formatDay($date),
                'expanded' => true,
            ]);

            if ($experience->hasVehicleChoice()) {
                $this->addVehicle($builder, $experience, 'Véhicule');
            }

            $builder->add('slot', ChoiceType::class, [
                'label' => 'Créneau horaire',
                'choices' => array_flip($this->schedule->allSlots()),
                'expanded' => true,
            ]);

            return;
        }

        [$first, $last] = $this->schedule->requestPeriod();
        $min = max($first, $this->availability->today());

        $builder->add('date', DateType::class, [
            'label' => 'Date souhaitée',
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'attr' => ['min' => $min->format('Y-m-d'), 'max' => $last->format('Y-m-d')],
        ]);
        $this->addVehicle($builder, $experience, 'Véhicule souhaité');
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Reservation::class]);
        $resolver->setRequired('experience');
        $resolver->setAllowedTypes('experience', Experience::class);
    }

    private function addVehicle(FormBuilderInterface $builder, Experience $experience, string $label): void
    {
        $builder->add('vehicle', EnumType::class, [
            'label' => $label,
            'class' => Vehicle::class,
            'choices' => $experience->vehicles(),
            'choice_label' => static fn (Vehicle $vehicle): string => $vehicle->label(),
            'expanded' => true,
        ]);
    }

    private function formatDay(\DateTimeInterface $date): string
    {
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, $this->schedule->timezone(), null, 'EEEE d MMMM');

        return ucfirst((string) $formatter->format($date));
    }
}
