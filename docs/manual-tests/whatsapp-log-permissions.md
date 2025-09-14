# Manual Test - WhatsApp Log Permissions

This test verifies that `LogService` respects the environment variables for log path and level, and that log files are created with safe permissions.

## Prerequisites
- PHP environment with project dependencies installed (`composer install`).

## Steps
1. Export the desired log path and level:
   ```bash
   export WHATSAPP_LOG_PATH="/tmp/manual-whatsapp.log"
   export WHATSAPP_NEW_LOG_LEVEL="debug"
   ```
2. Generate a log entry:
   ```bash
   php -r "require 'vendor/autoload.php'; (new WhatsappBot\\Services\\LogService())->info('perm test');"
   ```
3. Verify that the log file exists with the expected permissions:
   ```bash
   ls -l /tmp/manual-whatsapp-$(date +%F).log
   ```
4. Confirm the file mode is `-rw-r--r--` (0644) and the contents include `perm test`.

## Cleanup
```bash
rm /tmp/manual-whatsapp-$(date +%F).log
unset WHATSAPP_LOG_PATH WHATSAPP_NEW_LOG_LEVEL
```
