<?php

declare(strict_types=1);

namespace App\Application\Request;

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

    public function rules(): array
    {
        return [
            [['page', 'pageSize'], 'integer', 'min' => 1],
            ['pageSize', 'integer', 'max' => 100],
            ['tab', 'in', 'range' => ['active', 'all', 'mine']],
            ['status', 'in', 'range' => array_column(RequestStatus::cases(), 'value'), 'skipOnEmpty' => true],
            ['query', 'string', 'max' => 200],
            ['sort', 'in', 'range' => ['asc', 'desc']],
        ];
    }
}
