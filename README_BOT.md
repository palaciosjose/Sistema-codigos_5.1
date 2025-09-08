# 🤖 Bot de Telegram - Configuración Completa

Este bot está configurado para ser compatible con futuras instalaciones y actualizaciones.

## ✅ Características

- ✅ Instalación automática desde navegador
- ✅ Compatible con hosting sin terminal
- ✅ Autoloader robusto que no se rompe
- ✅ Scripts de verificación incluidos
- ✅ Documentación completa

## 🔧 Archivos de Configuración

### Archivos Web (Sin Terminal)
- `setup_web.php` - Configuración inicial
- `test_web.php` - Verificación del sistema
- `integration_panel.html` - Panel de integración

### Archivos de Composer
- `composer.json` - Configuración actualizada
- `telegram_bot/Scripts/PostInstallScript.php` - Post-instalación automática

## 🎯 Compatible Con

- ✅ Hosting compartido
- ✅ VPS sin acceso SSH
- ✅ Servidores con solo FTP
- ✅ Panels de control web
- ✅ Actualizaciones de Composer

## 📋 Estado del Sistema

El sistema ha sido integrado exitosamente y es compatible con futuras instalaciones.

## 📱 Bot de WhatsApp

El proyecto incluye scripts de Composer para instalar y probar el bot de WhatsApp:

- `composer run whatsapp-install` - ejecuta `setup_whatsapp_web.php` para configurar el bot.
- `composer run whatsapp-test` - ejecuta `test_whatsapp_web.php` para verificar la instalación.

### Variables de entorno

El bot de WhatsApp utiliza las siguientes variables de entorno. Los valores se obtienen desde tu panel de Wamundo:

- **`WHATSAPP_NEW_API_URL`**: URL base de la API de Wamundo (por ejemplo `https://wamundo.com/api`).
- **`WHATSAPP_NEW_SEND_SECRET`**: secreto utilizado para enviar mensajes mediante WamBot.
- **`WHATSAPP_NEW_ACCOUNT_ID`**: identificador de la cuenta en Wamundo.
- **`WHATSAPP_NEW_WEBHOOK_SECRET`**: cadena usada para validar los webhooks recibidos; debe coincidir con el secreto configurado en Wamundo.
- **`WHATSAPP_NEW_LOG_LEVEL`**: nivel de registro (`debug`, `info`, `warning`, `error`). Valor por defecto: `info`.
- **`WHATSAPP_NEW_API_TIMEOUT`**: tiempo máximo de espera en segundos para llamadas a la API. Valor por defecto: `30`.

Los valores sensibles como `WHATSAPP_NEW_SEND_SECRET` y `WHATSAPP_NEW_WEBHOOK_SECRET` se almacenan cifrados cuando son guardados mediante el `ConfigService`.

#### Formato de `whatsapp_id`
El campo `whatsapp_id` debe guardarse como número completo con código de país y sin sufijos como `@c.us` (ejemplo: `521234567890`).

#### Definir variables en el entorno

```bash
 export WHATSAPP_NEW_API_URL="https://wamundo.com/api"
 export WHATSAPP_NEW_SEND_SECRET="tu_send_secret"
 export WHATSAPP_NEW_ACCOUNT_ID="123"
 export WHATSAPP_NEW_WEBHOOK_SECRET="secreto_webhook"
 export WHATSAPP_NEW_LOG_LEVEL="info"
 export WHATSAPP_NEW_API_TIMEOUT="30"
 export WHATSAPP_ACTIVE_WEBHOOK="wamundo"
```

#### Uso de archivo `.env`

Copia el archivo `.env.example` a `.env` en la raíz del proyecto y personaliza los valores:

```bash
cp .env.example .env
```

Ejemplo de contenido de `.env`:

```env
# Datos de la base de datos
DB_HOST=localhost
DB_USER=usuario
DB_PASSWORD=contraseña
DB_NAME=nombre_db

# Configuración del Bot de WhatsApp
WHATSAPP_NEW_API_URL=https://wamundo.com/api
WHATSAPP_NEW_SEND_SECRET=tu_send_secret
WHATSAPP_NEW_ACCOUNT_ID=123
WHATSAPP_NEW_WEBHOOK_SECRET=secreto_webhook
WHATSAPP_NEW_LOG_LEVEL=info
WHATSAPP_NEW_API_TIMEOUT=30
WHATSAPP_ACTIVE_WEBHOOK=wamundo
```

Después de configurar el entorno, ejecuta:

```bash
composer run whatsapp-install
composer run whatsapp-test
```

para completar y verificar la configuración.

