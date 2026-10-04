<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Reservation;
use App\Service\Reservation\ReservationSpreadsheetExporter;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FilterFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Green "Exporter Excel" button on a reservation list. The export contains every row of
 * the list as currently shown (same scope, search, filters and sort), not just one page.
 * The using controller adds the #[AdminRoute] on its own exportExcel() method.
 */
trait ExportsReservationsToExcel
{
    public const EXPORT_ACTION = 'exportExcel';

    private ReservationSpreadsheetExporter $spreadsheetExporter;

    #[Required]
    public function setSpreadsheetExporter(ReservationSpreadsheetExporter $spreadsheetExporter): void
    {
        $this->spreadsheetExporter = $spreadsheetExporter;
    }

    private static function exportExcelAction(): Action
    {
        return Action::new(self::EXPORT_ACTION, 'Exporter Excel', 'fas fa-file-excel')
            ->linkToCrudAction(self::EXPORT_ACTION)
            ->createAsGlobalAction()
            ->setCssClass('btn btn-success');
    }

    /**
     * @param AdminContext<Reservation> $context
     */
    private function exportReservations(AdminContext $context, string $filenamePrefix): StreamedResponse
    {
        $fields = new FieldCollection($this->configureFields(Crud::PAGE_INDEX));
        $filters = $this->container->get(FilterFactory::class)->create($context->getCrud()->getFiltersConfig(), $fields, $context->getEntity());
        $reservations = $this->createIndexQueryBuilder($context->getSearch(), $context->getEntity(), $fields, $filters)
            ->getQuery()
            ->toIterable();

        return $this->spreadsheetExporter->createResponse(
            $reservations,
            sprintf('%s-%s.xlsx', $filenamePrefix, $this->clock->now()->format('Y-m-d-His')),
        );
    }
}
