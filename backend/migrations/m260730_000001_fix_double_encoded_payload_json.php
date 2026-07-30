<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * До исправления (см. RequestRepository и др., issue про двойное
 * кодирование payload_json) весь код вставки audit_events.payload_json
 * вручную вызывал json_encode() перед insert(), а Yii2 для колонок типа
 * json дополнительно сам кодирует переданное значение
 * (yii\db\mysql\ColumnSchema::dbTypecast() оборачивает его в
 * JsonExpression) — payload_json хранил JSON-строку внутри JSON-строки и
 * был непригоден для JSON_EXTRACT (валидный JSON-объект/массив даёт тип
 * 'STRING', а не 'OBJECT'/'ARRAY'). Разворачиваем такие записи на один
 * уровень через JSON_UNQUOTE — правильно закодированные строки (уже
 * исправленный код) JSON_TYPE = 'STRING' не дают и не затрагиваются.
 */
final class m260730_000001_fix_double_encoded_payload_json extends Migration
{
    public function safeUp(): void
    {
        $this->execute(
            "UPDATE {{%audit_events}} SET payload_json = JSON_UNQUOTE(payload_json) "
            . "WHERE JSON_TYPE(payload_json) = 'STRING'",
        );
    }

    public function safeDown(): void
    {
        // Необратимо: восстанавливать исходное двойное кодирование
        // бессмысленно (это был баг, а не осознанный формат данных).
        // Падаем явно, а не молча "успешно" ничего не делаем — иначе
        // откат выглядел бы завершённым, оставив данные уже исправленными.
        throw new \RuntimeException(
            static::class . ' is irreversible: restoring the original double-encoded payload_json would be undoing a bug fix, not a data format.',
        );
    }
}
