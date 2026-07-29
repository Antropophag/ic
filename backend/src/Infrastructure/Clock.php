<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class Clock
{
    /**
     * Текущее время в UTC с реальной микросекундной точностью, в формате
     * 'Y-m-d H:i:s.u', совместимом со всеми колонками dateTime(6) в схеме.
     *
     * gmdate('Y-m-d H:i:s.u') — частая, но ошибочная замена: gmdate()/date()
     * работают с целочисленным Unix-timestamp и всегда подставляют .000000
     * вместо реальных микросекунд, несмотря на синтаксическую валидность
     * плейсхолдера .u (issue #86). Только microtime(true) даёт субсекундную
     * точность.
     */
    public static function now(): string
    {
        $formatted = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
        if ($formatted === false) {
            throw new \RuntimeException('Failed to build a high-precision timestamp');
        }

        return $formatted->format('Y-m-d H:i:s.u');
    }
}
