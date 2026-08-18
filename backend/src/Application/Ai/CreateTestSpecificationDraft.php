<?php

declare(strict_types=1);

namespace App\Application\Ai;

final readonly class CreateTestSpecificationDraft
{
    private const PROMPT = <<<'PROMPT'
Изучи приложенное исходное техническое задание и используй доступную через RAG нормативную и внутреннюю документацию АО «ЩЛЗ». Сформируй структурированный черновик ТЗ на проведение испытаний на русском языке.

Определи объект и цель испытаний; выдели контролируемые характеристики и параметры; укажи критерии и требования, только если они подтверждаются исходным документом или найденными нормативными источниками; предложи методы, условия и оборудование только когда они следуют из документа или найденных источников; перечисли исходную документацию, неизвестные данные и открытые вопросы.

Не придумывай отсутствующие требования и нормативные ссылки. Явно укажи, что это черновик, требующий проверки специалистом. Верни только текст черновика: не включай поисковые запросы, служебные пояснения и Markdown-разметку.
PROMPT;

    public function __construct(
        private TechnicalSpecificationDocumentPort $documents,
        private LizaPort $liza,
        private AiConversationPort $conversations,
        private bool $enabled,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(int $requestId, int $actorId, ?int $versionId): array
    {
        if (!$this->enabled) {
            throw new AiFeatureUnavailable('AI-анализ пока недоступен.');
        }
        $candidates = $this->documents->candidates($requestId, $actorId);
        if ($versionId === null) {
            if ($candidates === []) {
                return ['status' => 'not_found', 'message' => 'Не удалось найти техническое задание среди документов заявки.'];
            }
            if (count($candidates) > 1) {
                return ['status' => 'choice_required', 'documents' => array_map(
                    static fn (TechnicalSpecificationCandidate $candidate): array => $candidate->toArray(),
                    $candidates,
                )];
            }
            $versionId = $candidates[0]->versionId;
        } elseif (!$this->containsVersion($candidates, $versionId)) {
            throw new TechnicalSpecificationUnavailable('Выбранный документ недоступен или не похож на техническое задание.');
        }

        $file = $this->documents->file($requestId, $versionId, $actorId);
        $reply = $this->liza->start(self::PROMPT, $file);
        $conversationId = $this->conversations->create('draft', $requestId, $versionId, $actorId, $reply);
        return [
            'status' => 'completed',
            'conversationId' => $conversationId,
            'documentVersionId' => $versionId,
            'draft' => $this->plainDraft($reply->content),
            'notice' => 'Черновик требует проверки специалистом и не изменяет документы заявки.',
        ];
    }

    /** @param list<TechnicalSpecificationCandidate> $candidates */
    private function containsVersion(array $candidates, int $versionId): bool
    {
        foreach ($candidates as $candidate) {
            if ($candidate->versionId === $versionId) {
                return true;
            }
        }
        return false;
    }

    private function plainDraft(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", trim($content));
        if (preg_match('/^(?:#{1,6}\s+|\*\*)?(?:ЧЕРНОВИК|ТЕХНИЧЕСКОЕ ЗАДАНИЕ)\b/imu', $content, $match, PREG_OFFSET_CAPTURE) === 1) {
            $content = substr($content, (int) $match[0][1]);
        }
        $content = preg_replace('/^[ \t]*(?:\*{3,}|-{3,}|_{3,})[ \t]*$/mu', '', $content) ?? $content;
        $content = preg_replace('/^[ \t]*#{1,6}[ \t]+/mu', '', $content) ?? $content;
        $content = str_replace(['**', '__'], '', $content);
        $content = preg_replace("/\n{3,}/", "\n\n", $content) ?? $content;
        $content = trim($content);
        if ($content === '') {
            throw new AiFeatureUnavailable('ЛИЗА вернула пустой черновик. Повторите попытку.');
        }
        return $content;
    }
}
