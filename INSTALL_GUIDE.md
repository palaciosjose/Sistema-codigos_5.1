# �6�10�0�16 Gu���0�9a de Instalaci���0�3n del Bot de Telegram

## �6�10�6�84 Instalaci���0�3n en Servidor Nuevo (Sin Terminal)

### 1. Subir Archivos
- Subir todos los archivos v���0�9a FTP/Panel de Control
- Mantener la estructura de directorios

### 2. Configuraci���0�3n Inicial
- Ir a: `tu_dominio.com/setup_web.php`
- Seguir las instrucciones en pantalla

### 3. Configurar Base de Datos
- Define las variables de entorno `DB_HOST`, `DB_USER`, `DB_PASSWORD` y `DB_NAME`
  o crea `config/db_credentials.php` a partir de `config/db_credentials.sample.php`
### Migracion de columnas
- Ejecutar el script `create_tables.php` para anadir las columnas `logo` y `sort_order` a la tabla `platforms` en instalaciones existentes
- Alternativamente, ejecutar manualmente:
  ```sql
  ALTER TABLE platforms ADD COLUMN logo VARCHAR(255) NULL AFTER description;
  ALTER TABLE platforms ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER logo;
  ```

  con tus datos de conexi���0�3n.

### 4. Configurar Bot
- Ir a: `tu_dominio.com/admin/telegram_management.php`
- Configurar token del bot
- Establecer URL del webhook
- Probar conexi���0�3n

### 5. Verificar Funcionamiento
- Ir a: `tu_dominio.com/test_web.php`
- Verificar que todas las pruebas pasen
- Enviar `/start` al bot en Telegram

## ��9�0�04 Actualizaci���0�3n del Sistema

### Si tienes acceso a terminal:
```bash
composer update
```

### Si NO tienes acceso a terminal:
1. Descargar nuevas dependencias localmente
2. Subir directorio `vendor/` actualizado
3. Ejecutar `setup_web.php` nuevamente

## ��9�0�57 Archivos Importantes

- `setup_web.php` - Configuraci���0�3n desde navegador
- `test_web.php` - Verificaci���0�3n desde navegador  
- `composer.json` - Configuraci���0�3n de dependencias
- `telegram_bot/webhook.php` - Endpoint del bot
- `admin/telegram_management.php` - Panel de administraci���0�3n

## ��9�6�88 Soluci���0�3n de Problemas

### Bot no responde:
1. Verificar token en panel admin
2. Verificar URL del webhook
3. Revisar logs en `telegram_bot/logs/`

### Errores de clases:
1. Ejecutar `setup_web.php`
2. Verificar permisos de archivos
3. Verificar que vendor/ est���0�7 completo

### Error de base de datos:
1. Verificar variables de entorno o `config/db_credentials.php`
2. Verificar que las tablas existan
3. Ejecutar instalador si es necesario

## ��9�0�86 Soporte

- Logs del bot: `telegram_bot/logs/bot.log`
- Test de sistema: `test_web.php`
- Panel de admin: `admin/telegram_management.php`