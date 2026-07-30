<?php

declare(strict_types=1);

namespace App\Infrastructure\Development;

use App\Infrastructure\Document\DocumentStorage;
use yii\db\Connection;

/** Resets request data and creates a deterministic, entirely synthetic demo registry. */
final class DemoRequestSeeder
{
    private const REQUIRED_USERS = [
        'manager' => 'dev.user',
        'executor' => 'dev.executor',
        'executor2' => 'dev.executor.naumov',
        'employee' => 'dev.employee',
        'expert' => 'dev.expert',
        'expert2' => 'dev.expert2',
        'security' => 'dev.security',
    ];

    /** @var list<array{status: string, product: string, manufacturer: string, supplier: string, quantity: int, method: string, color: string, age: int}> */
    private const REQUESTS = [
        ['status' => 'registered', 'product' => 'Панель управления лифтом «Вектор-Демо»', 'manufacturer' => 'ООО «Учебные системы»', 'supplier' => 'ООО «Демо Комплект»', 'quantity' => 2, 'method' => 'Входной контроль, проверка комплектности и маркировки.', 'color' => 'white', 'age' => 2],
        ['status' => 'in_progress', 'product' => 'Редуктор главного привода РГП-Д', 'manufacturer' => 'АО «Макет Маш»', 'supplier' => 'ООО «Пробный поставщик»', 'quantity' => 1, 'method' => 'Ресурсные испытания под номинальной нагрузкой.', 'color' => 'green', 'age' => 8],
        ['status' => 'suspended', 'product' => 'Канат тяговый КТ-10 (демо)', 'manufacturer' => 'ООО «Синтетик Канат»', 'supplier' => 'АО «Тест Снаб»', 'quantity' => 5, 'method' => 'Испытание на разрыв; контроль геометрических параметров.', 'color' => 'yellow', 'age' => 14],
        ['status' => 'opinion_preparation', 'product' => 'Датчик положения кабины ДПК-М', 'manufacturer' => 'ООО «Лабораторная автоматика»', 'supplier' => 'ООО «Демо Комплект»', 'quantity' => 3, 'method' => 'Проверка точности позиционирования и климатические испытания.', 'color' => 'blue', 'age' => 21],
        ['status' => 'security_review', 'product' => 'Частотный преобразователь ЧП-Демо', 'manufacturer' => 'АО «Учебный привод»', 'supplier' => 'ООО «Пробный поставщик»', 'quantity' => 2, 'method' => 'Функциональные испытания и анализ экспертного заключения.', 'color' => 'white', 'age' => 30],
        ['status' => 'completed', 'product' => 'Буфер кабины БК-01Д', 'manufacturer' => 'ООО «Макет Безопасность»', 'supplier' => 'АО «Тест Снаб»', 'quantity' => 2, 'method' => 'Испытание энергорассеивания по программе демо-стенда.', 'color' => 'green', 'age' => 45],
        ['status' => 'rejected', 'product' => 'Замок двери шахты ЗДШ-Д', 'manufacturer' => 'ООО «Учебные системы»', 'supplier' => 'ООО «Синтетик Сервис»', 'quantity' => 4, 'method' => 'Циклические испытания механизма блокировки.', 'color' => 'red', 'age' => 60],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly DocumentStorage $storage,
    ) {
    }

    /** @return array{requests: int, comments: int, documents: int} */
    public function seed(): array
    {
        $users = $this->resolveUsers();
        $oldKeys = $this->db->createCommand('SELECT storage_key FROM {{%request_document_versions}}')->queryColumn();
        $newKeys = [];
        $counts = ['requests' => 0, 'comments' => 0, 'documents' => 0];
        $transaction = $this->db->beginTransaction();

        try {
            $this->clearRequestData();
            foreach (self::REQUESTS as $index => $fixture) {
                $requestId = $this->insertRequest($index, $fixture, $users);
                ++$counts['requests'];
                $counts['comments'] += $this->insertComments($requestId, $index, $fixture['age'], $users);
                $this->insertWorkflow($requestId, $index, $fixture['status'], $fixture['age'], $users);

                if ($index >= 1) {
                    $documentType = $index >= 3 ? 'report' : 'attachment';
                    $documentName = $index >= 3 ? 'Отчёт об испытаниях.txt' : 'Программа испытаний.txt';
                    $newKeys[] = $this->insertAttachment($requestId, $documentType, $documentName, $users['employee'], $fixture['age'] - 1);
                    ++$counts['documents'];
                }
                if ($index >= 3 && $index <= 5) {
                    $version = $this->insertAttachment($requestId, 'opinion', 'Экспертное заключение.txt', $users[$index === 4 ? 'expert2' : 'expert'], $fixture['age'] - 4);
                    $newKeys[] = $version['key'];
                    ++$counts['documents'];
                    $opinionId = $this->insertOpinion($requestId, $version['id'], $users[$index === 4 ? 'expert2' : 'expert'], $fixture['age'] - 4);
                    if ($index === 3 || $index === 5) {
                        $this->insertSecurityCheck($requestId, $opinionId, $users['security'], $index === 5 ? 'approve' : 'return', $fixture['age'] - 5);
                    }
                }
            }
            $this->db->createCommand()->update('{{%request_number_sequence}}', ['value' => 1007], ['id' => 1])->execute();
            $transaction->commit();
        } catch (\Throwable $error) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            foreach ($newKeys as $item) {
                $this->storage->delete(is_array($item) ? $item['key'] : $item);
            }
            throw $error;
        }

        foreach ($oldKeys as $key) {
            $this->storage->delete((string) $key);
        }
        return $counts;
    }

    /** @return array<string, int> */
    private function resolveUsers(): array
    {
        $params = [];
        $placeholders = [];
        foreach (array_values(self::REQUIRED_USERS) as $index => $login) {
            $placeholder = ':login' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $login;
        }
        $rows = $this->db->createCommand(
            'SELECT id, ad_login FROM {{%users}} WHERE ad_login IN (' . implode(', ', $placeholders) . ')',
            $params,
        )->queryAll();
        $ids = array_column($rows, 'id', 'ad_login');
        $result = [];
        foreach (self::REQUIRED_USERS as $name => $login) {
            if (!isset($ids[$login])) {
                throw new \RuntimeException("Development user '{$login}' is missing. Run dev/seed first.");
            }
            $result[$name] = (int) $ids[$login];
        }
        return $result;
    }

    private function clearRequestData(): void
    {
        $this->db->createCommand("DELETE FROM {{%audit_events}} WHERE entity_type = 'request'")->execute();
        $this->db->createCommand()->delete('{{%security_checks}}')->execute();
        $this->db->createCommand()->delete('{{%expert_opinions}}')->execute();
        $this->db->createCommand()->delete('{{%requests}}')->execute();
    }

    /**
     * @param array<string, mixed> $fixture
     * @param array<string, int> $users
     */
    private function insertRequest(int $index, array $fixture, array $users): int
    {
        $created = $this->time($fixture['age']);
        $this->db->createCommand()->insert('{{%requests}}', [
            'number' => 1001 + $index, 'legacy_id' => null, 'initiator_id' => $users['employee'],
            'status' => $fixture['status'], 'product_name' => $fixture['product'],
            'manufacturer' => $fixture['manufacturer'], 'supplier' => $fixture['supplier'],
            'sample_quantity' => $fixture['quantity'], 'test_method' => $fixture['method'],
            'revision' => 1, 'lock_version' => 1, 'color' => $fixture['color'],
            'created_at' => $created, 'updated_at' => $this->time(max(0, $fixture['age'] - 1)),
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    /** @param array<string, int> $users */
    private function insertComments(int $requestId, int $index, int $age, array $users): int
    {
        $comments = [[ $users['employee'], 'Демо-заявка создана, образцы готовы к передаче в ИЦ.' ]];
        if ($index > 0) {
            $comments[] = [$users['executor'], 'Программа испытаний согласована, результаты будут добавлены в карточку.'];
        }
        foreach ($comments as $offset => [$author, $body]) {
            $this->db->createCommand()->insert('{{%request_comments}}', [
                'request_id' => $requestId, 'author_id' => $author, 'body' => $body,
                'created_at' => $this->time(max(0, $age - $offset)),
            ])->execute();
        }
        return count($comments);
    }

    /** @param array<string, int> $users */
    private function insertWorkflow(int $requestId, int $index, string $status, int $age, array $users): void
    {
        $executor = $index % 2 === 0 ? $users['executor2'] : $users['executor'];
        if ($status !== 'registered') {
            $this->assignment($requestId, 'executor', $executor, $users['manager'], $age - 1);
        }
        if (in_array($status, ['opinion_preparation', 'security_review', 'completed'], true)) {
            $this->assignment($requestId, 'expert', $index === 4 ? $users['expert2'] : $users['expert'], $users['manager'], $age - 3);
        }
        $steps = match ($status) {
            'registered' => [],
            'in_progress' => [['registered', 'in_progress', 'start', null]],
            'suspended' => [['registered', 'in_progress', 'start', null], ['in_progress', 'suspended', 'suspend', 'Ожидается дополнительный образец.']],
            'opinion_preparation' => [
                ['registered', 'in_progress', 'start', null],
                ['in_progress', 'opinion_preparation', 'upload_report', null],
                ['opinion_preparation', 'security_review', 'publish_opinion', null],
                ['security_review', 'opinion_preparation', 'security_return', 'Демонстрационный возврат на уточнение.'],
            ],
            'security_review' => [['registered', 'in_progress', 'start', null], ['in_progress', 'opinion_preparation', 'upload_report', null], ['opinion_preparation', 'security_review', 'publish_opinion', null]],
            'completed' => [['registered', 'in_progress', 'start', null], ['in_progress', 'opinion_preparation', 'upload_report', null], ['opinion_preparation', 'security_review', 'publish_opinion', null], ['security_review', 'completed', 'security_approve', null]],
            default => [['registered', 'rejected', 'reject', 'Комплект образцов не соответствует условиям приёмки.']],
        };
        foreach ($steps as $offset => [$from, $to, $action, $reason]) {
            $actor = $action === 'reject'
                ? $users['manager']
                : (str_starts_with($action, 'security_')
                    ? $users['security']
                    : ($action === 'publish_opinion' ? $users[$index === 4 ? 'expert2' : 'expert'] : $executor));
            $this->db->createCommand()->insert('{{%request_transitions}}', [
                'request_id' => $requestId, 'actor_id' => $actor, 'from_status' => $from,
                'to_status' => $to, 'action' => $action, 'reason' => $reason,
                'rule_id' => 'DEV-001', 'created_at' => $this->time(max(0, $age - $offset - 1)),
            ])->execute();
        }
    }

    private function assignment(int $requestId, string $type, int $userId, int $authorId, int $age): void
    {
        $this->db->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId, 'assignment_type' => $type, 'user_id' => $userId,
            'assigned_by' => $authorId, 'valid_from' => $this->time(max(0, $age)), 'valid_to' => null,
        ])->execute();
    }

    /** @return array{id: int, key: string} */
    private function insertAttachment(int $requestId, string $type, string $name, int $userId, int $age): array
    {
        $content = "Синтетический демонстрационный документ\nЗаявка: {$requestId}\nРеальные данные не используются.\n";
        $temporary = tempnam(sys_get_temp_dir(), 'ic-demo-');
        if ($temporary === false || file_put_contents($temporary, $content) === false) {
            throw new \RuntimeException('Cannot create demo attachment.');
        }
        try {
            $key = $this->storage->store($temporary);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        $this->db->createCommand()->insert('{{%request_documents}}', [
            'request_id' => $requestId, 'document_type' => $type, 'title' => $name,
            'created_by' => $userId, 'created_at' => $this->time(max(0, $age)),
        ])->execute();
        $documentId = (int) $this->db->getLastInsertID();
        $this->db->createCommand()->insert('{{%request_document_versions}}', [
            'document_id' => $documentId, 'version' => 1, 'storage_key' => $key,
            'original_name' => $name, 'mime_type' => 'text/plain', 'size_bytes' => strlen($content),
            'sha256' => hash('sha256', $content), 'uploaded_by' => $userId,
            'created_at' => $this->time(max(0, $age)),
        ])->execute();
        return ['id' => (int) $this->db->getLastInsertID(), 'key' => $key];
    }

    private function insertOpinion(int $requestId, int $versionId, int $expertId, int $age): int
    {
        $this->db->createCommand()->insert('{{%expert_opinions}}', [
            'request_id' => $requestId, 'revision' => 1, 'expert_id' => $expertId,
            'body' => 'Синтетическое заключение: образец соответствует демонстрационной программе испытаний.',
            'document_version_id' => $versionId, 'created_at' => $this->time(max(0, $age)),
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    private function insertSecurityCheck(int $requestId, int $opinionId, int $officerId, string $decision, int $age): void
    {
        $this->db->createCommand()->insert('{{%security_checks}}', [
            'request_id' => $requestId, 'expert_opinion_id' => $opinionId, 'officer_id' => $officerId,
            'decision' => $decision, 'reason' => $decision === 'return' ? 'Демонстрационный возврат на уточнение.' : null,
            'created_at' => $this->time(max(0, $age)),
        ])->execute();
    }

    private function time(int $daysAgo): string
    {
        return gmdate('Y-m-d H:i:s', time() - ($daysAgo * 86400));
    }
}
