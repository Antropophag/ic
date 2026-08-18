<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use ElephantIO\Engine\Packet;
use ElephantIO\Engine\SocketIO\Version4X;

final class ElephantOpenWebUiSocketEngine extends Version4X
{
    private ?string $namespaceSid = null;

    public function namespaceSid(): ?string
    {
        return $this->namespaceSid;
    }

    protected function doChangeNamespace(): ?Packet
    {
        $packet = parent::doChangeNamespace();
        if ($packet !== null) {
            foreach ($packet->flatten() as $item) {
                if (is_array($item->data) && is_string($item->data['sid'] ?? null)) {
                    $this->namespaceSid = $item->data['sid'];
                    break;
                }
            }
        }

        return $packet;
    }
}
