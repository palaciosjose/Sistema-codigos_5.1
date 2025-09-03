# Buenas prácticas de depuración

- Utiliza `TelegramBot\Services\LogService` para registrar eventos en formato estructurado.
- Los campos sensibles como `username`, `password` y `token` se enmascaran automáticamente.
- Evita enviar credenciales u otros datos personales a `error_log` u otros canales no protegidos.
- Incluye contexto suficiente en los registros para reproducir errores sin exponer información privada.
- Revisa los archivos de log periódicamente y elimina aquellos que ya no sean necesarios.
