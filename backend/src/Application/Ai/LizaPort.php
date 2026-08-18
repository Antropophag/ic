<?php

declare(strict_types=1);

namespace App\Application\Ai;

interface LizaPort
{
    public function start(
        string $prompt,
        ?TechnicalSpecificationFile $file = null,
        string $chatTitle = 'Анализ технического задания',
    ): LizaReply;
}
