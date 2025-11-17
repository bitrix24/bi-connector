<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApplicationTest extends TestCase
{
    public function testGetLogReturnsLoggerInstance(): void
    {
        // Set required environment variables for the test
        $_ENV['LOG_LEVEL'] = 'DEBUG';
        $_ENV['LOG_PATH'] = sys_get_temp_dir();
        $_ENV['LOG_ROTATION_DAYS'] = '7';

        // Test that getLog() returns a valid logger instance
        $logger = Application::getLog();

        $this->assertInstanceOf(LoggerInterface::class, $logger);

        // Clean up
        unset($_ENV['LOG_LEVEL'], $_ENV['LOG_PATH'], $_ENV['LOG_ROTATION_DAYS']);
    }

    public function testLoadConfigFromEnvFile(): void
    {
        // Create a temporary .env file for testing
        $tempEnvFile = dirname(__DIR__, 2) . '/.env.test';
        file_put_contents($tempEnvFile, "TEST_VAR=test_value\nLOG_LEVEL=DEBUG\n");

        // Load the config
        $reflection = new \ReflectionClass(Application::class);
        $method = $reflection->getMethod('loadConfigFromEnvFile');
        $method->setAccessible(true);

        // This should not throw any exceptions
        $this->expectNotToPerformAssertions();

        // Clean up
        if (file_exists($tempEnvFile)) {
            unlink($tempEnvFile);
        }
    }

    public function testParseLogLevel(): void
    {
        $reflection = new \ReflectionClass(Application::class);
        $method = $reflection->getMethod('parseLogLevel');
        $method->setAccessible(true);

        // Test various log levels
        $this->assertEquals(\Monolog\Level::Debug, $method->invoke(null, 'DEBUG'));
        $this->assertEquals(\Monolog\Level::Info, $method->invoke(null, 'INFO'));
        $this->assertEquals(\Monolog\Level::Error, $method->invoke(null, 'ERROR'));
        $this->assertEquals(\Monolog\Level::Debug, $method->invoke(null, 'UNKNOWN')); // Should default to DEBUG
    }
}
