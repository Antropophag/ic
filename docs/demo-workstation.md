# Развёртывание демо на рабочей Windows-машине

## Быстрый путь: клон с GitHub (если интернет на машине есть)

Проще всего, если на целевой машине нет ограничений на исходящий доступ —
тогда `demo-bundle` не нужен вовсе, `demo-up.ps1` сам соберёт образы из
исходников при первом запуске. Помимо GitHub и Docker Hub (сам код и базовые
образы), при установке предпосылок и онлайн-сборке потребуется доступ к
источникам `winget`/Microsoft, реестрам Composer (Packagist), npm и зеркалам
Alpine внутри Docker-сборки — если исходящий трафик на машине по allowlist,
согласуйте с ИТ/ИБ полный список, а не только GitHub/Docker Hub.

1. **Получите код.** Репозиторий приватный, нужен доступ на GitHub.
   Самый простой способ без установки Git — скачать ZIP:
   `github.com/Antropophag/ic` → кнопка **Code → Download ZIP**, распакуйте в
   любую папку. Если хотите потом обновляться через `git pull`, вместо этого
   поставьте Git: `winget install --id Git.Git -e`, затем
   `git clone https://github.com/Antropophag/ic.git`.
2. Откройте PowerShell **от имени администратора** в папке проекта и
   выполните:

   ```powershell
   Set-ExecutionPolicy -Scope Process Bypass
   .\scripts\demo-up.ps1 -InstallPrerequisites
   ```

   Это поставит WSL 2 и Docker Desktop через `winget`, если их ещё нет.
3. **Перезагрузите Windows** — обязательный шаг после установки WSL.
4. Откройте обычный (не обязательно администраторский) PowerShell в той же
   папке и выполните:

   ```powershell
   Set-ExecutionPolicy -Scope Process Bypass
   .\scripts\demo-up.ps1
   ```

   Автономного бандла в папке нет, поэтому скрипт соберёт образы из
   исходников (`docker compose up --build`), применит миграции, наполнит
   demo-пользователей и дождётся готовности.
5. Откройте `http://localhost:8080`.

Если Docker Desktop уже стоит — шаги 2–3 не нужны, сразу переходите к шагу 4.
Повторный запуск `.\scripts\demo-up.ps1` безопасен (миграции и seed
идемпотентны).

Остановка (без бандла запуск шёл через `compose.yaml`, а не
`compose.demo.yaml`):

```powershell
docker compose -f compose.yaml down
```

Удаление контейнеров вместе с демонстрационной БД (необратимо):

```powershell
docker compose -f compose.yaml down --volumes
```

Если на машине действует TLS inspection/прокси/allowlist и онлайн-сборка
образов падает на `docker compose build` — используйте автономный путь ниже,
он не обращается в Packagist/npm/Docker Hub с целевой машины вовсе.

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

## Повторный запуск и остановка (автономный комплект)

Команды ниже — для развёртывания из `dist-demo` (`compose.demo.yaml`). Для
быстрого пути с клоном с GitHub команды остановки — в конце раздела «Быстрый
путь» выше (`compose.yaml`).

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
