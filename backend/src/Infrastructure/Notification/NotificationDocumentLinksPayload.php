<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

final class NotificationDocumentLinksPayload
{
    /** @return list<array{label: string, documentVersionId: int}> */
    public static function parse(mixed $payload): array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } elseif (!is_array($payload)) {
            throw new InvalidNotificationPayload('Notification payload has an invalid documentLinks collection.');
        }
        if (!is_array($payload) || !isset($payload['documentLinks']) || !is_array($payload['documentLinks'])) {
            throw new InvalidNotificationPayload('Notification payload has an invalid documentLinks collection.');
        }

        $links = [];
        foreach ($payload['documentLinks'] as $link) {
            if (
                !is_array($link)
                || !isset($link['label'], $link['documentVersionId'])
                || !is_string($link['label'])
                || trim($link['label']) === ''
                || !is_int($link['documentVersionId'])
                || $link['documentVersionId'] <= 0
            ) {
                throw new InvalidNotificationPayload('Notification payload contains an invalid document link.');
            }
            $links[] = [
                'label' => $link['label'],
                'documentVersionId' => $link['documentVersionId'],
            ];
        }

        return $links;
    }
}
