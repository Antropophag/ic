<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Infrastructure\Deployment\DatabasePurpose;
use Yii;
use yii\db\Connection;
use yii\web\Request;
use yii\web\UnauthorizedHttpException;

final class CurrentUser
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function id(Request $request): int
    {
        $identityHeader = Yii::$app->params['identityHeader'] ?? null;
        if (is_string($identityHeader) && $this->allowsIdentityHeader($identityHeader)) {
            $header = $request->headers->get($identityHeader);
            $id = filter_var($header, FILTER_VALIDATE_INT);
            $identity = $id !== false && $id > 0 ? $this->activeIdentity($id) : null;
            if ($identity !== null) {
                $this->recordActivityBestEffort($id, $identity['lastActivityAt']);
                return $id;
            }
        }

        $sessionUserId = Yii::$app->session->get('userId');
        if (is_int($sessionUserId)) {
            // AUTH-003 проверяется на каждый запрос, а не только при входе —
            // отключение локального профиля должно немедленно обрывать уже
            // открытую сессию, а не только блокировать следующий логин.
            $identity = $this->activeIdentity($sessionUserId);
            if ($identity !== null) {
                $this->recordActivityBestEffort($sessionUserId, $identity['lastActivityAt']);
                return $sessionUserId;
            }
        }

        throw new UnauthorizedHttpException('Authentication required');
    }

    /** @return array{lastActivityAt: string|null}|null */
    private function activeIdentity(int $userId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT is_active AS isActive, last_activity_at AS lastActivityAt FROM {{%users}} WHERE id = :id',
            [':id' => $userId],
        )->queryOne();

        if ($row === false || !(bool) $row['isActive']) {
            return null;
        }
        return ['lastActivityAt' => is_string($row['lastActivityAt']) ? $row['lastActivityAt'] : null];
    }

    private function allowsIdentityHeader(string $identityHeader): bool
    {
        $database = $this->db->createCommand('SELECT DATABASE()')->queryScalar();

        return is_string($database)
            && DatabasePurpose::allowsIdentityHeader($database, $identityHeader);
    }

    private function recordActivityBestEffort(int $userId, ?string $knownActivityAt): void
    {
        try {
            (new UserActivityRecorder($this->db))->recordActivity($userId, $knownActivityAt);
        } catch (\Throwable $error) {
            Yii::warning(
                'Failed to update authenticated user activity (' . $error::class . ')',
                __METHOD__,
            );
        }
    }
}
