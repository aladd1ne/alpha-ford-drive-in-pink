<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Reservation;
use App\Enum\Vehicle;
use App\Service\Reservation\SlotSchedule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Back-office edit of a reservation. The contact fields are mapped to the entity; date,
 * vehicle and slot are not, because ReservationManager::update() has to check them (and
 * claim a seat) before they change.
 *
 * A slot booking picks its date among the experience's event days and a slot; an
 * "autres jours d'octobre" request (or a walk-in) gets any date.
 */
final class ReservationEditType extends AbstractType
{
    public function __construct(
        private readonly SlotSchedule $schedule,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Reservation $reservation */
        $reservation = $options['reservation'];
        $experience = $reservation->getExperience();

        $builder
            ->add('fullName', TextType::class, ['label' => 'Nom et prénom'])
            ->add('phone', TelType::class, ['label' => 'Téléphone'])
            ->add('email', EmailType::class, ['label' => 'E-mail']);

        if ($reservation->isSlotBooking()) {
            $dates = [];
            foreach ($this->schedule->dates($experience) as $date) {
                $dates[$date->format('d/m/Y')] = $date->format('Y-m-d');
            }
            $builder
                ->add('date', ChoiceType::class, [
                    'label' => 'Date',
                    'mapped' => false,
                    'choices' => $dates,
                    'data' => $reservation->getDate()?->format('Y-m-d'),
                ])
                ->add('slot', ChoiceType::class, [
                    'label' => 'Créneau',
                    'mapped' => false,
                    'choices' => array_flip($this->schedule->allSlots()),
                    'data' => $reservation->getSlot(),
                    'help' => 'Vendredi jusqu’à 16h15, samedi jusqu’à 14h00.',
                ]);
        } else {
            $builder->add('date', DateType::class, [
                'label' => 'Date du test drive',
                'mapped' => false,
                'widget' => 'single_text',
                'input' => 'string',
                'input_format' => 'Y-m-d',
                'required' => true,
                'data' => $reservation->getDate()?->format('Y-m-d'),
            ]);
        }

        $builder->add('vehicle', ChoiceType::class, [
            'label' => 'Véhicule',
            'mapped' => false,
            'choices' => $experience->vehicles(),
            'choice_value' => static fn (?Vehicle $vehicle): ?string => $vehicle?->value,
            'choice_label' => static fn (Vehicle $vehicle): string => $vehicle->label(),
            'data' => $reservation->getVehicle(),
            'placeholder' => $reservation->isSlotBooking() ? false : 'À définir',
            'required' => $reservation->isSlotBooking(),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => Reservation::class, 'csrf_token_id' => 'reservation-edit'])
            ->setRequired('reservation')
            ->setAllowedTypes('reservation', Reservation::class);
    }
}
