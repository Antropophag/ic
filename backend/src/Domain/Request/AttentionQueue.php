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
            self::ClaimExpert => 'Взять заявку на экспертизу',
            self::PublishOpinion => 'Подготовить заключение',
            self::SecurityDecision => 'Согласовать протокол испытаний',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AssignExecutor => 'Назначьте ответственного за проведение испытаний.',
            self::StartOrResumeWork => 'Начните работу по зарегистрированным заявкам или возобновите приостановленные.',
            self::UploadReport => 'Загрузите отчёт о результатах испытаний в формате PDF.',
            self::ClaimExpert => 'Возьмите заявку в работу для подготовки заключения.',
            self::PublishOpinion => 'Подготовьте и опубликуйте экспертное заключение.',
            self::SecurityDecision => 'Согласуйте протокол испытаний либо верните заявку на доработку.',
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
