# Manual de Instalación

Este documento describe los pasos para instalar **Web Codigos 5.0** en un servidor.

## Requisitos previos

- Servidor web con **PHP 8.2** o superior.
- Extensiones PHP necesarias: `session`, `imap`, `mbstring`, `fileinfo`, `json`, `openssl`, `filter`, `ctype`, `iconv` y `curl`.
- Acceso a una base de datos MySQL.
- Permisos de escritura para el directorio `license/` y para `cache/data/`.

## Pasos de instalación

1. **Obtener el código**
   - Clona este repositorio o copia sus archivos al directorio público de tu servidor.

2. **Configurar la base de datos**
   - Define las variables de entorno `DB_HOST`, `DB_USER`, `DB_PASSWORD` y `DB_NAME`.
   - o bien copia `config/db_credentials.sample.php` a `config/db_credentials.php` y edita ese archivo con tus datos.

3. **Ejecutar el instalador**
   - Accede con un navegador a `instalacion/instalador.php`.
   - Ingresa la clave de licencia solicitada.
   - Completa la información de la base de datos y el usuario administrador.
   - Al finalizar, el instalador creará `config/db_credentials.php` (si no existe) y eliminará los archivos temporales de instalación.

4. **Primer acceso**
   - Abre `index.php` y utiliza el usuario administrador creado para ingresar al sistema.

## Reinstalación

Si necesitas reinstalar, borra el registro `INSTALLED` en la tabla `settings` y vuelve a ejecutar `instalacion/instalador.php`.

## Actualización a Telegram ID

Si actualizaste desde una versión anterior que utilizaba el campo `email` en la tabla `users`, ejecuta una vez el script `instalacion/actualizar_telegram.php` tras desplegar el nuevo código.
Este script añadirá el campo `telegram_id` y eliminará `email`.

```bash
php instalacion/actualizar_telegram.php
```

## Migración de columnas de plataformas

En instalaciones existentes que no dispongan de las columnas `logo` y `sort_order` en la tabla `platforms`, ejecuta una de las
siguientes opciones antes de continuar con las pruebas de despliegue:

1. Utiliza el script automático:
   ```bash
   php create_tables.php
   ```
2. O aplica las sentencias SQL manualmente:
   ```sql
   ALTER TABLE platforms ADD COLUMN logo VARCHAR(255) NULL AFTER description;
   ALTER TABLE platforms ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER logo;
   ```

Una vez actualizada la base de datos, ejecuta las pruebas del sistema (por ejemplo `test_web.php`) para validar que la instalación es
correcta.