<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\CreateRequestInput;
use PHPUnit\Framework\TestCase;

final class CreateRequestInputTest extends TestCase
{
    public function testValidRequestDataPassesValidation(): void
    {
        $input = new CreateRequestInput();
        $input->setAttributes([
            'productName' => 'Испытуемый образец',
            'manufacturer' => 'Производитель',
            'supplier' => 'Поставщик',
            'sampleQuantity' => 2,
            'testMethod' => 'Типовая программа',
        ]);

        self::assertTrue($input->validate());
        self::assertSame([], $input->getErrors());
    }

    public function testRequiredFieldsAreRejected(): void
    {
        $input = new CreateRequestInput();

        self::assertFalse($input->validate());
        self::assertSame(
            ['productName', 'manufacturer', 'supplier', 'sampleQuantity', 'testMethod'],
            array_keys($input->getErrors()),
        );
    }

    public function testQuantityMustBePositiveInteger(): void
    {
        $input = $this->validInput();
        $input->sampleQuantity = 0;
        self::assertFalse($input->validate());
        self::assertArrayHasKey('sampleQuantity', $input->getErrors());

        $input = $this->validInput();
        $input->sampleQuantity = 2.5;
        self::assertFalse($input->validate());
        self::assertArrayHasKey('sampleQuantity', $input->getErrors());
    }

    public function testTextLengthLimitsAreEnforced(): void
    {
        $input = $this->validInput();
        $input->productName = str_repeat('я', 501);
        $input->testMethod = str_repeat('я', 10001);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('productName', $input->getErrors());
        self::assertArrayHasKey('testMethod', $input->getErrors());
    }

    private function validInput(): CreateRequestInput
    {
        $input = new CreateRequestInput();
        $input->setAttributes([
            'productName' => 'Образец',
            'manufacturer' => 'Производитель',
            'supplier' => 'Поставщик',
            'sampleQuantity' => 1,
            'testMethod' => 'Метод',
        ]);
        return $input;
    }
}
