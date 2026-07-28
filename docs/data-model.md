# Модель данных

Схема строится из реально развёрнутой MariaDB после применения миграций, а не
поддерживается вручную. После изменения миграций выполните
`make schema-diagram`; `make schema-diagram-check` завершится ошибкой, если
диаграмма в Git устарела.

<!-- schema-diagram:start -->

<!-- generated from migrated MariaDB by scripts/gen_schema_diagram.py -->

```mermaid
erDiagram
    audit_events {
        bigint(20)_unsigned id PK
        varchar(64) event_type
        varchar(64) entity_type
        bigint(20)_unsigned entity_id
        bigint(20)_unsigned actor_id FK "-> users.id"
        varchar(16) rule_id
        longtext payload_json
        datetime(6) created_at
    }
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
    request_comments {
        bigint(20)_unsigned id PK
        bigint(20)_unsigned request_id FK "-> requests.id"
        bigint(20)_unsigned author_id FK "-> users.id"
        text body
        datetime(6) created_at
    }
    request_documents {
        bigint(20)_unsigned id PK
        bigint(20)_unsigned request_id FK "-> requests.id"
        varchar(32) document_type
        varchar(255) title
        bigint(20)_unsigned created_by FK "-> users.id"
        datetime(6) created_at
    }
    request_document_versions {
        bigint(20)_unsigned id PK
        bigint(20)_unsigned document_id FK "-> request_documents.id"
        int(11)_unsigned version
        varchar(80) storage_key
        varchar(255) original_name
        varchar(100) mime_type
        bigint(20)_unsigned size_bytes
        char(64) sha256
        bigint(20)_unsigned uploaded_by FK "-> users.id"
        datetime(6) created_at
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
    roles {
        int(10)_unsigned id PK
        varchar(64) code
        varchar(128) name
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
    user_roles {
        bigint(20)_unsigned user_id PK,FK "-> users.id"
        int(11)_unsigned role_id PK,FK "-> roles.id"
        bigint(20)_unsigned assigned_by FK "-> users.id"
        datetime(6) created_at
    }
    users ||--o{ audit_events : "actor_id"
    users ||--o{ requests : "initiator_id"
    requests ||--o{ request_assignments : "request_id"
    users ||--o{ request_assignments : "user_id"
    users ||--o{ request_assignments : "assigned_by"
    requests ||--o{ request_comments : "request_id"
    users ||--o{ request_comments : "author_id"
    requests ||--o{ request_documents : "request_id"
    users ||--o{ request_documents : "created_by"
    request_documents ||--o{ request_document_versions : "document_id"
    users ||--o{ request_document_versions : "uploaded_by"
    requests ||--o{ request_transitions : "request_id"
    users ||--o{ request_transitions : "actor_id"
    users ||--o{ user_roles : "user_id"
    roles ||--o{ user_roles : "role_id"
    users |o--o{ user_roles : "assigned_by"
```

<!-- schema-diagram:end -->
