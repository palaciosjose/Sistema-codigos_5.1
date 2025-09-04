# Web Codigos 5.0

## Database Configuration

Database credentials are no longer stored in `instalacion/basededatos.php`.
Instead, the application loads them from environment variables or from a
non-tracked file `config/db_credentials.php`.

1. **Using environment variables**: set `DB_HOST`, `DB_USER`, `DB_PASSWORD`
   and `DB_NAME` in your server environment or in a `.env` file.
2. **Using a credentials file**: copy `config/db_credentials.sample.php` to
   `config/db_credentials.php` and fill in your database details. This file is
   ignored by Git so your credentials remain private.

During installation the system will automatically create
`config/db_credentials.php` with the data you provide.

### Configuration resolution

The application uses the `ConfigService` class to read configuration values in
layers. The precedence is:

1. Environment variables (from the process or a `.env` file).
2. Files such as `config/db_credentials.php` or the legacy
   `instalacion/basededatos.php`.
3. Values stored in the `settings` table of the database.
4. Default values provided by the code.

This order allows overriding configuration without modifying source files.

### File locations

- `.env`: place at the project root or in `telegram_bot/.env`. Parsed by
  `config/env_helper.php`.
- `config/db_credentials.php`: main database credentials. Copy from
  `config/db_credentials.sample.php`.
- Legacy `instalacion/basededatos.php`: supported for upgrades but ignored if
  `config/db_credentials.php` exists.

### Example `.env`

```env
DB_HOST=localhost
DB_USER=your_user
DB_PASSWORD=your_password
DB_NAME=your_database
```

### ConfigService usage and cache

Fetch configuration values with:

```php
$service = Shared\ConfigService::getInstance();
$host = $service->get('DB_HOST');
```

`ConfigService` caches results in memory and in `cache/data/settings.json`.
To refresh both caches run:

```bash
php -r "require 'shared/ConfigService.php';\\ Shared\\ConfigService::getInstance()->reload();"
```

To clear all application caches:

```bash
php -r "require 'cache/cache_helper.php';\\ SimpleCache::clear_cache();"
```

### Migration notes

If you are upgrading from a version that stored database credentials in
`instalacion/basededatos.php`, copy those values to `.env` or
`config/db_credentials.php` and remove the old file. After migrating, clear the
cache using the commands above so new settings are picked up.

## Installation

For a step-by-step guide to installing the system, see
[docs/INSTALACION.md](docs/INSTALACION.md).

After cloning the repository run `composer install` to download the PHP
dependencies.

## Jerarquía de carpetas y constantes de ruta

El archivo `config/path_constants.php` define rutas absolutas que el sistema usa
para localizar archivos sin depender del directorio actual. La estructura
esperada del proyecto es:

```
PROJECT_ROOT/
├── admin/           # ADMIN_DIR
├── cache/           # CACHE_DIR
├── config/          # CONFIG_DIR
├── instalacion/     # INSTALL_DIR
├── license/         # LICENSE_DIR
├── security/        # SECURITY_DIR
```

### Constantes definidas

| Constante       | Propósito                                               |
|-----------------|---------------------------------------------------------|
| `PROJECT_ROOT`  | Ruta absoluta a la raíz del proyecto.                   |
| `CONFIG_DIR`    | Archivos de configuración y helpers.                    |
| `ADMIN_DIR`     | Scripts del panel de administración.                    |
| `INSTALL_DIR`   | Instalador y scripts de actualización.                  |
| `LICENSE_DIR`   | Archivos relacionados con la licencia.                  |
| `LICENSE_FILE`  | Archivo `license.dat` dentro de `LICENSE_DIR`.          |
| `SECURITY_DIR`  | Utilidades de seguridad.                                |
| `CACHE_DIR`     | Archivos temporales y de caché.                         |

### Ejemplos de uso

**Entorno web**

Al acceder a `index.php` desde el servidor web:

```php
require_once __DIR__ . '/config/path_constants.php';
require_once PROJECT_ROOT . '/funciones.php';
```

`PROJECT_ROOT` apunta a la raíz del proyecto y permite incluir `funciones.php`
sin importar el directorio actual.

**CLI**

Desde la línea de comandos:

```bash
php create_tables.php
```

Dentro de este script se utiliza:

```php
$envFile = PROJECT_ROOT . '/telegram_bot/.env';
```

`PROJECT_ROOT` facilita localizar el archivo `.env` del bot aunque el comando se
ejecute desde cualquier carpeta.

## License validation

The system verifies its license with the remote server every 24 hours. If the
server responds with an HTTP 4xx code, the license is marked as invalid
immediately and an overlay covers the interface to block access. Network errors
keep the previous validation date so the 76¥2day grace period starts from the last
successful check; once that period ends the overlay also activates and the system
remains blocked until the license is renewed.

Administrators can trigger a manual check by visiting
[manual_license_check.php](manual_license_check.php) or renew the license through
[renovar_licencia.php](renovar_licencia.php).

### Updating existing installations

After updating the license client, run a manual synchronization once so the
server can provide the new `license_type` and `expires_at` fields. You can do

## Development

Before pushing changes, run `composer lint` to ensure all PHP files are free of syntax errors.

this from the admin panel (**Licencia** tab ¡ú **Actualizar Datos de Licencia**)
or by visiting `admin/sync_license.php` directly.

## User Manual

Once installed and logged in, a **Manual** option appears in the navigation bar.
It links to an interactive help page (`manual.php`) with basic usage
instructions. The same content is also available in
[docs/MANUAL_USO.md](docs/MANUAL_USO.md).

## Telegram Bot (Experimental)

A new Telegram bot is being integrated to replicate the web search features. The initial skeleton lives under `telegram_bot/` and requires Composer dependencies. To install them run:

```bash
composer install
```

Configure your bot token in `telegram_bot/config/bot_config.php` and set up the webhook to point to `telegram_bot/webhook.php`.
From version 5.0.1 these valores pueden modificarse desde la pesta0Š9a **Bot Telegram** del panel de administraci¨®n.


Once configured, you can query codes via /codigo <id> or search with /buscar <palabras>. The bot uses the same database as the website, so results are consistent across both platforms.

## Bot de WhatsApp

Este proyecto también integra un bot de WhatsApp que se comunica con Whaticket para ofrecer búsquedas de códigos desde la aplicación de mensajería.

### Campos configurables

La configuración se obtiene de variables de entorno:

- `WHATSAPP_API_URL`: URL base de la API de Whaticket (por ejemplo `https://midominio.com/api`).
- `WHATSAPP_TOKEN`: token generado en Whaticket → **Configuración → API**.
- `WHATSAPP_INSTANCE_ID`: identificador de la instancia visible en **Instancias**.
- `WHATSAPP_WEBHOOK_SECRET`: secreto para validar los webhooks recibidos.
- `WHATSAPP_LOG_LEVEL`: nivel de registro (`debug`, `info`, `warning`, `error`). Valor por defecto: `info`.

Puedes definir estos valores en tu entorno o copiando `.env.example` a `.env` y ajustando los campos anteriores.

### Instalación

1. Configura las variables de entorno mencionadas.
2. Ejecuta `composer run whatsapp-install` para instalar el bot.
3. Ejecuta `composer run whatsapp-test` para verificar la configuración.
4. Configura en Whaticket el webhook apuntando a `whatsapp_bot/webhook.php`.

### Comandos disponibles

El bot responde a los siguientes comandos enviados por chat:

- `/start` – mensaje de bienvenida.
- `/login usuario clave` – inicia sesión con credenciales.
- `/buscar email plataforma` – busca códigos por correo y plataforma.
- `/codigo id` – obtiene un código por su identificador numérico.
- `/stats` – muestra estadísticas del bot.
- `/ayuda` – lista todos los comandos disponibles.

### Ejemplo de uso

```
/login usuario@example.com clave123
/buscar usuario@example.com netflix
```

Los comandos deben enviarse al número de WhatsApp vinculado con tu instancia de Whaticket.
