<?php

declare(strict_types=1);

namespace App\Domain\Request;

enum AttentionQueue: string
{
    case AssignExecutor = 'assign_executor';
    case StartOrResumeWork = 'start_or_resume_work';
    case UploadReport = 'upload_report';
    case ClaimExpert = 'claim_expert';
    case PublishOpinion = 'publish_opinion';
    case SecurityDecision = 'security_decision';

    public function title(): string
    {
        return match ($this) {
            self::AssignExecutor => 'Назначить исполнителя',
            self::StartOrResumeWork => 'Начать или возобновить работы',
            self::UploadReport => 'Загрузить отчёт',
            self::ClaimExpert => 'Взять экспертизу',
            self::PublishOpinion => 'Подготовить заключение',
            self::SecurityDecision => 'Проверить СБ',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AssignExecutor => 'Выберите ответственного за испытания.',
            self::StartOrResumeWork => 'Начните зарегистрированные или возобновите приостановленные работы.',
            self::UploadReport => 'Добавьте PDF с результатами испытаний.',
            self::ClaimExpert => 'Возьмите заявку для подготовки заключения.',
            self::PublishOpinion => 'Опубликуйте заключение и передайте его в СБ.',
            self::SecurityDecision => 'Согласуйте заключение или верните заявку.',
        };
    }

    /** @return list<Role> */
    public function roles(): array
    {
        return match ($this) {
            self::AssignExecutor => [Role::IcManager, Role::LaboratoryManager],
            self::StartOrResumeWork, self::UploadReport => [
                Role::IcExecutor,
                Role::IcManager,
                Role::LaboratoryManager,
            ],
            self::ClaimExpert, self::PublishOpinion => [Role::Expert],
            self::SecurityDecision => [Role::SecurityOfficer],
        };
    }
}
