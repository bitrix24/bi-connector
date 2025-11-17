<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class InstallEndpointTest extends TestCase
{
    public function testInstallEndpointExists(): void
    {
        $installFile = dirname(__DIR__, 2) . '/public/install.php';
        $this->assertFileExists($installFile);
    }

    public function testIndexEndpointExists(): void
    {
        $indexFile = dirname(__DIR__, 2) . '/public/index.php';
        $this->assertFileExists($indexFile);
    }

    public function testRequestHandling(): void
    {
        // Test that we can create a request object
        $request = Request::create('/test', 'POST', ['action' => 'check']);

        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('check', $request->request->get('action'));
    }
}
