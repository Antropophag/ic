<?php

declare(strict_types=1);

// Exact, shrinking debt ledger. Any added, removed, grown, or reduced entry must be reconciled here.
return [
    'dependencies' => [
        'backend/src/Application/Admin/ListAuditEventsInput.php' => ['yii\\base\\Model'],
        'backend/src/Application/Admin/ListNotificationsInput.php' => ['yii\\base\\Model'],
        'backend/src/Application/Document/TestActDocumentService.php' => [
            'App\\Infrastructure\\Document\\TestActDocumentGenerator',
            'yii\\db\\Connection',
        ],
        'backend/src/Application/Document/TestActInput.php' => ['yii\\base\\Model'],
        'backend/src/Application/Identity/AssignRoleInput.php' => ['yii\\base\\Model'],
        'backend/src/Application/Identity/CreateUserInput.php' => ['yii\\base\\Model'],
        'backend/src/Application/Identity/LoginInput.php' => ['yii\\base\\Model'],
        'backend/src/Application/Identity/RevokeRoleInput.php' => ['yii\\base\\Model'],
        'backend/src/Application/Request/ListRequestsInput.php' => ['yii\\base\\Model'],
        'backend/src/Http/Controller/AdminController.php' => [
            'App\\Infrastructure\\Admin\\AuditQuery',
            'App\\Infrastructure\\Admin\\NotificationQuery',
            'App\\Infrastructure\\Admin\\SystemOverviewQuery',
            'App\\Infrastructure\\Clock',
            'App\\Infrastructure\\Document\\DocumentStorage',
            'App\\Infrastructure\\Identity\\CurrentUser',
            'App\\Infrastructure\\Identity\\UserAdministrationRepository',
            'App\\Infrastructure\\Notification\\Mailer',
        ],
        'backend/src/Http/Controller/ApiController.php' => [
            'App\\Infrastructure\\Document\\DocumentStorage',
            'App\\Infrastructure\\Http\\IdempotencyConflict',
            'App\\Infrastructure\\Http\\IdempotencyStore',
            'App\\Infrastructure\\Http\\RequestFingerprint',
            'App\\Infrastructure\\Identity\\CurrentUser',
        ],
        'backend/src/Http/Controller/AuthController.php' => [
            'App\\Infrastructure\\Identity\\AccountDisabled',
            'App\\Infrastructure\\Identity\\AuthenticationDenied',
            'App\\Infrastructure\\Identity\\BreakGlassAuthenticator',
            'App\\Infrastructure\\Identity\\BreakGlassConfiguration',
            'App\\Infrastructure\\Identity\\CurrentUser',
            'App\\Infrastructure\\Identity\\LdapAuthenticator',
            'App\\Infrastructure\\Identity\\LoginAuthenticator',
            'App\\Infrastructure\\Identity\\UserActivityRecorder',
            'App\\Infrastructure\\Ldap\\LdapConnectionException',
            'App\\Infrastructure\\Ldap\\NativeLdapClient',
        ],
        'backend/src/Http/Controller/DevController.php' => [
            'App\\Infrastructure\\Deployment\\DatabasePurpose',
            'App\\Infrastructure\\Development\\DevelopmentRequestSeeder',
            'App\\Infrastructure\\Development\\ReviewFeedbackRepository',
            'App\\Infrastructure\\Document\\DocumentStorage',
            'App\\Infrastructure\\Identity\\CurrentUser',
        ],
        'backend/src/Http/Controller/HealthController.php' => [
            'App\\Infrastructure\\Document\\DocumentStorage',
            'App\\Infrastructure\\Identity\\CurrentUser',
            'App\\Infrastructure\\Identity\\UserAdministrationRepository',
        ],
        'backend/src/Http/Controller/RequestController.php' => [
            'App\\Infrastructure\\Document\\DocumentRepository',
            'App\\Infrastructure\\Document\\DocumentStorage',
            'App\\Infrastructure\\Document\\OfficeDocumentInspector',
            'App\\Infrastructure\\Document\\TestActDocumentGenerator',
            'App\\Infrastructure\\Identity\\CurrentUser',
            'App\\Infrastructure\\Request\\RequestQuery',
        ],
    ],
    'files' => [
        'backend/src/Http/Controller/RequestController.php' => 856,
        'backend/src/Infrastructure/Request/RequestQuery.php' => 617,
        'frontend/src/components/RequestRegistry.vue' => 847,
    ],
    'methods' => [
        'backend/src/Infrastructure/Bitrix/BitrixSnapshotExporter.php' => [
            'BitrixSnapshotExporter::export' => 116,
        ],
        'backend/src/Infrastructure/Development/DevelopmentRequestSeeder.php' => [
            'DevelopmentRequestSeeder::seed' => 83,
        ],
        'backend/src/Infrastructure/Document/TestActDocumentTemplate.php' => [
            'TestActDocumentTemplate::build' => 123,
        ],
        'backend/src/Infrastructure/Http/IdempotencyStore.php' => [
            'IdempotencyStore::execute' => 130,
        ],
        'backend/src/Infrastructure/Import/DatabaseLegacyRequestWriter.php' => [
            'DatabaseLegacyRequestWriter::write' => 85,
        ],
        'backend/src/Infrastructure/Request/RequestQuery.php' => [
            'RequestQuery::findDetails' => 260,
            'RequestQuery::findPage' => 230,
        ],
    ],
];
