<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ai;

use App\Application\Ai\AiConversationPort;
use App\Application\Ai\AiFeatureUnavailable;
use App\Application\Ai\AnalyzeTechnicalSpecification;
use App\Application\Ai\CreateTestSpecificationDraft;
use App\Application\Ai\LizaPort;
use App\Application\Ai\LizaReply;
use App\Application\Ai\TechnicalSpecificationCandidate;
use App\Application\Ai\TechnicalSpecificationDocumentPort;
use App\Application\Ai\TechnicalSpecificationUnavailable;
use PHPUnit\Framework\TestCase;

final class AnalyzeTechnicalSpecificationTest extends TestCase
{
    public function testSingleCandidateIsAnalyzedAndGetsOwnConversation(): void
    {
        $documents = new FakeTechnicalSpecificationDocuments([$this->candidate(11)]);
        $liza = new FakeLiza();
        $conversations = new FakeConversations();
        $useCase = new AnalyzeTechnicalSpecification($documents, $liza, $conversations, true);

        $first = $useCase->execute(7, 3, null);
        $second = $useCase->execute(7, 3, null);

        self::assertSame('completed', $first['status']);
        self::assertSame(11, $documents->readVersionId);
        self::assertNotSame($first['conversationId'], $second['conversationId']);
        self::assertSame('/storage/tz.docx', $liza->startedFiles[0]?->path);
    }

    public function testSeveralCandidatesRequireExplicitChoice(): void
    {
        $useCase = new AnalyzeTechnicalSpecification(
            new FakeTechnicalSpecificationDocuments([$this->candidate(11), $this->candidate(12)]),
            new FakeLiza(),
            new FakeConversations(),
            true,
        );

        $result = $useCase->execute(7, 3, null);

        self::assertSame('choice_required', $result['status']);
        self::assertCount(2, $result['documents']);
    }

    public function testLargeDocumentIsSentAsOneFileAnalysisConversation(): void
    {
        $documents = new FakeTechnicalSpecificationDocuments([$this->candidate(11)]);
        $documents->size = 200_000_000;
        $liza = new FakeLiza();

        $result = (new AnalyzeTechnicalSpecification($documents, $liza, new FakeConversations(), true))
            ->execute(7, 3, null);

        self::assertSame('completed', $result['status']);
        self::assertCount(1, $liza->startedPrompts);
        self::assertSame(200_000_000, $liza->startedFiles[0]?->size);
    }

    public function testNoCandidateReturnsFriendlyEmptyState(): void
    {
        $result = (new AnalyzeTechnicalSpecification(
            new FakeTechnicalSpecificationDocuments([]),
            new FakeLiza(),
            new FakeConversations(),
            true,
        ))->execute(7, 3, null);

        self::assertSame('not_found', $result['status']);
    }

    public function testUnavailableSelectedDocumentIsRejected(): void
    {
        $this->expectException(TechnicalSpecificationUnavailable::class);
        (new AnalyzeTechnicalSpecification(
            new FakeTechnicalSpecificationDocuments([$this->candidate(11)]),
            new FakeLiza(),
            new FakeConversations(),
            true,
        ))->execute(7, 3, 99);
    }

    public function testFeatureFlagStopsBeforeDocumentsOrLiza(): void
    {
        $documents = new FakeTechnicalSpecificationDocuments([$this->candidate(11)]);
        $this->expectException(AiFeatureUnavailable::class);
        (new AnalyzeTechnicalSpecification($documents, new FakeLiza(), new FakeConversations(), false))->execute(7, 3, null);
    }

    public function testLizaErrorDoesNotCreateConversation(): void
    {
        $liza = new FakeLiza();
        $liza->fail = true;
        $conversations = new FakeConversations();
        try {
            (new AnalyzeTechnicalSpecification(
                new FakeTechnicalSpecificationDocuments([$this->candidate(11)]),
                $liza,
                $conversations,
                true,
            ))->execute(7, 3, null);
            self::fail('Expected Liza failure.');
        } catch (AiFeatureUnavailable) {
            self::assertSame([], $conversations->items);
        }
    }

    /** @dataProvider malformedResponses */
    public function testMalformedResponseNeverBecomesSuccessfulEmptyAnalysis(string $response): void
    {
        $liza = new FakeLiza();
        $liza->startContent = $response;
        $this->expectException(AiFeatureUnavailable::class);
        (new AnalyzeTechnicalSpecification(
            new FakeTechnicalSpecificationDocuments([$this->candidate(11)]),
            $liza,
            new FakeConversations(),
            true,
        ))->execute(7, 3, null);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedResponses(): iterable
    {
        yield 'missing key' => ['{"criticalContradictions":[]}'];
        yield 'scalar' => ['{"criticalContradictions":"none","ambiguousRequirements":[],"missingInformation":[],"testRequirements":[],"initiatorQuestions":[],"recommendations":[]}'];
        yield 'object' => ['{"criticalContradictions":{},"ambiguousRequirements":[],"missingInformation":[],"testRequirements":[],"initiatorQuestions":[],"recommendations":[]}'];
        yield 'null' => ['{"criticalContradictions":null,"ambiguousRequirements":[],"missingInformation":[],"testRequirements":[],"initiatorQuestions":[],"recommendations":[]}'];
        yield 'invalid element' => ['{"criticalContradictions":[1],"ambiguousRequirements":[],"missingInformation":[],"testRequirements":[],"initiatorQuestions":[],"recommendations":[]}'];
    }

    public function testDraftCreatesIndependentChatFromOriginalDocument(): void
    {
        $liza = new FakeLiza();
        $conversations = new FakeConversations();
        $documents = new FakeTechnicalSpecificationDocuments([$this->candidate(11)]);
        $analysis = (new AnalyzeTechnicalSpecification(
            $documents,
            $liza,
            $conversations,
            true,
        ))->execute(7, 3, null);

        $draft = (new CreateTestSpecificationDraft($documents, $liza, $conversations, true))->execute(7, 3, null);

        self::assertNotSame($analysis['conversationId'], $draft['conversationId']);
        self::assertSame('chat-1', $conversations->items[(string) $analysis['conversationId']]['chatId']);
        self::assertSame('chat-2', $conversations->items[(string) $draft['conversationId']]['chatId']);
        self::assertSame('analysis', $conversations->items[(string) $analysis['conversationId']]['taskType']);
        self::assertSame('draft', $conversations->items[(string) $draft['conversationId']]['taskType']);
        self::assertCount(2, $liza->startedFiles);
        self::assertSame('/storage/tz.docx', $liza->startedFiles[1]?->path);
        self::assertStringContainsString('Изучи приложенное исходное техническое задание', $liza->startedPrompts[1]);
        self::assertStringNotContainsString('ранее выполненного анализа', $liza->startedPrompts[1]);
        self::assertStringContainsString('проверки специалистом', $draft['notice']);
    }

    public function testDraftFailureDoesNotChangeCompletedAnalysis(): void
    {
        $liza = new FakeLiza();
        $conversations = new FakeConversations();
        $documents = new FakeTechnicalSpecificationDocuments([$this->candidate(11)]);
        $analysis = (new AnalyzeTechnicalSpecification(
            $documents,
            $liza,
            $conversations,
            true,
        ))->execute(7, 3, null);
        $liza->fail = true;

        try {
            (new CreateTestSpecificationDraft($documents, $liza, $conversations, true))->execute(7, 3, null);
            self::fail('Expected draft failure.');
        } catch (AiFeatureUnavailable) {
            self::assertArrayHasKey((string) $analysis['conversationId'], $conversations->items);
            self::assertCount(1, $conversations->items);
        }
    }

    public function testAnalysisFailureDoesNotPreventIndependentDraft(): void
    {
        $documents = new FakeTechnicalSpecificationDocuments([$this->candidate(11)]);
        $liza = new FakeLiza();
        $liza->fail = true;
        try {
            (new AnalyzeTechnicalSpecification($documents, $liza, new FakeConversations(), true))->execute(7, 3, null);
            self::fail('Expected analysis failure.');
        } catch (AiFeatureUnavailable) {
            $liza->fail = false;
            $conversations = new FakeConversations();
            $draft = (new CreateTestSpecificationDraft($documents, $liza, $conversations, true))->execute(7, 3, null);
            self::assertSame('completed', $draft['status']);
            self::assertSame('draft', array_values($conversations->items)[0]['taskType']);
        }
    }

    public function testDraftRemovesRagQueryAndMarkdownScaffolding(): void
    {
        $liza = new FakeLiza();
        $liza->startContent = "найди в базе внутренние регламенты\n\n---\n\n"
            . "**ЧЕРНОВИК ТЕХНИЧЕСКОГО ЗАДАНИЯ**\n\n### 1. Объект испытаний\n**Объект:** изделие\n\n***\n";

        $result = (new CreateTestSpecificationDraft(
            new FakeTechnicalSpecificationDocuments([$this->candidate(11)]),
            $liza,
            new FakeConversations(),
            true,
        ))->execute(7, 3, null);

        self::assertSame(
            "ЧЕРНОВИК ТЕХНИЧЕСКОГО ЗАДАНИЯ\n\n1. Объект испытаний\nОбъект: изделие",
            $result['draft'],
        );
        self::assertStringContainsString('не включай поисковые запросы', $liza->startedPrompts[0]);
    }

    private function candidate(int $id): TechnicalSpecificationCandidate
    {
        return new TechnicalSpecificationCandidate($id, "ТЗ {$id}.pdf", 'application/pdf', 1);
    }
}
