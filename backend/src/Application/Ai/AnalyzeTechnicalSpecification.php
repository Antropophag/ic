<?php

declare(strict_types=1);

namespace App\Application\Ai;

final readonly class AnalyzeTechnicalSpecification
{
    private const PROMPT = <<<'PROMPT'
Проведи анализ документа с учётом нормативной и внутренней документации АО «ЩЛЗ», доступной тебе через RAG.

Нужно выявить внутренние противоречия, неоднозначные требования, требования без измеримого критерия, недостающие для испытаний данные и требования, проверяемые испытаниями. Укажи нормативные требования только когда они реально найдены в доступной базе. Сформируй вопросы инициатору и рекомендации. Не придумывай отсутствующие требования и нормативные ссылки.

Верни только JSON-объект на русском языке с массивами строк: criticalContradictions, ambiguousRequirements, missingInformation, testRequirements, initiatorQuestions, recommendations.
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
        $analysis = $this->structured($reply->content);
        $conversationId = $this->conversations->create('analysis', $requestId, $versionId, $actorId, $reply);

        return [
            'status' => 'completed',
            'conversationId' => $conversationId,
            'documentVersionId' => $versionId,
            'analysis' => $analysis,
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

    /** @return array<string, list<string>> */
    private function structured(string $content): array
    {
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', trim($content)) ?? $content;
        $decoded = json_decode($content);
        if (!$decoded instanceof \stdClass) {
            throw new AiFeatureUnavailable('ЛИЗА вернула ответ в неподдерживаемом формате. Повторите попытку.');
        }
        $result = [];
        foreach ($this->analysisKeys() as $key) {
            if (!property_exists($decoded, $key) || !is_array($decoded->{$key}) || !array_is_list($decoded->{$key})) {
                throw new AiFeatureUnavailable('ЛИЗА вернула ответ в неподдерживаемом формате. Повторите попытку.');
            }
            foreach ($decoded->{$key} as $value) {
                if (!is_string($value)) {
                    throw new AiFeatureUnavailable('ЛИЗА вернула ответ в неподдерживаемом формате. Повторите попытку.');
                }
            }
            $result[$key] = $decoded->{$key};
        }
        return $result;
    }

    /** @return list<string> */
    private function analysisKeys(): array
    {
        return ['criticalContradictions', 'ambiguousRequirements', 'missingInformation', 'testRequirements', 'initiatorQuestions', 'recommendations'];
    }
}
