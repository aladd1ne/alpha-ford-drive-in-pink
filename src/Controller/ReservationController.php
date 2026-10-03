<?php

namespace App\Controller;

use App\Entity\Reservation;
use App\Enum\Experience;
use App\Form\ReservationType;
use App\Service\Reservation\ReservationBooker;
use App\Service\Reservation\SlotAvailability;
use App\Service\Reservation\SlotUnavailableException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;

#[Route('/reservation')]
class ReservationController extends AbstractController
{
    private const SUCCESS_FLASH = 'reservation_success';

    public function __construct(
        private readonly SlotAvailability $availability,
    ) {}

    #[Route('', name: 'reservation', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('reservation/index.html.twig', [
            'full' => $this->fullExperiences(),
        ]);
    }

    #[Route('/{experience}', name: 'reservation_form', requirements: ['experience' => new EnumRequirement(Experience::class)], methods: ['GET', 'POST'])]
    public function form(Experience $experience, Request $request, ReservationBooker $booker, ClockInterface $clock): Response
    {
        $full = $this->availability->isFull($experience);
        $reservation = new Reservation($experience, $clock->now());
        $form = $this->createForm(ReservationType::class, $reservation, ['experience' => $experience]);

        if (!$full) {
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                try {
                    $booker->book($reservation);
                    $this->addFlash(self::SUCCESS_FLASH, $experience->value);

                    return $this->redirectToRoute('reservation_confirmation', ['experience' => $experience->value]);
                } catch (SlotUnavailableException $e) {
                    $form->get('slot')->addError(new FormError($e->getMessage()));
                }
            }
        }

        return $this->render('reservation/form.html.twig', [
            'experience' => $experience,
            'form' => $form,
            'full' => $full,
            'availability' => $this->availability->remainingMap($experience),
        ]);
    }

    #[Route('/{experience}/confirmation', name: 'reservation_confirmation', requirements: ['experience' => new EnumRequirement(Experience::class)], methods: ['GET'])]
    public function confirmation(Experience $experience, Request $request): Response
    {
        // Only reachable right after a successful submission.
        $session = $request->getSession();
        $flashes = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get(self::SUCCESS_FLASH) : [];
        if (!\in_array($experience->value, $flashes, true)) {
            return $this->redirectToRoute('reservation_form', ['experience' => $experience->value]);
        }

        return $this->render('reservation/confirmation.html.twig', [
            'experience' => $experience,
        ]);
    }

    /**
     * @return array<string, bool> experience slug => no slot left
     */
    private function fullExperiences(): array
    {
        $full = [];
        foreach (Experience::cases() as $experience) {
            $full[$experience->value] = $this->availability->isFull($experience);
        }

        return $full;
    }
}
