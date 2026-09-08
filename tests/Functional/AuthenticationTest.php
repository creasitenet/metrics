<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthenticationTest extends WebTestCase
{
    private const USER_EMAIL = 'admin@example.org';
    private const USER_PASSWORD = 'valid-password12';

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(self::USER_EMAIL, '');
        $user->setPassword($hasher->hashPassword($user, self::USER_PASSWORD));
        $entityManager->persist($user);
        $entityManager->flush();
        $entityManager->close();
        self::ensureKernelShutdown();
    }

    public function testLoginWithValidCredentialsGrantsDashboardAccess(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/login');

        $form = $crawler->selectButton('Open the console')->form([
            '_username' => self::USER_EMAIL,
            '_password' => self::USER_PASSWORD,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Containers');
    }

    public function testProtectedDashboardPartialRedirectsAnonymousUsersToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/partials/dashboard');

        self::assertResponseRedirects('/login', Response::HTTP_FOUND);
    }
}
