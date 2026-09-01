<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validation;

use App\Validation\NameValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameValidatorTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function acceptedTableNameProvider(): array
    {
        return [
            ['orders'],
            ['o'],
            ['order_items'],
            ['orders2024'],
            ['b24_crm_deal_stage_1'],
        ];
    }

    #[DataProvider('acceptedTableNameProvider')]
    public function testAcceptedTableNamesAreReturnedUnchanged(string $name): void
    {
        $this->assertSame($name, (new NameValidator())->validateTableName($name));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function rejectedTableNameProvider(): array
    {
        return [
            'capital letter' => ['Orders'],
            'leading digit' => ['1orders'],
            'hyphen' => ['order-s'],
            'space' => ['order s'],
            'empty string' => [''],
            'leading underscore' => ['_orders'],
            'quote injection' => ['orders"; DROP TABLE users; --'],
            'trailing newline' => ["orders\n"],
            'not a string' => [42],
            'null' => [null],
            'array' => [['orders']],
        ];
    }

    #[DataProvider('rejectedTableNameProvider')]
    public function testRejectedTableNamesRaiseAnError(mixed $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new NameValidator())->validateTableName($name);
    }

    /**
     * @return list<array{string}>
     */
    public static function acceptedFieldNameProvider(): array
    {
        return [
            ['ORDER_ID'],
            ['ID'],
            ['A'],
            ['FIELD_2024'],
        ];
    }

    #[DataProvider('acceptedFieldNameProvider')]
    public function testAcceptedFieldNamesAreReturnedUnchanged(string $name): void
    {
        $this->assertSame($name, (new NameValidator())->validateFieldName($name));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function rejectedFieldNameProvider(): array
    {
        return [
            'lowercase' => ['order_id'],
            'mixed case' => ['Order_Id'],
            'leading digit' => ['1ORDER'],
            'hyphen' => ['ORDER-ID'],
            'space' => ['ORDER ID'],
            'empty string' => [''],
            'leading underscore' => ['_ORDER'],
            'not a string' => [42],
            'null' => [null],
        ];
    }

    #[DataProvider('rejectedFieldNameProvider')]
    public function testRejectedFieldNamesRaiseAnError(mixed $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new NameValidator())->validateFieldName($name);
    }

    public function testErrorMessageNamesTheRejectedValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid table name: "Orders"');

        (new NameValidator())->validateTableName('Orders');
    }

    public function testErrorMessageDistinguishesTheFieldPosition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid field name: "order_id"');

        (new NameValidator())->validateFieldName('order_id');
    }

    public function testEmptyFieldListIsAccepted(): void
    {
        $this->assertSame([], (new NameValidator())->validateFieldNames([]));
    }

    public function testFieldListIsValidatedElementByElement(): void
    {
        $names = ['ORDER_ID', 'AMOUNT'];

        $this->assertSame($names, (new NameValidator())->validateFieldNames($names));
    }

    public function testFieldListRejectsTheFirstUnacceptableElement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid field name: "amount"');

        (new NameValidator())->validateFieldNames(['ORDER_ID', 'amount']);
    }

    public function testFieldListRejectsAValueThatIsNotAList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A list of field names is required, string given');

        (new NameValidator())->validateFieldNames('ORDER_ID');
    }
}
