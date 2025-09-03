# Manual del Bot de Telegram

## Configuración
1. Copiar `.env.example` a `.env` dentro de `telegram_bot`.
2. Editar el token del bot y la URL del webhook.
3. Ejecutar `php telegram_bot/setup.php` para registrar el webhook.
4. Antes de probar desde el panel de administración, envía `/start` al bot y confirma tu ID numérico de Telegram.

## Comandos Disponibles
- `/start` - Iniciar bot
- `/buscar <email> <plataforma>` - Buscar códigos
- `/codigo <id>` - Obtener código por ID
- `/ayuda` - Mostrar ayuda
- `/stats` - Estadísticas (solo admin)
- `/config` - Ver configuración personal
- `/login` - Iniciar sesión con usuario y contraseña si no tienes telegram_id

La sesión creada por `/login` dura 30 minutos y se renueva con cada interacción del bot.

## Troubleshooting
### Error de webhook
- Verificar URL accesible
- Revisar certificado SSL
- Comprobar token del bot

### Integración con Panel Admin
Desde la pestaña **Bot Telegram** del panel de administración puedes actualizar el token, configurar el webhook y revisar estadísticas sin modificar archivos.

## Actualización desde versiones anteriores

Si tu instalación utiliza la tabla `telegram_bot_config`, ejecuta el script SQL `docs/migrations/telegram_config_to_settings.sql` y asegúrate de configurar el bot desde el panel de administración para que los valores se guarden en `settings`.
