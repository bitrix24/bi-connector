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

        // Test existing connector by title
        $result = $method->invoke(null, $existingConnectors, 'MySQL Database Connector', '');
        $this->assertEquals(1, $result, 'Should return ID 1 for existing MySQL connector by title');

        // Test non-existing connector by title
        $result = $method->invoke(null, $existingConnectors, 'PostgreSQL Database Connector', '');
        $this->assertNull($result, 'Should return null for non-existing PostgreSQL connector by title');

        // Test existing connector by description
        $result = $method->invoke(null, $existingConnectors, 'Non-existing Title', 'Existing MySQL connector');
        $this->assertEquals(1, $result, 'Should return ID 1 for existing MySQL connector by description');

        // Test non-existing connector by description
        $result = $method->invoke(null, $existingConnectors, 'Non-existing Title', 'Non-existing description');
        $this->assertNull($result, 'Should return null for non-existing connector by description');
    }

    public function testGetExistingConnectorIdWithEmptyList(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $result = $method->invoke(null, [], 'MySQL Database Connector', 'Some description');
        $this->assertNull($result, 'Should return null for empty connector list');
    }

    public function testGetExistingConnectorIdIsCaseSensitive(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            [
                'id' => 1,
                'title' => 'MySQL Database Connector',
                'description' => 'MySQL Description'
            ]
        ];

        $result = $method->invoke(null, $existingConnectors, 'mysql database connector', '');
        $this->assertNull($result, 'Should be case sensitive for title');

        $result = $method->invoke(null, $existingConnectors, '', 'mysql description');
        $this->assertNull($result, 'Should be case sensitive for description');
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
                'title' => 'MySQL Database Connector',
                'description' => 'MySQL Description'
                // Missing 'id' field
            ]
        ];

        $result = $method->invoke(null, $existingConnectors, 'MySQL Database Connector', '');
        $this->assertNull($result, 'Should return null when id field is missing for title match');

        $result = $method->invoke(null, $existingConnectors, '', 'MySQL Description');
        $this->assertNull($result, 'Should return null when id field is missing for description match');
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

    public function testGetExistingConnectorIdWithTitleOrDescriptionLogic(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            [
                'id' => 1,
                'title' => 'MySQL Database Connector',
                'description' => 'First MySQL Description'
            ],
            [
                'id' => 2,
                'title' => 'PostgreSQL Database Connector',
                'description' => 'Second PostgreSQL Description'
            ]
        ];

        // Test title match with different description
        $result = $method->invoke(null, $existingConnectors, 'MySQL Database Connector', 'Different Description');
        $this->assertEquals(1, $result, 'Should find connector by title even with different description');

        // Test description match with different title
        $result = $method->invoke(null, $existingConnectors, 'Different Title', 'First MySQL Description');
        $this->assertEquals(1, $result, 'Should find connector by description even with different title');

        // Test both title and description match (should return first match by title)
        $result = $method->invoke(null, $existingConnectors, 'MySQL Database Connector', 'First MySQL Description');
        $this->assertEquals(1, $result, 'Should find connector when both title and description match');
    }

    public function testGetExistingConnectorIdWithEmptyDescription(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            [
                'id' => 1,
                'title' => 'MySQL Database Connector',
                'description' => 'MySQL Description'
            ]
        ];

        // Test with empty description - should only check by title
        $result = $method->invoke(null, $existingConnectors, 'MySQL Database Connector', '');
        $this->assertEquals(1, $result, 'Should find connector by title when description is empty');

        // Test with non-matching title and empty description
        $result = $method->invoke(null, $existingConnectors, 'Non-matching Title', '');
        $this->assertNull($result, 'Should not find connector when title does not match and description is empty');
    }

    public function testGetExistingConnectorIdWithMissingTitleOrDescription(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            [
                'id' => 1,
                'title' => 'MySQL Database Connector'
                // Missing 'description' field
            ],
            [
                'id' => 2,
                'description' => 'PostgreSQL Description'
                // Missing 'title' field
            ]
        ];

        // Test with missing description field
        $result = $method->invoke(null, $existingConnectors, 'MySQL Database Connector', 'Some Description');
        $this->assertEquals(1, $result, 'Should find connector by title when description field is missing');

        // Test with missing title field
        $result = $method->invoke(null, $existingConnectors, 'Some Title', 'PostgreSQL Description');
        $this->assertEquals(2, $result, 'Should find connector by description when title field is missing');
    }
}
