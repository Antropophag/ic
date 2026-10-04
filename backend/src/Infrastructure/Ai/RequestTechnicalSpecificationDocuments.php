<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Ai\TechnicalSpecificationCandidate;
use App\Application\Ai\TechnicalSpecificationDocumentPort;
use App\Application\Ai\TechnicalSpecificationFile;
use App\Application\Ai\TechnicalSpecificationSelector;
use App\Application\Ai\TechnicalSpecificationUnavailable;
use App\Domain\Request\RequestNotFound;
use App\Infrastructure\Document\DocumentRepository;
use App\Infrastructure\Document\DocumentStorage;
use yii\db\Connection;

final readonly class RequestTechnicalSpecificationDocuments implements TechnicalSpecificationDocumentPort
{
    public function __construct(
        private Connection $db,
        private DocumentRepository $repository,
        private DocumentStorage $storage,
        private TechnicalSpecificationSelector $selector = new TechnicalSpecificationSelector(),
    ) {
    }

    public function candidates(int $requestId, int $actorId): array
    {
        $visible = $this->db->createCommand(
            'SELECT 1 FROM {{%requests}} request_item JOIN {{%users}} viewer '
            . 'ON viewer.id = :actor_id AND viewer.is_active = 1 WHERE request_item.id = :request_id',
            [':request_id' => $requestId, ':actor_id' => $actorId],
        )->queryScalar();
        if ($visible === false) {
            throw new RequestNotFound('Request not found');
        }
        $rows = $this->db->createCommand(
            'SELECT v.id AS versionId, v.original_name AS name, v.mime_type AS mimeType, v.version '
            . 'FROM {{%request_documents}} d JOIN {{%request_document_versions}} v ON v.document_id = d.id '
            . 'WHERE d.request_id = :request_id AND d.deleted_at IS NULL AND v.deleted_at IS NULL '
            . 'AND v.version = (SELECT MAX(latest.version) FROM {{%request_document_versions}} latest '
            . 'WHERE latest.document_id = d.id AND latest.deleted_at IS NULL)',
            [':request_id' => $requestId],
        )->queryAll();
        $result = [];
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $mime = (string) $row['mimeType'];
            try {
                $this->repository->findVersionForDownload((int) $row['versionId'], $actorId);
            } catch (RequestNotFound) {
                continue;
            }
            $result[] = new TechnicalSpecificationCandidate((int) $row['versionId'], $name, $mime, (int) $row['version']);
        }
        return $this->selector->select($result);
    }

    public function file(int $requestId, int $versionId, int $actorId): TechnicalSpecificationFile
    {
        try {
            $version = $this->repository->findVersionForDownload($versionId, $actorId);
        } catch (RequestNotFound $error) {
            throw new TechnicalSpecificationUnavailable('Документ недоступен.', previous: $error);
        }
        if ((int) $version['requestId'] !== $requestId) {
            throw new TechnicalSpecificationUnavailable('Документ не относится к выбранной заявке.');
        }
        $path = $this->storage->path((string) $version['storageKey']);
        if (!is_file($path)) {
            throw new TechnicalSpecificationUnavailable('Файл документа недоступен в хранилище.');
        }
        return new TechnicalSpecificationFile(
            (string) $version['originalName'],
            (string) $version['mimeType'],
            $path,
            (int) $version['sizeBytes'],
        );
    }
}
