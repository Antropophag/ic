<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Application\Request\Command\CreateRequestCommand;
use yii\base\Model;

final class CreateRequest extends Model
{
    public ?string $productName = null;
    public ?string $manufacturer = null;
    public ?string $supplier = null;
    public mixed $sampleQuantity = null;
    public ?string $testMethod = null;

    public function rules(): array
    {
        return [
            [['productName', 'manufacturer', 'supplier', 'sampleQuantity', 'testMethod'], 'required'],
            [['productName', 'manufacturer', 'supplier'], 'string', 'max' => 500],
            ['testMethod', 'string', 'max' => 10000],
            ['sampleQuantity', 'integer', 'min' => 1],
        ];
    }

    public function toCommand(int $initiatorId): CreateRequestCommand
    {
        return new CreateRequestCommand(
            $initiatorId,
            (string) $this->productName,
            (string) $this->manufacturer,
            (string) $this->supplier,
            (int) $this->sampleQuantity,
            (string) $this->testMethod,
        );
    }
}
