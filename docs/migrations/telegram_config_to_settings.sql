-- Migrar configuraciones del bot de Telegram a la tabla settings
INSERT INTO settings (name, value, description, category)
SELECT CONCAT('TELEGRAM_', UPPER(setting_name)), setting_value,
       CASE
           WHEN setting_name = 'token' THEN 'Token del bot de Telegram'
           WHEN setting_name = 'webhook' THEN 'URL del webhook de Telegram'
           WHEN setting_name = 'webhook_secret' THEN 'Secreto del webhook de Telegram'
           ELSE setting_name
       END,
       'telegram'
FROM telegram_bot_config
WHERE setting_name IN ('token','webhook','webhook_secret')
ON DUPLICATE KEY UPDATE value = VALUES(value);

DROP TABLE IF EXISTS telegram_bot_config;
