# Модель данных

Схема строится из реально развёрнутой MariaDB после применения миграций, а не
поддерживается вручную. После изменения миграций выполните
`make schema-diagram`; `make schema-diagram-check` завершится ошибкой, если
диаграмма в Git устарела.

<!-- schema-diagram:start -->

<!-- generated from migrated MariaDB by scripts/gen_schema_diagram.py -->

```mermaid
erDiagram
    migration {
        varchar(180) version PK
        int(11) apply_time
    }
    requests {
        bigint(20)_unsigned id PK
        bigint(20)_unsigned number
        varchar(128) legacy_id
        bigint(20)_unsigned initiator_id FK "-> users.id"
        varchar(32) status
        varchar(500) product_name
        varchar(500) manufacturer
        varchar(500) supplier
        int(11)_unsigned sample_quantity
        text test_method
        int(11)_unsigned revision
        int(11)_unsigned lock_version
        datetime(6) created_at
        datetime(6) updated_at
    }
    request_assignments {
        bigint(20)_unsigned id PK
        bigint(20)_unsigned request_id FK "-> requests.id"
        varchar(16) assignment_type
        bigint(20)_unsigned user_id FK "-> users.id"
        bigint(20)_unsigned assigned_by FK "-> users.id"
        datetime(6) valid_from
        datetime(6) valid_to
    }
    request_number_sequence {
        tinyint(3)_unsigned id PK
        bigint(20)_unsigned value
    }
    request_transitions {
        bigint(20)_unsigned id PK
        bigint(20)_unsigned request_id FK "-> requests.id"
        bigint(20)_unsigned actor_id FK "-> users.id"
        varchar(32) from_status
        varchar(32) to_status
        varchar(32) action
        text reason
        varchar(16) rule_id
        datetime(6) created_at
    }
    users {
        bigint(20)_unsigned id PK
        varchar(128) ad_login
        varchar(255) display_name
        varchar(255) email
        varchar(255) position
        varchar(255) department
        tinyint(1) is_active
        datetime(6) created_at
        datetime(6) updated_at
    }
    users ||--o{ requests : "initiator_id"
    requests ||--o{ request_assignments : "request_id"
    users ||--o{ request_assignments : "user_id"
    users ||--o{ request_assignments : "assigned_by"
    requests ||--o{ request_transitions : "request_id"
    users ||--o{ request_transitions : "actor_id"
```

<!-- schema-diagram:end -->
