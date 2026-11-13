<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthEndpointIsPublic(): void
    {
        $client = self::createClient();
        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $client->getResponse()->getContent());
    }

    public function testDashboardIsPubliclyAccessible(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar');
    }
}
