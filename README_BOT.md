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

El bot de WhatsApp utiliza las siguientes variables de entorno. Los valores se obtienen desde tu panel de Whaticket:

- **`WHATSAPP_API_URL`**: URL base de la API de Whaticket (por ejemplo `https://midominio.com/api`).
- **`WHATSAPP_API_TOKEN`**: token de acceso generado en Whaticket → Configuración → API.
- **`WHATSAPP_INSTANCE_ID`**: identificador de la instancia visible en Whaticket → Instancias.
- **`WHATSAPP_WEBHOOK_SECRET`**: cadena usada para validar los webhooks recibidos; debe coincidir con el secreto configurado en Whaticket.
- **`WHATSAPP_LOG_LEVEL`**: nivel de registro (`debug`, `info`, `warning`, `error`). Valor por defecto: `info`.

#### Definir variables en el entorno

```bash
export WHATSAPP_API_URL="https://midominio.com/api"
export WHATSAPP_API_TOKEN="token_generado_en_whaticket"
export WHATSAPP_INSTANCE_ID="123"
export WHATSAPP_WEBHOOK_SECRET="secreto_webhook"
```

#### Uso de archivo `.env`

Crea un archivo `.env` en la raíz del proyecto con valores similares a:

```env
# Datos de la base de datos
DB_HOST=localhost
DB_USER=usuario
DB_PASSWORD=contraseña
DB_NAME=nombre_db

# Configuración del Bot de WhatsApp
WHATSAPP_API_URL=https://midominio.com/api
WHATSAPP_API_TOKEN=token_generado_en_whaticket
WHATSAPP_INSTANCE_ID=123
WHATSAPP_WEBHOOK_SECRET=secreto_webhook
WHATSAPP_LOG_LEVEL=info
```

Después de configurar el entorno, ejecuta:

```bash
composer run whatsapp-install
composer run whatsapp-test
```

para completar y verificar la configuración.

