<?php

declare(strict_types=1);

namespace App\Domain\Request;

enum AttentionQueue: string
{
    case CloseRequest = 'close_request';
    case AssignExecutor = 'assign_executor';
    case StartOrResumeWork = 'start_or_resume_work';
    case UploadReport = 'upload_report';
    case ClaimExpert = 'claim_expert';
    case PublishOpinion = 'publish_opinion';
    case SecurityDecision = 'security_decision';

    public function title(): string
    {
        return match ($this) {
            self::CloseRequest => 'Закрыть заявку',
            self::AssignExecutor => 'Назначить исполнителя',
            self::StartOrResumeWork => 'Начать или возобновить работы',
            self::UploadReport => 'Загрузить отчёт',
            self::ClaimExpert => 'Взять заявку на экспертизу',
            self::PublishOpinion => 'Подготовить заключение',
            self::SecurityDecision => 'Принять решение СБ',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CloseRequest => 'Проверьте отчёт и выберите необходимые проверки при закрытии заявки.',
            self::AssignExecutor => 'Назначьте ответственного за проведение испытаний.',
            self::StartOrResumeWork => 'Начните или возобновите работу по заявке.',
            self::UploadReport => 'Загрузите отчёт о результатах испытаний в формате PDF.',
            self::ClaimExpert => 'Возьмите заявку в работу для подготовки заключения.',
            self::PublishOpinion => 'Подготовьте и опубликуйте экспертное заключение.',
            self::SecurityDecision => 'Сохраните решение «Согласовано» или «Не согласовано». Любое решение завершает заявку.',
        };
    }

    /** @return list<Role> */
    public function roles(): array
    {
        return match ($this) {
            self::CloseRequest, self::AssignExecutor => [Role::IcManager, Role::LaboratoryManager],
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
