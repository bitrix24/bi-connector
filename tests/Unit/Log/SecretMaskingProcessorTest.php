<?php

declare(strict_types=1);

namespace App\Tests\Unit\Log;

use App\Log\SecretMaskingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SecretMaskingProcessorTest extends TestCase
{
    public function testConnectionSubtreeIsReplacedByTheMask(): void
    {
        $record = $this->process(['connection' => ['password' => 'secret', 'host' => 'db']]);

        $this->assertSame(SecretMaskingProcessor::MASK, $record->context['connection']);
        $this->assertStringNotContainsString('secret', $this->flatten($record->context));
    }

    public function testSecretNestedFourLevelsDeepIsMasked(): void
    {
        $record = $this->process([
            'request' => [
                'body' => [
                    'params' => [
                        'password' => 'secret',
                    ],
                ],
            ],
        ]);

        $this->assertStringNotContainsString('secret', $this->flatten($record->context));
        $this->assertSame(
            SecretMaskingProcessor::MASK,
            $record->context['request']['body']['params']['password']
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function sensitiveKeyProvider(): array
    {
        return [
            ['password'],
            ['connection'],
            ['auth'],
            ['accessToken'],
            ['access_token'],
            ['refreshToken'],
            ['refresh_token'],
        ];
    }

    #[DataProvider('sensitiveKeyProvider')]
    public function testEverySensitiveKeyIsMasked(string $key): void
    {
        $record = $this->process([$key => 'secret']);

        $this->assertSame(SecretMaskingProcessor::MASK, $record->context[$key]);
    }

    /**
     * @return list<array{string}>
     */
    public static function keyCaseProvider(): array
    {
        return [
            ['PASSWORD'],
            ['Password'],
            ['ACCESS_TOKEN'],
            ['AccessToken'],
        ];
    }

    #[DataProvider('keyCaseProvider')]
    public function testKeyMatchingIgnoresCase(string $key): void
    {
        $record = $this->process([$key => 'secret']);

        $this->assertSame(SecretMaskingProcessor::MASK, $record->context[$key]);
    }

    public function testInsensitiveKeysAreLeftUntouched(): void
    {
        $context = [
            'action' => 'data',
            'connectionType' => 'mysql',
            'table' => 'orders',
            'select' => ['ORDER_ID', 'AMOUNT'],
            'limit' => 100,
            'passwordPolicy' => 'strict',
        ];

        $this->assertSame($context, $this->process($context)->context);
    }

    #[DataProvider('levelProvider')]
    public function testMaskingDoesNotDependOnTheRecordLevel(Level $level): void
    {
        $record = $this->process(['password' => 'secret'], $level);

        $this->assertSame(SecretMaskingProcessor::MASK, $record->context['password']);
    }

    /**
     * @return list<array{Level}>
     */
    public static function levelProvider(): array
    {
        return [
            [Level::Debug],
            [Level::Info],
            [Level::Warning],
            [Level::Error],
        ];
    }

    public function testNestingDeeperThanTheLimitIsCutOff(): void
    {
        $context = ['password' => 'secret'];

        for ($depth = 0; $depth < 40; $depth++) {
            $context = ['level' => $context];
        }

        $this->assertStringNotContainsString('secret', $this->flatten($this->process($context)->context));
    }

    public function testSelfReferencingContextIsNotWalkedForever(): void
    {
        $branch = ['password' => 'secret'];
        $branch['self'] = &$branch;

        $record = $this->process(['root' => $branch]);

        $this->assertStringNotContainsString('secret', $this->flatten($record->context));
    }

    public function testEmptyContextStaysEmpty(): void
    {
        $this->assertSame([], $this->process([])->context);
    }

    public function testTheImageKeepsTheArgumentsOfAFrameOutOfATrace(): void
    {
        // The processor masks the context of a record and does not reach into a stack trace, which is
        // written to the log on every failure. Keeping the arguments of a frame out of the trace is what
        // the image is left to do, and the built-in default of PHP does the opposite.
        $path = dirname(__DIR__, 3) . '/Dockerfile';
        $contents = is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            $this->fail('The Dockerfile of the image is not readable at ' . $path . '.');
        }

        $this->assertMatchesRegularExpression(
            '/zend\.exception_ignore_args\s*=\s*On/i',
            $contents,
            'The image has to keep the arguments of a frame out of a trace, or a secret passed to a '
            . 'frame reaches the log in clear text.'
        );
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private function process(array $context, Level $level = Level::Debug): LogRecord
    {
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'BiConnectorApp',
            $level,
            'test.message',
            $context
        );

        return (new SecretMaskingProcessor())($record);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private function flatten(array $context): string
    {
        return print_r($context, true);
    }
}
