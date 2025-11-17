<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class ConnectorRegistrationTest extends TestCase
{
    private ReflectionClass $appReflection;

    protected function setUp(): void
    {
        $this->appReflection = new ReflectionClass(Application::class);
    }

    public function testGetExistingConnectorIdReturnsCorrectId(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            [
                'id' => 1,
                'title' => 'MySQL Database Connector',
                'description' => 'Existing MySQL connector'
            ],
            [
                'id' => 2,
                'title' => 'Other Connector',
                'description' => 'Some other connector'
            ]
        ];

        // Test existing connector
        $result = $method->invoke(null, $existingConnectors, 'MySQL Database Connector');
        $this->assertEquals(1, $result, 'Should return ID 1 for existing MySQL connector');

        // Test non-existing connector
        $result = $method->invoke(null, $existingConnectors, 'PostgreSQL Database Connector');
        $this->assertNull($result, 'Should return null for non-existing PostgreSQL connector');
    }

    public function testGetExistingConnectorIdWithEmptyList(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $result = $method->invoke(null, [], 'MySQL Database Connector');
        $this->assertNull($result, 'Should return null for empty connector list');
    }

    public function testGetExistingConnectorIdIsCaseSensitive(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            [
                'id' => 1,
                'title' => 'MySQL Database Connector'
            ]
        ];

        $result = $method->invoke(null, $existingConnectors, 'mysql database connector');
        $this->assertNull($result, 'Should be case sensitive');
    }

    private function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $method = $this->appReflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }

    public function testGetExistingConnectorIdHandlesMissingIdField(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            [
                'title' => 'MySQL Database Connector'
                // Missing 'id' field
            ]
        ];

        $result = $method->invoke(null, $existingConnectors, 'MySQL Database Connector');
        $this->assertNull($result, 'Should return null when id field is missing');
    }

    public function testUpdateConnectorViaAPIMethodExists(): void
    {
        // Test that the updateConnectorViaAPI method exists and is callable
        $this->assertTrue(
            $this->appReflection->hasMethod('updateConnectorViaAPI'),
            'updateConnectorViaAPI method should exist'
        );

        $method = $this->appReflection->getMethod('updateConnectorViaAPI');
        $this->assertTrue($method->isStatic(), 'updateConnectorViaAPI should be static');
        $this->assertTrue($method->isPrivate(), 'updateConnectorViaAPI should be private');
    }
}
