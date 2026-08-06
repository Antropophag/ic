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
                'assign_executor' => ['Назначить исполнителя', 'Выберите ответственного за испытания.', [Role::IcManager, Role::LaboratoryManager]],
                'start_or_resume_work' => ['Начать или возобновить работы', 'Начните зарегистрированные или возобновите приостановленные работы.', [Role::IcExecutor, Role::IcManager, Role::LaboratoryManager]],
                'upload_report' => ['Загрузить отчёт', 'Добавьте PDF с результатами испытаний.', [Role::IcExecutor, Role::IcManager, Role::LaboratoryManager]],
                'claim_expert' => ['Взять экспертизу', 'Возьмите заявку для подготовки заключения.', [Role::Expert]],
                'publish_opinion' => ['Подготовить заключение', 'Опубликуйте заключение и передайте его в СБ.', [Role::Expert]],
                'security_decision' => ['Проверить СБ', 'Согласуйте заключение или верните заявку.', [Role::SecurityOfficer]],
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
