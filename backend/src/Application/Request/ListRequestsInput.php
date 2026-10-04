<?php

declare(strict_types=1);

namespace App\Application\Request;

use App\Domain\Request\AttentionQueue;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\RequestColor;
use yii\base\Model;

final class ListRequestsInput extends Model
{
    public mixed $page = 1;
    public mixed $pageSize = 10;
    public mixed $tab = 'active';
    public mixed $status = null;
    public mixed $query = '';
    public mixed $sort = 'desc';
    public mixed $colors = '';
    public mixed $attention = null;

    public function rules(): array
    {
        return [
            [['page', 'pageSize'], 'integer', 'min' => 1],
            ['pageSize', 'integer', 'max' => 100],
            ['tab', 'in', 'range' => ['active', 'all', 'mine']],
            ['status', 'in', 'range' => array_column(RequestStatus::cases(), 'value'), 'skipOnEmpty' => true],
            ['query', 'string', 'max' => 200],
            ['sort', 'in', 'range' => ['asc', 'desc']],
            ['colors', 'string', 'max' => 64, 'skipOnEmpty' => false],
            ['colors', 'validateColors'],
            ['attention', 'in', 'range' => array_column(AttentionQueue::cases(), 'value'), 'skipOnEmpty' => true],
        ];
    }

    public function validateColors(): void
    {
        if (!is_string($this->colors)) {
            return;
        }
        $values = array_map('trim', explode(',', $this->colors));
        $allowed = array_column(RequestColor::cases(), 'value');
        if (count($values) > count($allowed) || array_diff($values, $allowed) !== []) {
            $this->addError('colors', 'Выберите направления испытаний из списка.');
        }
    }

    /** @return list<string> */
    public function colorValues(): array
    {
        return $this->colors === null || $this->colors === ''
            ? []
            : array_values(array_unique(array_map('trim', explode(',', (string) $this->colors))));
    }
}
