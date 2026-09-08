<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataSource\ClickHouse;

use App\DataSource\ClickHouse\ClickHouseDataSource;
use App\DataSource\ClickHouse\ClickHouseHttpClient;
use App\DataSource\ClickHouse\ClickHouseQueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ClickHouseQueryBuilderTest extends TestCase
{
    private const ROW_LIMIT_VARIABLE = 'MAX_RESULT_ROWS';

    /** @var array<string, mixed>|null */
    private ?array $capturedRequest = null;

    private mixed $rowLimitBackup = null;

    protected function setUp(): void
    {
        $this->capturedRequest = null;
        $this->rowLimitBackup = $_ENV[self::ROW_LIMIT_VARIABLE] ?? null;
        unset($_ENV[self::ROW_LIMIT_VARIABLE]);
    }

    protected function tearDown(): void
    {
        if ($this->rowLimitBackup === null) {
            unset($_ENV[self::ROW_LIMIT_VARIABLE]);

            return;
        }

        $_ENV[self::ROW_LIMIT_VARIABLE] = $this->rowLimitBackup;
    }

    public function testCheckReachesTheSourceWithoutTouchingATable(): void
    {
        $this->assertSame('SELECT 1', $this->createQueryBuilder()->buildCheck());
    }

    public function testEveryColumnIsReadWhenNoFieldWasAsked(): void
    {
        $this->assertSame(
            'SELECT * FROM `report` LIMIT 100',
            $this->createQueryBuilder()->buildSelect('report', [], [], 100)
        );
    }

    public function testAskedFieldsAreQuotedOneByOne(): void
    {
        $this->assertSame(
            'SELECT `id`, `title` FROM `report` LIMIT 100',
            $this->createQueryBuilder()->buildSelect('report', ['id', 'title'], [], 100)
        );
    }

    public function testStatementWithoutARowLimitCarriesNoLimitClause(): void
    {
        $this->assertSame(
            'SELECT * FROM `report`',
            $this->createQueryBuilder()->buildSelect('report', [], [], 0)
        );
    }

    /**
     * A backquote inside a name is doubled instead of ending the name.
     */
    public function testNamesAreQuotedAgainstBreakingOutOfTheStatement(): void
    {
        $this->assertSame(
            'SELECT `a``b` FROM `re``port`',
            $this->createQueryBuilder()->buildSelect('re`port', ['a`b'], [], 0)
        );
    }

    /**
     * One case per entry of the operator dictionary.
     *
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function operatorProvider(): iterable
    {
        yield '=' => [['operator' => '=', 'value' => 10], '`amount` = 10'];
        yield 'EQ' => [['operator' => 'EQ', 'value' => 10], '`amount` = 10'];
        yield '!=' => [['operator' => '!=', 'value' => 10], '`amount` != 10'];
        yield '<>' => [['operator' => '<>', 'value' => 10], '`amount` != 10'];
        yield 'NEQ' => [['operator' => 'NEQ', 'value' => 10], '`amount` != 10'];
        yield '>' => [['operator' => '>', 'value' => 10], '`amount` > 10'];
        yield 'GT' => [['operator' => 'GT', 'value' => 10], '`amount` > 10'];
        yield '>=' => [['operator' => '>=', 'value' => 10], '`amount` >= 10'];
        yield 'GTE' => [['operator' => 'GTE', 'value' => 10], '`amount` >= 10'];
        yield '<' => [['operator' => '<', 'value' => 10], '`amount` < 10'];
        yield 'LT' => [['operator' => 'LT', 'value' => 10], '`amount` < 10'];
        yield '<=' => [['operator' => '<=', 'value' => 10], '`amount` <= 10'];
        yield 'LTE' => [['operator' => 'LTE', 'value' => 10], '`amount` <= 10'];
        yield 'LIKE' => [['operator' => 'LIKE', 'value' => 'acme'], "`amount` LIKE '%acme%'"];
        yield 'NOT LIKE' => [['operator' => 'NOT LIKE', 'value' => 'acme'], "`amount` NOT LIKE '%acme%'"];
        yield 'IN' => [['operator' => 'IN', 'value' => [1, 2]], '`amount` IN (1, 2)'];
        yield 'NOT IN' => [['operator' => 'NOT IN', 'value' => [1, 2]], '`amount` NOT IN (1, 2)'];
        yield 'IS NULL' => [['operator' => 'IS NULL'], '`amount` IS NULL'];
        yield 'IS NOT NULL' => [['operator' => 'IS NOT NULL'], '`amount` IS NOT NULL'];
        yield 'BETWEEN' => [['operator' => 'BETWEEN', 'from' => 1, 'to' => 5], '`amount` BETWEEN 1 AND 5'];
    }

    #[DataProvider('operatorProvider')]
    public function testOperatorBecomesItsPredicate(mixed $condition, string $expectedPredicate): void
    {
        $this->assertSame(
            'SELECT * FROM `report` WHERE ' . $expectedPredicate . ' LIMIT 100',
            $this->createQueryBuilder()->buildSelect('report', [], ['amount' => $condition], 100)
        );
    }

    public function testOperatorIsReadWithoutRegardToCase(): void
    {
        $this->assertSame(
            "SELECT * FROM `report` WHERE `title` LIKE '%acme%' LIMIT 100",
            $this->createQueryBuilder()->buildSelect(
                'report',
                [],
                ['title' => ['operator' => 'like', 'value' => 'acme']],
                100
            )
        );
    }

    public function testPlainValueIsReadAsAnEqualityCondition(): void
    {
        $this->assertSame(
            "SELECT * FROM `report` WHERE `title` = 'acme' LIMIT 100",
            $this->createQueryBuilder()->buildSelect('report', [], ['title' => 'acme'], 100)
        );
    }

    public function testListOfValuesIsReadAsAMembershipCondition(): void
    {
        $this->assertSame(
            "SELECT * FROM `report` WHERE `title` IN ('a', 'b') LIMIT 100",
            $this->createQueryBuilder()->buildSelect('report', [], ['title' => ['a', 'b']], 100)
        );
    }

    public function testSeveralFiltersAreJoinedByConjunction(): void
    {
        $this->assertSame(
            "SELECT * FROM `report` WHERE `amount` > 10 AND `title` = 'acme' LIMIT 100",
            $this->createQueryBuilder()->buildSelect(
                'report',
                [],
                [
                    'amount' => ['operator' => '>', 'value' => 10],
                    'title' => 'acme',
                ],
                100
            )
        );
    }

    /**
     * A quote inside a value is escaped and stays part of the literal instead of ending it.
     */
    public function testQuoteInsideAValueDoesNotEndTheLiteral(): void
    {
        $this->assertSame(
            "SELECT * FROM `report` WHERE `title` = 'O\\'Brien\\' OR 1=1 --' LIMIT 100",
            $this->createQueryBuilder()->buildSelect(
                'report',
                [],
                ['title' => ['operator' => '=', 'value' => "O'Brien' OR 1=1 --"]],
                100
            )
        );
    }

    public function testValueOfASubstringMatchIsEscapedAsWell(): void
    {
        $this->assertSame(
            "SELECT * FROM `report` WHERE `title` LIKE '%O\\'Brien%' LIMIT 100",
            $this->createQueryBuilder()->buildSelect(
                'report',
                [],
                ['title' => ['operator' => 'LIKE', 'value' => "O'Brien"]],
                100
            )
        );
    }

    /**
     * An operator outside the dictionary drops its filter: no text of the request reaches the statement.
     */
    public function testUnknownOperatorIsReportedAndAppliesNoFilter(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'ClickHouseQueryBuilder.buildFieldPredicate.unknownOperator',
                $this->callback(
                    static fn(array $context): bool => $context['operator'] === 'DROP TABLE'
                        && $context['field'] === 'title'
                )
            );

        $sql = (new ClickHouseQueryBuilder($logger))->buildSelect(
            'report',
            [],
            ['title' => ['operator' => 'DROP TABLE', 'value' => 'report']],
            100
        );

        $this->assertSame('SELECT * FROM `report` LIMIT 100', $sql);
    }

    public function testMembershipConditionWithoutValuesAppliesNoFilter(): void
    {
        $this->assertSame(
            'SELECT * FROM `report` LIMIT 100',
            $this->createQueryBuilder()->buildSelect(
                'report',
                [],
                ['title' => ['operator' => 'IN', 'value' => []]],
                100
            )
        );
    }

    public function testRangeConditionWithoutBothEndsAppliesNoFilter(): void
    {
        $this->assertSame(
            'SELECT * FROM `report` LIMIT 100',
            $this->createQueryBuilder()->buildSelect(
                'report',
                [],
                ['amount' => ['operator' => 'BETWEEN', 'from' => 1]],
                100
            )
        );
    }

    public function testTableListReadsTheCatalogueOfTheCurrentDatabase(): void
    {
        $this->assertSame(
            'SELECT `name` FROM `system`.`tables` WHERE `database` = currentDatabase() ORDER BY `name`',
            $this->createQueryBuilder()->buildTableList('')
        );
    }

    public function testSearchStringBecomesAnEscapedLiteralOfThePredicate(): void
    {
        $this->assertSame(
            'SELECT `name` FROM `system`.`tables` WHERE `database` = currentDatabase()'
            . " AND `name` LIKE '%o\\'brien%' ORDER BY `name`",
            $this->createQueryBuilder()->buildTableList("o'brien")
        );
    }

    public function testTableDescriptionReadsTheColumnsInTheOrderOfTheTable(): void
    {
        $this->assertSame(
            'SELECT `name`, `type` FROM `system`.`columns` WHERE `database` = currentDatabase()'
            . " AND `table` = 'report' ORDER BY `position`",
            $this->createQueryBuilder()->buildTableDescription('report')
        );
    }

    public function testTableNameOfADescriptionIsEscapedAsALiteral(): void
    {
        $this->assertStringContainsString(
            "AND `table` = 'report\\' OR 1=1 --'",
            $this->createQueryBuilder()->buildTableDescription("report' OR 1=1 --")
        );
    }

    /**
     * The row limit of a statement and the `max_result_rows` boundary of the request are the same number:
     * a `LIMIT` above the boundary would fail the statement instead of shortening its answer.
     */
    public function testRowLimitReachesBothTheStatementAndTheRequestParameters(): void
    {
        $_ENV[self::ROW_LIMIT_VARIABLE] = '1000';

        $dataSource = new ClickHouseDataSource(
            ['host' => 'ch.example.com', 'database' => 'analytics'],
            new NullLogger(),
            new ClickHouseHttpClient(
                ['host' => 'ch.example.com', 'database' => 'analytics'],
                new NullLogger(),
                $this->createCapturingTransport()
            )
        );

        foreach ($dataSource->fetchData('report', ['id'], [], 5000) as $row) {
            $this->assertIsArray($row);
        }

        $this->assertNotNull($this->capturedRequest, 'No request reached the transport.');
        $this->assertSame('SELECT `id` FROM `report` LIMIT 1000', $this->capturedRequest['options']['body']);
        $this->assertSame('1000', $this->requestParameters()['max_result_rows']);
    }

    private function createQueryBuilder(): ClickHouseQueryBuilder
    {
        return new ClickHouseQueryBuilder(new NullLogger());
    }

    private function createCapturingTransport(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->capturedRequest = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return new MockResponse("[\"id\"]\n[\"UInt64\"]\n", ['http_code' => 200]);
        });
    }

    /**
     * @return array<string, string>
     */
    private function requestParameters(): array
    {
        $this->assertNotNull($this->capturedRequest, 'No request reached the transport.');

        $query = explode('?', (string)$this->capturedRequest['url'], 2)[1] ?? '';
        $parameters = [];
        parse_str($query, $parameters);

        /** @var array<string, string> $parameters */
        return $parameters;
    }
}
