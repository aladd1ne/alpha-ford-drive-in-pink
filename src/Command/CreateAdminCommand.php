<?php

namespace App\Command;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-admin', description: 'Create an admin user')]
class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Admin e-mail')
            ->addArgument('password', InputArgument::REQUIRED, 'Plain-text password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Invalid e-mail address.');
            return Command::FAILURE;
        }

        $admin = new AdminUser();
        $admin->setEmail($email);
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, $input->getArgument('password')));

        $this->em->persist($admin);
        $this->em->flush();

        $io->success(sprintf('Admin user "%s" created successfully.', $email));
        return Command::SUCCESS;
    }
}
