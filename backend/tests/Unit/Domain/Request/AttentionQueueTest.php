<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\AttentionQueue;
use App\Domain\Request\Role;
use PHPUnit\Framework\TestCase;

final class AttentionQueueTest extends TestCase
{
    public function testCategoriesHaveStableBusinessTextAndApplicableRoles(): void
    {
        self::assertSame(
            [
                'assign_executor' => ['Назначить исполнителя', 'Назначьте ответственного за проведение испытаний.', [Role::IcManager, Role::LaboratoryManager]],
                'start_or_resume_work' => ['Начать или возобновить работы', 'Начните или возобновите работу по заявке.', [Role::IcExecutor, Role::IcManager, Role::LaboratoryManager]],
                'upload_report' => ['Загрузить отчёт', 'Загрузите отчёт о результатах испытаний в формате PDF.', [Role::IcExecutor, Role::IcManager, Role::LaboratoryManager]],
                'claim_expert' => ['Взять заявку на экспертизу', 'Возьмите заявку в работу для подготовки заключения.', [Role::Expert]],
                'publish_opinion' => ['Подготовить заключение', 'Подготовьте и опубликуйте экспертное заключение.', [Role::Expert]],
                'security_decision' => ['Согласовать протокол испытаний', 'Согласуйте протокол испытаний либо верните заявку на доработку.', [Role::SecurityOfficer]],
            ],
            array_reduce(
                AttentionQueue::cases(),
                static function (array $result, AttentionQueue $queue): array {
                    $result[$queue->value] = [$queue->title(), $queue->description(), $queue->roles()];
                    return $result;
                },
                [],
            ),
        );
    }
}
