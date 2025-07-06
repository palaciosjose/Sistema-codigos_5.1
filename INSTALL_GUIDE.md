# 🤖 Guía de Instalación del Bot de Telegram

## 🚀 Instalación en Servidor Nuevo (Sin Terminal)

### 1. Subir Archivos
- Subir todos los archivos vía FTP/Panel de Control
- Mantener la estructura de directorios

### 2. Configuración Inicial
- Ir a: `tu_dominio.com/setup_web.php`
- Seguir las instrucciones en pantalla

### 3. Configurar Base de Datos
- Define las variables de entorno `DB_HOST`, `DB_USER`, `DB_PASSWORD` y `DB_NAME`
  o crea `config/db_credentials.php` a partir de `config/db_credentials.sample.php`
  con tus datos de conexión.

### 4. Configurar Bot
- Ir a: `tu_dominio.com/admin/telegram_management.php`
- Configurar token del bot
- Establecer URL del webhook
- Probar conexión

### 5. Verificar Funcionamiento
- Ir a: `tu_dominio.com/test_web.php`
- Verificar que todas las pruebas pasen
- Enviar `/start` al bot en Telegram

## 🔄 Actualización del Sistema

### Si tienes acceso a terminal:
```bash
composer update
```

### Si NO tienes acceso a terminal:
1. Descargar nuevas dependencias localmente
2. Subir directorio `vendor/` actualizado
3. Ejecutar `setup_web.php` nuevamente

## 📁 Archivos Importantes

- `setup_web.php` - Configuración desde navegador
- `test_web.php` - Verificación desde navegador  
- `composer.json` - Configuración de dependencias
- `telegram_bot/webhook.php` - Endpoint del bot
- `admin/telegram_management.php` - Panel de administración

## 🆘 Solución de Problemas

### Bot no responde:
1. Verificar token en panel admin
2. Verificar URL del webhook
3. Revisar logs en `telegram_bot/logs/`

### Errores de clases:
1. Ejecutar `setup_web.php`
2. Verificar permisos de archivos
3. Verificar que vendor/ esté completo

### Error de base de datos:
1. Verificar variables de entorno o `config/db_credentials.php`
2. Verificar que las tablas existan
3. Ejecutar instalador si es necesario

## 📞 Soporte

- Logs del bot: `telegram_bot/logs/bot.log`
- Test de sistema: `test_web.php`
- Panel de admin: `admin/telegram_management.php`
