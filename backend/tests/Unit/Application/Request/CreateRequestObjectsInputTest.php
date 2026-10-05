<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\CreateRequestInput;
use PHPUnit\Framework\TestCase;

final class CreateRequestObjectsInputTest extends TestCase
{
    public function testOneAndTenObjectsAcceptCyrillicAndTextQuantities(): void
    {
        foreach ([1, 10] as $count) {
            $input = $this->input(array_fill(0, $count, ['productName' => 'Направляющая', 'sampleQuantity' => '4 шт по 3 метра']));
            self::assertTrue($input->validate(), json_encode($input->getErrors(), JSON_UNESCAPED_UNICODE));
            self::assertCount($count, $input->objectValues());
            self::assertNull($input->numericQuantity());
        }
        $input = $this->input([['productName' => str_repeat('я', 500), 'sampleQuantity' => str_repeat('я', 15)]]);
        self::assertTrue($input->validate());
    }

    public function testInvalidListsAndFieldsAreRejected(): void
    {
        $valid = ['productName' => 'Объект', 'sampleQuantity' => '2'];
        $cases = [[], array_fill(0, 11, $valid), 'invalid', [$valid, null],
            [['productName' => ' ', 'sampleQuantity' => '2']],
            [['productName' => str_repeat('я', 501), 'sampleQuantity' => '2']],
            [['productName' => 'Объект', 'sampleQuantity' => str_repeat('я', 16)]],
            [['productName' => 'Объект', 'sampleQuantity' => ' ']],
            [['productName' => 'Объект', 'sampleQuantity' => 2]],
            [3 => $valid], [$valid + ['manufacturer' => 'Не общий']]];
        foreach ($cases as $objects) {
            $input = $this->input($objects);
            self::assertFalse($input->validate());
            self::assertArrayHasKey('objects', $input->getErrors());
        }
    }

    public function testMixedLegacyAndObjectPayloadIsRejected(): void
    {
        $input = $this->input([['productName' => 'Объект', 'sampleQuantity' => '2']]);
        $input->productName = 'Другой объект';
        self::assertFalse($input->validate());
        self::assertArrayHasKey('objects', $input->getErrors());
    }

    public function testLegacyIntegerSyntaxKeepsItsNumericProjection(): void
    {
        $input = new CreateRequestInput();
        $input->setAttributes(['productName' => 'Объект', 'sampleQuantity' => '+2', 'manufacturer' => 'Завод', 'supplier' => 'Поставщик', 'testMethod' => 'Программа']);
        self::assertTrue($input->validate());
        self::assertSame(2, $input->numericQuantity());
    }

    private function input(mixed $objects): CreateRequestInput
    {
        $input = new CreateRequestInput();
        $input->setAttributes(['objects' => $objects, 'manufacturer' => 'Завод', 'supplier' => 'Поставщик', 'testMethod' => 'Программа']);
        return $input;
    }
}
