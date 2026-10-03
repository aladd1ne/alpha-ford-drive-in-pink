<?php

namespace App\Command;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-admin', description: 'Create an admin user, or a commercial with --commercial (interactive)')]
class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AdminUserRepository $admins,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('commercial', null, InputOption::VALUE_NONE, 'Create a commercial account (test drive validation only) instead of an admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->isInteractive()) {
            $io->error('This command asks for the e-mail and password: run it without --no-interaction.');

            return Command::FAILURE;
        }

        $commercial = (bool) $input->getOption('commercial');
        $kind = $commercial ? 'commercial' : 'admin';

        $io->title(sprintf('Create an %s user', $kind));

        $email = $io->askQuestion($this->emailQuestion());

        $password = $io->askHidden('Password', function (?string $value): string {
            if (mb_strlen((string) $value) < AdminUser::MIN_PASSWORD_LENGTH) {
                throw new \RuntimeException(sprintf('The password must contain at least %d characters.', AdminUser::MIN_PASSWORD_LENGTH));
            }

            return $value;
        });

        $io->askHidden('Confirm password', function (?string $value) use ($password): string {
            if ($value !== $password) {
                throw new \RuntimeException('The passwords do not match.');
            }

            return $value;
        });

        $admin = new AdminUser();
        $admin->setEmail($email);
        $admin->setRoles([$commercial ? AdminUser::ROLE_COMMERCIAL : AdminUser::ROLE_ADMIN]);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, $password));

        $this->em->persist($admin);
        $this->em->flush();

        $io->success(sprintf('%s user "%s" created successfully.', ucfirst($kind), $email));

        return Command::SUCCESS;
    }

    /**
     * E-mail prompt; after "@" it autocompletes the domains already used by admins.
     */
    private function emailQuestion(): Question
    {
        $domains = array_values(array_unique(array_map(
            static fn (AdminUser $admin): string => substr(strrchr($admin->getEmail(), '@') ?: '@', 1),
            $this->admins->findAll(),
        )));

        $question = new Question('E-mail');
        $question->setAutocompleterCallback(static function (string $typed) use ($domains): array {
            $at = strpos($typed, '@');
            if (false === $at) {
                return [];
            }

            $local = substr($typed, 0, $at + 1);

            return array_map(static fn (string $domain): string => $local . $domain, array_filter($domains));
        });
        $question->setNormalizer(static fn (?string $value): string => mb_strtolower(trim((string) $value)));
        $question->setValidator(function (string $email): string {
            if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Invalid e-mail address.');
            }
            if (null !== $this->admins->findOneBy(['email' => $email])) {
                throw new \RuntimeException(sprintf('An admin with the e-mail "%s" already exists.', $email));
            }

            return $email;
        });
        $question->setMaxAttempts(3);

        return $question;
    }
}
