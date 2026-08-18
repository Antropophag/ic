<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ai;

use App\Application\Ai\AiFeatureUnavailable;
use App\Application\Ai\LizaPort;
use App\Application\Ai\LizaReply;
use App\Application\Ai\TechnicalSpecificationFile;

final class FakeLiza implements LizaPort
{
    /** @var list<string> */
    public array $startedPrompts = [];
    /** @var list<TechnicalSpecificationFile|null> */
    public array $startedFiles = [];
    /** @var list<string> */
    public array $chatTitles = [];
    public bool $fail = false;
    public ?string $startContent = null;

    public function start(
        string $prompt,
        ?TechnicalSpecificationFile $file = null,
        string $chatTitle = 'Анализ технического задания',
    ): LizaReply {
        if ($this->fail) {
            throw new AiFeatureUnavailable('timeout');
        }
        $this->startedPrompts[] = $prompt;
        $this->startedFiles[] = $file;
        $this->chatTitles[] = $chatTitle;
        $json = $this->startContent ?? (str_contains($prompt, 'черновик ТЗ')
            ? 'Черновик'
            : '{"criticalContradictions":[],"ambiguousRequirements":[],"missingInformation":[],'
                . '"testRequirements":[],"initiatorQuestions":[],"recommendations":[]}');
        return new LizaReply('chat-' . count($this->startedPrompts), 'message-1', $json);
    }
}
