<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Form\BookingType;
use App\Repository\BookingRepository;
use App\Repository\CategoryRepository;
use App\Repository\ExtraRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BookingController extends AbstractController
{
    public function __construct(
        private readonly int $depositAmount,
    ) {}

    #[Route('/book/{slug}', name: 'booking_form', methods: ['GET', 'POST'])]
    public function form(
        string $slug,
        Request $request,
        CategoryRepository $categoryRepository,
        ExtraRepository $extraRepository,
        EntityManagerInterface $em,
    ): Response {
        $category = $categoryRepository->findOneBySlug($slug);

        if (!$category) {
            throw $this->createNotFoundException('Expérience introuvable.');
        }

        $booking = new Booking();
        $booking->setCategory($category);
        $booking->setDepositAmount((string) $this->depositAmount);

        $form = $this->createForm(BookingType::class, $booking);
        $form->handleRequest($request);

        // Collect selected extra IDs from GET (pre-selection from detail page) or POST
        $selectedExtraIds = $request->request->all()['extra_ids'] ?? $request->query->all()['extras'] ?? [];
        $selectedExtraIds = array_map('intval', (array) $selectedExtraIds);

        $availableExtras = $category->getExtras()->toArray();

        if ($form->isSubmitted() && $form->isValid()) {
            // Build selectedExtras JSON from submitted extra IDs
            $selectedExtrasData = [];
            $extrasTotal = '0';

            foreach ($availableExtras as $extra) {
                if (in_array($extra->getId(), $selectedExtraIds, true)) {
                    $selectedExtrasData[] = [
                        'id' => $extra->getId(),
                        'name' => $extra->getName(),
                        'price' => $extra->getPrice(),
                    ];
                    $extrasTotal = bcadd($extrasTotal, $extra->getPrice(), 2);
                }
            }

            $totalPrice = bcadd($category->getBasePrice(), $extrasTotal, 2);
            $booking->setSelectedExtras($selectedExtrasData);
            $booking->setTotalPrice($totalPrice);
            $booking->setStatus('pending');

            $em->persist($booking);
            $em->flush();

            return $this->redirectToRoute('booking_confirmation', ['id' => $booking->getId()]);
        }

        return $this->render('booking/form.html.twig', [
            'category' => $category,
            'form' => $form,
            'availableExtras' => $availableExtras,
            'selectedExtraIds' => $selectedExtraIds,
        ]);
    }

    #[Route('/booking/confirmation/{id}', name: 'booking_confirmation')]
    public function confirmation(int $id, BookingRepository $bookingRepository): Response
    {
        $booking = $bookingRepository->find($id);

        if (!$booking) {
            throw $this->createNotFoundException('Réservation introuvable.');
        }

        return $this->render('booking/confirmation.html.twig', [
            'booking' => $booking,
        ]);
    }
}
