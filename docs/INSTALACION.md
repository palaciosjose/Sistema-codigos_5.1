# Manual de Instalación

Este documento describe los pasos para instalar **Web Codigos 5.0** en un servidor.

## Requisitos previos

- Servidor web con **PHP 8.2** o superior.
- Extensiones PHP necesarias: `session`, `imap`, `mbstring`, `fileinfo`, `json`, `openssl`, `filter`, `ctype`, `iconv`, `curl` y `mysqlnd`.
- Acceso a una base de datos MySQL.
- Permisos de escritura para el directorio `license/` y para `cache/data/`.
- En futuras versiones se añadirá una lógica de respaldo para servidores sin `mysqlnd`.

## Pasos de instalación

1. **Obtener el código**
   - Clona este repositorio o copia sus archivos al directorio público de tu servidor.

2. **Descargar Composer**
   - Si tu servidor no dispone de Composer, descárgalo desde [getcomposer.org](https://getcomposer.org/download/).
   - Luego ejecuta `composer install` en la raíz del proyecto.

3. **Configurar la base de datos**
   - Define las variables de entorno `DB_HOST`, `DB_USER`, `DB_PASSWORD` y `DB_NAME`.
   - o bien copia `config/db_credentials.sample.php` a `config/db_credentials.php` y edita ese archivo con tus datos.
4. **Ejecutar el instalador**
   - Accede con un navegador a `instalacion/instalador.php`.
   - Ingresa la clave de licencia solicitada.
   - Completa la información de la base de datos y el usuario administrador.
   - Al finalizar, el instalador creará `config/db_credentials.php` (si no existe) y eliminará los archivos temporales de instalación.

5. **Primer acceso**
   - Abre `index.php` y utiliza el usuario administrador creado para ingresar al sistema.

## Reinstalación

Si necesitas reinstalar, borra el registro `INSTALLED` en la tabla `settings` y vuelve a ejecutar `instalacion/instalador.php`.

## Actualización a Telegram ID

Si actualizaste desde una versión anterior que utilizaba el campo `email` en la tabla `users`, ejecuta una vez el script `instalacion/actualizar_telegram.php` tras desplegar el nuevo código.
Este script añadirá el campo `telegram_id` y eliminará `email`.

```bash
php instalacion/actualizar_telegram.php
```
