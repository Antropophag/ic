<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use RuntimeException;
use yii\db\Connection;

final class LegacyProductNameSchemaGuard
{
    public static function assertCanRestoreLimit(Connection $db, int $maximum): void
    {
        $longNames = (int) $db->createCommand(
            'SELECT COUNT(*) FROM {{%requests}} WHERE CHAR_LENGTH(product_name) > :maximum',
            [':maximum' => $maximum],
        )->queryScalar();
        if ($longNames > 0) {
            throw new RuntimeException(
                "Cannot restore VARCHAR({$maximum}) product_name while imported requests contain longer values.",
            );
        }
    }
}
