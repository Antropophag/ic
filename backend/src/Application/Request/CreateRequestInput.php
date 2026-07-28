<?php

declare(strict_types=1);

namespace App\Application\Request;

use yii\base\Model;

final class CreateRequestInput extends Model
{
    public ?string $productName = null;
    public ?string $manufacturer = null;
    public ?string $supplier = null;
    public ?int $sampleQuantity = null;
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
}
