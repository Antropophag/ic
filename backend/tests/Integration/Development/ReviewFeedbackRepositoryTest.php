<?php

declare(strict_types=1);

namespace Tests\Integration\Development;

use App\Infrastructure\Development\ReviewFeedbackRepository;
use Tests\Integration\IntegrationTestCase;

final class ReviewFeedbackRepositoryTest extends IntegrationTestCase
{
    public function testCreatesAndListsSharedFeedback(): void
    {
        $authorId = $this->createUser('review.author', 'Проверяющий');
        $repository = new ReviewFeedbackRepository($this->db());

        $created = $repository->create($authorId, 'Неясно, что делать дальше.', [
            'Понятно ли действие?',
            'Виден ли результат?',
        ]);

        self::assertSame('Проверяющий', $created['authorName']);
        self::assertSame('Неясно, что делать дальше.', $created['body']);
        self::assertSame(['Понятно ли действие?', 'Виден ли результат?'], $created['checklist']);
        self::assertSame($created, $repository->latest()[0]);
    }
}
