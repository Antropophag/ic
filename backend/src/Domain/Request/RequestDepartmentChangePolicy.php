<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class RequestDepartmentChangePolicy
{
    public function assertCanChange(bool $administrator, bool $actorActive): void
    {
        if (!$actorActive || !$administrator) {
            throw new RequestDepartmentChangeDenied(
                'Изменять подразделение заявки может только активный администратор.',
            );
        }
    }
}
