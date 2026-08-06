<?php

declare(strict_types=1);

namespace App\Infrastructure\Development;

use App\Infrastructure\Document\DocumentStorage;
use Dompdf\Dompdf;
use Dompdf\Options;
use Yii;
use yii\db\Connection;
use ZipArchive;

/** Resets request data and creates a deterministic, entirely synthetic development registry. */
final class DevelopmentRequestSeeder
{
    private const REQUIRED_USERS = [
        'manager' => 'dev.user',
        'executor' => 'dev.executor',
        'executor2' => 'dev.executor.naumov',
        'employee' => 'dev.employee',
        'expert' => 'dev.expert',
        'expert2' => 'dev.expert2',
        'security' => 'dev.security',
        'admin' => 'dev.admin',
    ];

    private const REQUEST_COUNT = 100;

    /** @var list<string> */
    private const INITIATORS = ['employee', 'expert', 'expert2', 'security', 'admin'];

    /** @var list<array{status: string, product: string, manufacturer: string, supplier: string, quantity: int, method: string, color: string}> */
    private const REQUESTS = [
        ['status' => 'registered', 'product' => 'Панель управления лифтом «Вектор»', 'manufacturer' => 'ООО «Учебные системы»', 'supplier' => 'ООО «Демо Комплект»', 'quantity' => 2, 'method' => 'Входной контроль комплектности и маркировки.', 'color' => 'white'],
        ['status' => 'in_progress', 'product' => 'Редуктор главного привода РГП', 'manufacturer' => 'АО «Макет Маш»', 'supplier' => 'ООО «Пробный поставщик»', 'quantity' => 1, 'method' => 'Ресурсные испытания под номинальной нагрузкой.', 'color' => 'green'],
        ['status' => 'suspended', 'product' => 'Канат тяговый КТ-10', 'manufacturer' => 'ООО «Синтетик Канат»', 'supplier' => 'АО «Тест Снаб»', 'quantity' => 5, 'method' => 'Испытание на разрыв и контроль геометрических параметров.', 'color' => 'yellow'],
        ['status' => 'opinion_preparation', 'product' => 'Датчик положения кабины ДПК', 'manufacturer' => 'ООО «Лабораторная автоматика»', 'supplier' => 'ООО «Демо Комплект»', 'quantity' => 3, 'method' => 'Проверка точности позиционирования и климатические испытания.', 'color' => 'blue'],
        ['status' => 'security_review', 'product' => 'Частотный преобразователь ЧП', 'manufacturer' => 'АО «Учебный привод»', 'supplier' => 'ООО «Пробный поставщик»', 'quantity' => 2, 'method' => 'Функциональные испытания и анализ экспертного заключения.', 'color' => 'white'],
        ['status' => 'completed', 'product' => 'Буфер кабины БК-01', 'manufacturer' => 'ООО «Макет Безопасность»', 'supplier' => 'АО «Тест Снаб»', 'quantity' => 2, 'method' => 'Испытание энергорассеивания на демонстрационном стенде.', 'color' => 'green'],
        ['status' => 'rejected', 'product' => 'Замок двери шахты ЗДШ', 'manufacturer' => 'ООО «Учебные системы»', 'supplier' => 'ООО «Синтетик Сервис»', 'quantity' => 4, 'method' => 'Циклические испытания механизма блокировки.', 'color' => 'red'],
        ['status' => 'withdrawn', 'product' => 'Ограничитель скорости ОС', 'manufacturer' => 'АО «Макет Механика»', 'supplier' => 'ООО «Демо Комплект»', 'quantity' => 1, 'method' => 'Проверка скорости срабатывания и возврата механизма.', 'color' => 'white'],
    ];

    /** @var list<array{extension: string, mime: string}> */
    private const ATTACHMENT_FORMATS = [
        ['extension' => 'pdf', 'mime' => 'application/pdf'],
        ['extension' => 'png', 'mime' => 'image/png'],
        ['extension' => 'jpg', 'mime' => 'image/jpeg'],
        ['extension' => 'jpeg', 'mime' => 'image/jpeg'],
        ['extension' => 'docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ['extension' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
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
        $initiatorDepartments = $this->resolveInitiatorDepartments($users);
        $oldKeys = $this->db->createCommand('SELECT storage_key FROM {{%request_document_versions}}')->queryColumn();
        $newKeys = [];
        $counts = ['requests' => 0, 'comments' => 0, 'documents' => 0];
        $transaction = $this->db->beginTransaction();

        try {
            $this->clearRequestData();
            for ($index = 0; $index < self::REQUEST_COUNT; ++$index) {
                $fixture = self::REQUESTS[$index % count(self::REQUESTS)];
                $fixture['age'] = 8 + ($index % 83);
                $initiator = self::INITIATORS[$index % count(self::INITIATORS)];
                $initiatorId = $users[$initiator];
                $requestId = $this->insertRequest($index, $fixture, $initiatorId, $initiatorDepartments[$initiatorId]);
                ++$counts['requests'];
                $counts['comments'] += $this->insertComments($requestId, $index, $fixture['age'], $initiatorId, $users);
                $reportVersionId = null;

                $format = self::ATTACHMENT_FORMATS[($index * 5 + 3) % count(self::ATTACHMENT_FORMATS)];
                $version = $this->insertAttachment(
                    $requestId,
                    'attachment',
                    sprintf('Сопроводительные материалы %03d.%s', $index + 1, $format['extension']),
                    $format['mime'],
                    $initiatorId,
                    $fixture['age'] - 1,
                );
                $newKeys[] = $version['key'];
                ++$counts['documents'];

                if (in_array($fixture['status'], ['opinion_preparation', 'security_review', 'completed'], true)) {
                    $version = $this->insertAttachment(
                        $requestId,
                        'report',
                        sprintf('Отчёт об испытаниях %03d.pdf', $index + 1),
                        'application/pdf',
                        $users['employee'],
                        $fixture['age'] - 2,
                    );
                    $newKeys[] = $version['key'];
                    $reportVersionId = $version['id'];
                    ++$counts['documents'];
                }
                if (in_array($fixture['status'], ['opinion_preparation', 'security_review', 'completed'], true)) {
                    $expert = $index % 2 === 0 ? $users['expert2'] : $users['expert'];
                    $version = $this->insertAttachment($requestId, 'opinion', sprintf('Экспертное заключение %03d.pdf', $index + 1), 'application/pdf', $expert, $fixture['age'] - 4);
                    $newKeys[] = $version['key'];
                    ++$counts['documents'];
                    $opinionId = $this->insertOpinion($requestId, $version['id'], $expert, $fixture['age'] - 4);
                    if ($fixture['status'] !== 'security_review') {
                        $this->insertSecurityCheck($requestId, $opinionId, $users['security'], $fixture['status'] === 'completed' ? 'approve' : 'return', $fixture['age'] - 5);
                    }
                }
                $this->insertWorkflow($requestId, $index, $fixture['status'], $fixture['age'], $users, $reportVersionId);
            }
            $this->db->createCommand()->update('{{%request_number_sequence}}', ['value' => 1000 + self::REQUEST_COUNT], ['id' => 1])->execute();
            $transaction->commit();
        } catch (\Throwable $error) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            foreach ($newKeys as $key) {
                $this->deleteBestEffort($key);
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

    /**
     * @param array<string, int> $users
     * @return array<int, string>
     */
    private function resolveInitiatorDepartments(array $users): array
    {
        $params = [];
        $placeholders = [];
        foreach (self::INITIATORS as $index => $initiator) {
            if (!array_key_exists($initiator, $users)) {
                throw new \RuntimeException("Development initiator '{$initiator}' is not resolved.");
            }
            $placeholder = ':initiator' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $users[$initiator];
        }
        $rows = $this->db->createCommand(
            'SELECT id, NULLIF(TRIM(department), \'\') AS department FROM {{%users}} '
            . 'WHERE id IN (' . implode(', ', $placeholders) . ')',
            $params,
        )->queryAll();
        $departments = [];
        foreach ($rows as $row) {
            if ($row['department'] === null) {
                throw new \RuntimeException("Development initiator '{$row['id']}' has no department.");
            }
            $departments[(int) $row['id']] = (string) $row['department'];
        }
        if (count($departments) !== count(self::INITIATORS)) {
            throw new \RuntimeException('Cannot resolve all development initiator departments.');
        }
        return $departments;
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
     */
    private function insertRequest(int $index, array $fixture, int $initiatorId, string $department): int
    {
        $created = $this->time($fixture['age']);
        $this->db->createCommand()->insert('{{%requests}}', [
            'number' => 1001 + $index, 'legacy_id' => null, 'initiator_id' => $initiatorId,
            'department_name' => $department, 'department_source' => 'current_profile',
            'status' => $fixture['status'], 'product_name' => sprintf('%s — демо-серия %03d', $fixture['product'], $index + 1),
            'manufacturer' => $fixture['manufacturer'], 'supplier' => $fixture['supplier'],
            'sample_quantity' => $fixture['quantity'], 'test_method' => $fixture['method'],
            'revision' => 1, 'lock_version' => 1, 'color' => $fixture['color'],
            'created_at' => $created, 'updated_at' => $this->time(max(0, $fixture['age'] - 1)),
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    /** @param array<string, int> $users */
    private function insertComments(int $requestId, int $index, int $age, int $initiatorId, array $users): int
    {
        $thread = [
            [$initiatorId, 'Демонстрационная заявка создана. Образцы готовы к передаче в ИЦ.'],
            [$users['executor'], 'Программа испытаний согласована. Отчёт будет загружен после завершения испытаний.'],
            [$initiatorId, 'Передали комплект образцов и уточнили маркировку на упаковке. Просьба учесть, что один из образцов предназначен для разрушающего контроля, а остальные необходимо вернуть после завершения работ.'],
            [$users['executor2'], 'Информация принята. В журнале лаборатории зарезервировано оборудование, проверены условия проведения испытаний и доступность измерительного стенда. Если предварительные результаты выйдут за установленные программой пределы, добавим сюда таблицу измерений и отдельно согласуем повторный цикл.'],
        ];
        $comments = array_slice($thread, 0, 1 + ($index % count($thread)));
        foreach ($comments as $offset => [$author, $body]) {
            $this->db->createCommand()->insert('{{%request_comments}}', [
                'request_id' => $requestId, 'author_id' => $author, 'body' => $body,
                'created_at' => $this->time(max(0, $age - $offset)),
            ])->execute();
        }
        return count($comments);
    }

    /** @param array<string, int> $users */
    private function insertWorkflow(int $requestId, int $index, string $status, int $age, array $users, ?int $reportVersionId): void
    {
        $executor = $index % 2 === 0 ? $users['executor2'] : $users['executor'];
        if ($status !== 'registered') {
            $this->assignment($requestId, 'executor', $executor, $users['manager'], $age - 1);
        }
        if (in_array($status, ['opinion_preparation', 'security_review', 'completed'], true)) {
            $this->assignment($requestId, 'expert', $index % 2 === 0 ? $users['expert2'] : $users['expert'], $users['manager'], $age - 3);
        }
        $steps = match ($status) {
            'registered' => [],
            'in_progress' => [['registered', 'in_progress', 'start', null]],
            'suspended' => [['registered', 'in_progress', 'start', null], ['in_progress', 'suspended', 'suspend', 'Ожидается дополнительный образец.']],
            'opinion_preparation' => [
                ['registered', 'in_progress', 'start', null],
                ['in_progress', 'opinion_preparation', 'upload_report', null],
                ['opinion_preparation', 'security_review', 'publish_opinion', null],
                ['security_review', 'opinion_preparation', 'security_return', 'Требуется уточнить вывод экспертного заключения.'],
            ],
            'security_review' => [['registered', 'in_progress', 'start', null], ['in_progress', 'opinion_preparation', 'upload_report', null], ['opinion_preparation', 'security_review', 'publish_opinion', null]],
            'completed' => [['registered', 'in_progress', 'start', null], ['in_progress', 'opinion_preparation', 'upload_report', null], ['opinion_preparation', 'security_review', 'publish_opinion', null], ['security_review', 'completed', 'security_approve', null]],
            'rejected' => [['registered', 'rejected', 'reject', 'Комплект образцов не соответствует условиям приёмки.']],
            default => [['registered', 'withdrawn', 'withdraw', 'Потребность в испытаниях снята инициатором.']],
        };
        foreach ($steps as $offset => [$from, $to, $action, $reason]) {
            $actor = $action === 'reject'
                ? $users['manager']
                : (str_starts_with($action, 'security_')
                    ? $users['security']
                    : ($action === 'publish_opinion' ? $users[$index % 2 === 0 ? 'expert2' : 'expert'] : $executor));
            $this->db->createCommand()->insert('{{%request_transitions}}', [
                'request_id' => $requestId, 'actor_id' => $actor, 'from_status' => $from,
                'to_status' => $to, 'action' => $action, 'reason' => $reason,
                'document_version_id' => $action === 'upload_report' ? $reportVersionId : null,
                'rule_id' => 'DEV-001', 'created_at' => $this->time(max(0, $age - (2 * $offset) - 1)),
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
    private function insertAttachment(int $requestId, string $type, string $name, string $mimeType, int $userId, int $age): array
    {
        $content = $this->documentContent($name, $requestId);
        $temporary = tempnam(sys_get_temp_dir(), 'ic-development-');
        if ($temporary === false || file_put_contents($temporary, $content) === false) {
            throw new \RuntimeException('Cannot create development attachment.');
        }
        try {
            $key = $this->storage->store($temporary);
        } finally {
            if (is_file($temporary)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam created this path
                unlink($temporary);
            }
        }
        try {
            $this->db->createCommand()->insert('{{%request_documents}}', [
                'request_id' => $requestId, 'document_type' => $type, 'title' => $name,
                'created_by' => $userId, 'created_at' => $this->time(max(0, $age)),
            ])->execute();
            $documentId = (int) $this->db->getLastInsertID();
            $this->db->createCommand()->insert('{{%request_document_versions}}', [
                'document_id' => $documentId, 'version' => 1, 'storage_key' => $key,
                'original_name' => $name, 'mime_type' => $mimeType, 'size_bytes' => strlen($content),
                'sha256' => hash('sha256', $content), 'uploaded_by' => $userId,
                'created_at' => $this->time(max(0, $age)),
            ])->execute();
        } catch (\Throwable $error) {
            $this->deleteBestEffort($key);
            throw $error;
        }
        return ['id' => (int) $this->db->getLastInsertID(), 'key' => $key];
    }

    private function documentContent(string $name, int $requestId): string
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        return match ($extension) {
            'pdf' => $this->pdfContent($name, $requestId),
            'png' => (string) base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true,
            ),
            'jpg', 'jpeg' => (string) base64_decode(
                '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EH//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EH//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EH//2Q==',
                true,
            ),
            'docx', 'xlsx' => $this->officeContent($extension, $requestId),
            default => throw new \LogicException("Unsupported development document extension: {$extension}"),
        };
    }

    private function pdfContent(string $title, int $requestId): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pdf->loadHtml(
            '<html lang="ru"><meta charset="utf-8"><body style="font-family:DejaVu Sans,sans-serif">'
            . '<h1>' . $safeTitle . '</h1><p>Синтетический документ к демонстрационной заявке № '
            . $requestId . '.</p><p>Реальные персональные и производственные данные не используются.</p></body></html>',
            'UTF-8',
        );
        $pdf->render();
        return $pdf->output();
    }

    private function officeContent(string $extension, int $requestId): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'ic-development-office-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot create development office document.');
        }
        $archive = new ZipArchive();
        try {
            if ($archive->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot open development office document.');
            }
            if ($extension === 'docx') {
                $archive->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                    . '<Default Extension="xml" ContentType="application/xml"/>'
                    . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                    . '</Types>');
                $archive->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                    . '</Relationships>');
                $archive->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'
                    . 'Синтетические материалы к заявке ' . $requestId
                    . '</w:t></w:r></w:p><w:sectPr/></w:body></w:document>');
            } else {
                $archive->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                    . '<Default Extension="xml" ContentType="application/xml"/>'
                    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                    . '</Types>');
                $archive->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                    . '</Relationships>');
                $archive->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                    . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                    . '<sheets><sheet name="Материалы" sheetId="1" r:id="rId1"/></sheets></workbook>');
                $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                    . '</Relationships>');
                $archive->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">'
                    . '<c r="A1" t="inlineStr"><is><t>Синтетическая заявка ' . $requestId
                    . '</t></is></c></row></sheetData></worksheet>');
            }
            $archive->close();
            $content = file_get_contents($temporary);
            if ($content === false) {
                throw new \RuntimeException('Cannot read development office document.');
            }
            return $content;
        } finally {
            if (is_file($temporary)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam created this path
                unlink($temporary);
            }
        }
    }

    private function deleteBestEffort(string $key): void
    {
        try {
            $this->storage->delete($key);
        } catch (\Throwable $error) {
            Yii::warning([
                'message' => 'Failed to delete a development document during compensating cleanup.',
                'storage_key' => $key,
                'exception' => $error::class,
                'error' => $error->getMessage(),
            ], __METHOD__);
        }
    }

    private function insertOpinion(int $requestId, int $versionId, int $expertId, int $age): int
    {
        $this->db->createCommand()->insert('{{%expert_opinions}}', [
            'request_id' => $requestId, 'revision' => 1, 'expert_id' => $expertId,
            'body' => 'По результатам демонстрационных испытаний образец соответствует требованиям программы.',
            'document_version_id' => $versionId, 'created_at' => $this->time(max(0, $age)),
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    private function insertSecurityCheck(int $requestId, int $opinionId, int $officerId, string $decision, int $age): void
    {
        $this->db->createCommand()->insert('{{%security_checks}}', [
            'request_id' => $requestId, 'expert_opinion_id' => $opinionId, 'officer_id' => $officerId,
            'decision' => $decision, 'reason' => $decision === 'return' ? 'Требуется уточнить вывод экспертного заключения.' : null,
            'created_at' => $this->time(max(0, $age)),
        ])->execute();
    }

    private function time(int $daysAgo): string
    {
        return gmdate('Y-m-d H:i:s', time() - ($daysAgo * 86400));
    }
}
