<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Business rules of a Drive in Pink reservation (date, vehicle, slot availability).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidReservation extends Constraint
{
    public string $vehicleRequiredMessage = 'Veuillez choisir un véhicule.';
    public string $vehicleMessage = 'Ce véhicule n’est pas proposé pour cette expérience.';
    public string $dateMessage = 'Cette date n’est pas proposée pour cette expérience.';
    public string $pastDateMessage = 'Veuillez choisir une date à partir d’aujourd’hui.';
    public string $requestDateMessage = 'Choisissez un jour de semaine d’octobre (hors week-ends et 15 octobre), en dehors des journées Everest & Ranger (9-10) et Territory (23-24), qui ont leur propre formulaire.';
    public string $slotRequiredMessage = 'Veuillez choisir un créneau.';
    public string $slotMessage = 'Ce créneau n’est pas proposé ce jour-là.';
    public string $slotStartedMessage = 'Ce créneau n’est plus disponible.';
    public string $slotFullMessage = 'Ce créneau est complet pour ce véhicule. Merci d’en choisir un autre.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
