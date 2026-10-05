<?php

declare(strict_types=1);

namespace App\Application\Request;

use yii\base\Model;

final class CreateRequestInput extends Model
{
    public mixed $objects = null;
    public ?string $productName = null;
    public ?string $manufacturer = null;
    public ?string $supplier = null;
    public mixed $sampleQuantity = null;
    public ?string $testMethod = null;

    public function rules(): array
    {
        return [
            [['productName', 'manufacturer', 'supplier', 'sampleQuantity', 'testMethod'], 'required',
                'when' => fn (): bool => $this->objects === null],
            [['manufacturer', 'supplier', 'testMethod'], 'required', 'when' => fn (): bool => $this->objects !== null],
            ['objects', 'validateObjects', 'skipOnEmpty' => false],
            [['productName', 'manufacturer', 'supplier'], 'string', 'max' => 500],
            ['testMethod', 'string', 'max' => 10000],
            ['sampleQuantity', 'integer', 'min' => 1],
        ];
    }

    public function validateObjects(string $attribute): void
    {
        if ($this->objects === null) {
            return;
        }
        if ($this->productName !== null || $this->sampleQuantity !== null) {
            $this->addError($attribute, 'Передайте объекты либо поля одной позиции, не оба формата одновременно.');
            return;
        }
        if (!is_array($this->objects) || !array_is_list($this->objects) || count($this->objects) < 1 || count($this->objects) > 10) {
            $this->addError($attribute, 'В заявке должно быть от 1 до 10 объектов.');
            return;
        }
        foreach ($this->objects as $index => $object) {
            if (!is_array($object) || array_diff(array_keys($object), ['productName', 'sampleQuantity']) !== []) {
                $this->addError($attribute, 'Некорректные поля объекта ' . ($index + 1) . '.');
                continue;
            }
            foreach (['productName' => 500, 'sampleQuantity' => 15] as $field => $limit) {
                $value = $object[$field] ?? null;
                if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $limit) {
                    $label = $field === 'productName' ? 'наименование' : 'количество образцов';
                    $this->addError($attribute, 'Объект ' . ($index + 1) . ': ' . $label . ' — обязательный текст, не более ' . $limit . ' символов.');
                }
            }
        }
    }

    /** @return list<array{productName: string, sampleQuantity: string}> */
    public function objectValues(): array
    {
        if ($this->objects === null) {
            return [['productName' => (string) $this->productName, 'sampleQuantity' => (string) $this->sampleQuantity]];
        }
        return array_map(static fn (array $object): array => [
            'productName' => trim($object['productName']),
            'sampleQuantity' => trim($object['sampleQuantity']),
        ], $this->objects);
    }

    public function numericQuantity(): ?int
    {
        if ($this->objects === null) {
            return (int) $this->sampleQuantity;
        }
        $text = $this->objectValues()[0]['sampleQuantity'];
        return preg_match('/^[1-9][0-9]*$/D', $text) === 1 && (float) $text <= 4294967295
            ? (int) $text : null;
    }
}
