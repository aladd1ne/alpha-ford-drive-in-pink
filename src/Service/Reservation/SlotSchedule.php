<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Enum\Experience;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Event calendar built from config/packages/reservation.yaml: which days each
 * experience runs, which slots exist on each day and how many bookings a slot accepts.
 * Dates are compared as "Y-m-d" strings so timezones never shift a day.
 */
final class SlotSchedule
{
    private readonly \DateTimeZone $timezone;

    /**
     * @param list<string>                                                      $slots       "HH:MM-HH:MM"
     * @param array<string, array{capacity?: int, opens_at?: string, dates: array<string, string>}> $experiences slug => config
     */
    public function __construct(
        #[Autowire(param: 'app.reservation.slots')]
        private readonly array $slots,
        #[Autowire(param: 'app.reservation.experiences')]
        private readonly array $experiences,
        #[Autowire(param: 'app.reservation.request_month')]
        private readonly string $requestMonth,
        /** @var list<string> "Y-m-d" days refused by the request form */
        #[Autowire(param: 'app.reservation.request_closed_dates')]
        private readonly array $requestClosedDates,
        #[Autowire(param: 'app.reservation.timezone')]
        string $timezone = 'Africa/Tunis',
    ) {
        $this->timezone = new \DateTimeZone($timezone);
    }

    public function timezone(): \DateTimeZone
    {
        return $this->timezone;
    }

    public function capacity(Experience $experience): int
    {
        if (!$experience->hasSlots()) {
            return 0;
        }

        return max(0, (int) ($this->experiences[$experience->value]['capacity'] ?? 1));
    }

    /**
     * First day bookings are accepted (midnight, event timezone), null when always open.
     */
    public function opensAt(Experience $experience): ?\DateTimeImmutable
    {
        $day = $this->experiences[$experience->value]['opens_at'] ?? null;

        return null === $day ? null : $this->day($day);
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    public function dates(Experience $experience): array
    {
        $days = array_keys($this->experiences[$experience->value]['dates'] ?? []);
        sort($days);

        return array_map(fn (string $day): \DateTimeImmutable => $this->day($day), $days);
    }

    public function hasDate(Experience $experience, \DateTimeInterface $date): bool
    {
        return isset($this->experiences[$experience->value]['dates'][$date->format('Y-m-d')]);
    }

    /**
     * All configured slots: start ("09:00") => label ("09h00 – 09h30").
     *
     * @return array<string, string>
     */
    public function allSlots(): array
    {
        $slots = [];
        foreach ($this->slots as $slot) {
            [$start, $end] = explode('-', $slot);
            $slots[$start] = str_replace(':', 'h', $start) . ' – ' . str_replace(':', 'h', $end);
        }

        return $slots;
    }

    /**
     * Slots offered on a given event day (those ending before the day's closing time).
     *
     * @return array<string, string>
     */
    public function slotsFor(Experience $experience, \DateTimeInterface $date): array
    {
        $closing = $this->experiences[$experience->value]['dates'][$date->format('Y-m-d')] ?? null;
        if (null === $closing) {
            return [];
        }

        $labels = $this->allSlots();
        $slots = [];
        foreach ($this->slots as $slot) {
            [$start, $end] = explode('-', $slot);
            if ($end <= $closing) {
                $slots[$start] = $labels[$start];
            }
        }

        return $slots;
    }

    public function slotStartsAt(\DateTimeInterface $date, string $slot): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d') . ' ' . $slot, $this->timezone);
    }

    /**
     * Days covered by a slot-based experience (they have their own forms).
     */
    public function isEventDate(\DateTimeInterface $date): bool
    {
        foreach (Experience::cases() as $experience) {
            if ($experience->hasSlots() && $this->hasDate($experience, $date)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} first and last day of the request month
     */
    public function requestPeriod(): array
    {
        $first = $this->day($this->requestMonth . '-01');

        return [$first, $first->modify('last day of this month')];
    }

    /**
     * Date accepted by the "autres jours d'octobre" form: a weekday of the request month,
     * outside event days and closed dates (ignores "today", see validator).
     */
    public function isRequestDate(\DateTimeInterface $date): bool
    {
        [$first, $last] = $this->requestPeriod();
        $day = $date->format('Y-m-d');

        return $day >= $first->format('Y-m-d') && $day <= $last->format('Y-m-d')
            && (int) $date->format('N') < 6
            && !\in_array($day, $this->requestClosedDates, true)
            && !$this->isEventDate($date);
    }

    /**
     * @return list<string> "Y-m-d" days refused by the request form besides weekends and event days
     */
    public function requestClosedDates(): array
    {
        return $this->requestClosedDates;
    }

    /**
     * The event-local calendar day of an instant (midnight, event timezone).
     */
    public function dayOf(\DateTimeInterface $instant): \DateTimeImmutable
    {
        return $this->day(\DateTimeImmutable::createFromInterface($instant)->setTimezone($this->timezone)->format('Y-m-d'));
    }

    private function day(string $ymd): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd, $this->timezone);
    }
}
