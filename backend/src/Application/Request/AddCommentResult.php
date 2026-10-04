<?php

declare(strict_types=1);

namespace App\Application\Request;

final readonly class AddCommentResult
{
    public function __construct(
        public int $id,
        public string $body,
        public string $createdAt,
        public string $authorName,
    ) {
    }

    /** @return array{id: int, body: string, createdAt: string, authorName: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'createdAt' => $this->createdAt,
            'authorName' => $this->authorName,
        ];
    }
}
