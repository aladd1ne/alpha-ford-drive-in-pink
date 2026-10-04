<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Entity\Reservation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the .xlsx download of a reservation list (back-office "Exporter Excel" button).
 */
final class ReservationSpreadsheetExporter
{
    private const HEADERS = [
        'Expérience',
        'Date',
        'Créneau',
        'Véhicule',
        'Nom et prénom',
        'Téléphone',
        'E-mail',
        'Statut',
        'Test drive',
        'Test drive validé le',
        'Validé par',
        'Reçue le',
    ];

    /**
     * @param iterable<Reservation> $reservations
     */
    public function createResponse(iterable $reservations, string $filename): StreamedResponse
    {
        $spreadsheet = $this->build($reservations);

        $response = new StreamedResponse(static function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @param iterable<Reservation> $reservations
     */
    public function build(iterable $reservations): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Réservations');
        $sheet->fromArray(self::HEADERS);

        $row = 2;
        foreach ($reservations as $reservation) {
            $sheet->fromArray([
                $reservation->getExperience()->label(),
                self::excelDate($reservation->getDate()),
                $reservation->getSlot(),
                $reservation->getVehicle()?->label(),
                $reservation->getFullName(),
                $reservation->getPhone(),
                $reservation->getEmail(),
                $reservation->getStatus()->label(),
                $reservation->getTestDriveStatus()->label(),
                self::excelDate($reservation->getTestDriveCompletedAt()),
                $reservation->getTestDriveValidatedBy(),
                self::excelDate($reservation->getCreatedAt()),
            ], null, 'A' . $row, true);
            // Phone numbers stay text ("+216 ..." must not become a number).
            $sheet->setCellValueExplicit('F' . $row, $reservation->getPhone(), DataType::TYPE_STRING);
            ++$row;
        }

        $lastRow = max(2, $row - 1);
        $sheet->getStyle('B2:B' . $lastRow)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->getStyle('J2:J' . $lastRow)->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
        $sheet->getStyle('L2:L' . $lastRow)->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');

        $lastColumn = Coordinate::stringFromColumnIndex(\count(self::HEADERS));
        $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:' . $lastColumn . $lastRow);
        for ($column = 1; $column <= \count(self::HEADERS); ++$column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }

        return $spreadsheet;
    }

    private static function excelDate(?\DateTimeInterface $date): ?float
    {
        return null === $date ? null : (float) ExcelDate::PHPToExcel($date);
    }
}
