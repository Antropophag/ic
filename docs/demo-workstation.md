# Развёртывание демо на рабочей Windows-машине

## Рекомендуемый автономный путь

Автономный комплект исключает обращения Composer и npm к интернету на целевой
машине. Это основной вариант при TLS inspection, Kaspersky и ограничениях ИБ.

1. На машине разработчика выполните `make demo-bundle` либо запустите ручной job
   `demo-bundle` в pipeline основной ветки GitLab.
2. Перенесите всю папку `dist-demo` на рабочую машину. В ней должны находиться
   `demo-images.tar`, `SHA256SUMS`, `compose.demo.yaml`, `.env.example` и
   `demo-up.ps1`. Скрипт проверит целостность архива перед загрузкой.
3. Запустите PowerShell от имени администратора:

   ```powershell
   Set-ExecutionPolicy -Scope Process Bypass
   .\demo-up.ps1 -InstallPrerequisites
   ```

4. Перезагрузите Windows. Повторно запустите PowerShell и выполните:

   ```powershell
   Set-ExecutionPolicy -Scope Process Bypass
   .\demo-up.ps1
   ```

Первый этап включает WSL и устанавливает Docker Desktop через `winget`. Второй
запускает Docker Desktop, генерирует `.env`, загружает локальные образы, создаёт
БД и применяет миграции. Composer, PHP, Node.js и npm на Windows не устанавливаются.

## Что потребуется согласовать с ИТ/ИБ

- аппаратная виртуализация должна быть разрешена в BIOS/UEFI;
- компоненты Windows WSL 2 и Virtual Machine Platform не должны блокироваться
  групповой политикой;
- пользователь должен иметь право установить Docker Desktop либо пакет должен
  быть опубликован в корпоративном Software Center;
- лицензия Docker Desktop должна соответствовать политике предприятия;
- локальный порт `8080` должен быть свободен.

Если `winget` запрещён, ИТ устанавливает актуальные WSL 2 и Docker Desktop своим
штатным способом. После этого достаточно выполнить `.\demo-up.ps1` без ключа.

## TLS-сертификаты и Composer

Не используйте `disable-tls`, `secure-http=false` и отключение проверки
сертификатов. Если Composer нужен для разработки, запросите у ИБ корпоративный
корневой сертификат цепочки TLS inspection в PEM/Base64 X.509 и настройте его:

```powershell
composer config --global cafile C:\Certificates\corporate-root.pem
$env:COMPOSER_CAFILE = 'C:\Certificates\corporate-root.pem'
composer diagnose
```

Корневой сертификат также должен быть установлен в доверенные корневые центры
Windows и, если HTTPS перехватывается внутри Docker, добавлен в доверенные CA
Docker Desktop по инструкции вашей ИБ. Сам сертификат не следует коммитить в
репозиторий: он поставляется через защищённый корпоративный канал.

Автономный комплект остаётся предпочтительным: после установки Docker Desktop
приложение разворачивается из локальных образов и не зависит от доступности
Packagist, npm registry или Docker Hub.

## Повторный запуск и остановка

Повторный запуск безопасен: миграции и создание demo-пользователя идемпотентны.

Остановка:

```powershell
docker compose -f compose.demo.yaml down
```

Удаление контейнеров вместе с демонстрационной БД:

```powershell
docker compose -f compose.demo.yaml down --volumes
```

Последняя команда необратимо удаляет локальные демонстрационные данные.
