<?php

namespace App\DataFixtures;

use App\Entity\AdminUser;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Development data: a default admin and a default commercial account.
 */
class AppFixtures extends Fixture
{
    public const ADMIN_EMAIL = 'admin@driveinpink.tn';
    public const ADMIN_PASSWORD = '123456789';
    public const COMMERCIAL_EMAIL = 'commercial@driveinpink.tn';
    public const COMMERCIAL_PASSWORD = '123456789';

    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher) {}

    public function load(ObjectManager $manager): void
    {
        $admin = new AdminUser();
        $admin->setEmail(self::ADMIN_EMAIL);
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, self::ADMIN_PASSWORD));
        $manager->persist($admin);

        $commercial = new AdminUser();
        $commercial->setEmail(self::COMMERCIAL_EMAIL);
        $commercial->setRoles([AdminUser::ROLE_CASHIER]);
        $commercial->setPassword($this->passwordHasher->hashPassword($commercial, self::COMMERCIAL_PASSWORD));
        $manager->persist($commercial);

        $manager->flush();
    }
}
