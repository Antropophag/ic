<?php

declare(strict_types=1);

namespace App\Application\Request;

use App\Domain\Request\AttentionQueue;
use App\Domain\Request\RequestStatus;
use yii\base\Model;

final class ListRequestsInput extends Model
{
    public mixed $page = 1;
    public mixed $pageSize = 10;
    public mixed $tab = 'active';
    public mixed $status = null;
    public mixed $query = '';
    public mixed $sort = 'desc';
    public mixed $attention = null;

    public function rules(): array
    {
        return [
            [['page', 'pageSize'], 'integer', 'min' => 1],
            ['pageSize', 'integer', 'max' => 100],
            ['tab', 'in', 'range' => ['active', 'all', 'mine']],
            ['status', 'validateStatuses', 'skipOnEmpty' => false],
            ['query', 'string', 'max' => 200],
            ['sort', 'in', 'range' => ['asc', 'desc']],
            ['attention', 'in', 'range' => array_column(AttentionQueue::cases(), 'value'), 'skipOnEmpty' => true],
        ];
    }

    public function validateStatuses(string $attribute): void
    {
        $value = $this->$attribute;
        if ($value === null || $value === '') {
            return;
        }
        if (!is_string($value)) {
            $this->addError($attribute, 'Некорректный список статусов.');
            return;
        }
        $parts = explode(',', $value);
        $allowed = array_column(RequestStatus::cases(), 'value');
        if (
            count($parts) > count($allowed)
            || in_array('', $parts, true)
            || array_filter($parts, static fn (string $status): bool => trim($status) !== $status) !== []
            || array_diff($parts, $allowed) !== []
        ) {
            $this->addError($attribute, 'Некорректный список статусов.');
        }
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return $this->status === null || $this->status === ''
            ? []
            : array_values(array_unique(explode(',', (string) $this->status)));
    }
}
