<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Empties the reservation table and restarts its ids, so the next reservation gets id 1
 * (e.g. to remove test data before opening the bookings).
 *
 * The cagnotte credits of the deleted test drives are deleted with them. Amounts added
 * by hand are kept unless --with-fund is given, which empties the whole cagnotte.
 */
#[AsCommand(name: 'app:reservations:reset', description: 'Delete every reservation and restart the reservation ids at 1')]
final class ResetReservationsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('with-fund', null, InputOption::VALUE_NONE, 'Also delete the amounts added by hand: the cagnotte restarts from its opening amount')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $withFund = (bool) $input->getOption('with-fund');

        $reservations = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reservation');
        $contributions = (int) $this->connection->fetchOne(
            $withFund ? 'SELECT COUNT(*) FROM fund_contribution' : 'SELECT COUNT(*) FROM fund_contribution WHERE reservation_id IS NOT NULL',
        );

        $io->warning(sprintf(
            'This deletes %d reservation(s) and %d cagnotte credit(s)%s. It cannot be undone.',
            $reservations,
            $contributions,
            $withFund ? ' (including the amounts added by hand)' : ' (amounts added by hand are kept)',
        ));

        if (!$input->getOption('force')) {
            if (!$input->isInteractive()) {
                $io->error('Run it with --force to confirm without a prompt.');

                return Command::FAILURE;
            }
            if (!$io->confirm('Continue?', false)) {
                $io->note('Nothing was deleted.');

                return Command::SUCCESS;
            }
        }

        $this->connection->transactional(function (Connection $connection) use ($withFund): void {
            $connection->executeStatement($withFund ? 'DELETE FROM fund_contribution' : 'DELETE FROM fund_contribution WHERE reservation_id IS NOT NULL');
            $connection->executeStatement('DELETE FROM reservation');
        });

        // Outside the transaction: on MySQL, ALTER TABLE commits implicitly.
        $this->restartIds('reservation');
        if (0 === (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fund_contribution')) {
            $this->restartIds('fund_contribution');
        }

        $io->success(sprintf('%d reservation(s) deleted. The next reservation will get id 1.', $reservations));

        return Command::SUCCESS;
    }

    private function restartIds(string $table): void
    {
        $platform = $this->connection->getDatabasePlatform();

        match (true) {
            $platform instanceof AbstractMySQLPlatform => $this->connection->executeStatement(sprintf('ALTER TABLE %s AUTO_INCREMENT = 1', $table)),
            $platform instanceof PostgreSQLPlatform => $this->connection->executeStatement(sprintf("SELECT setval(pg_get_serial_sequence('%s', 'id'), 1, false)", $table)),
            $platform instanceof SQLitePlatform => $this->connection->executeStatement('DELETE FROM sqlite_sequence WHERE name = ?', [$table]),
            default => throw new \LogicException(sprintf('Restarting ids is not supported on %s.', $platform::class)),
        };
    }
}
