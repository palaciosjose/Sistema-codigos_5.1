<?php
// whatsapp_bot/templates/messages.php
// Todos los mensajes de texto del bot de WhatsApp

return [
    'welcome' => "🤖 *¡Bienvenido al Bot de Códigos en WhatsApp\\!*\n\n" .
                 "Estoy aquí para ayudarte a buscar códigos de verificación\\.\n" .
                 "Envía cualquiera de estos comandos:\n\n" .
                 "🔹 `/login usuario clave` \\- Iniciar sesión\n" .
                 "🔹 `/buscar <email> <plataforma>` \\- Buscar códigos\n" .
                 "🔹 `/codigo <id>` \\- Obtener un código específico\n" .
                 "🔹 `/ayuda` \\- Ver todos los comandos\n\n" .
                 "¡Empecemos\\! 🚀",

    'help' => "📚 *Comandos Disponibles*\n\n" .
              "*Comandos principales:*\n" .
              "• `/start` \\- Mostrar mensaje de bienvenida\n" .
              "• `/login usuario clave` \\- Iniciar sesión\n" .
              "• `/buscar <email> <plataforma>` \\- Buscar códigos\n" .
              "• `/codigo <id>` \\- Obtener código por ID\n" .
              "• `/stats` \\- Ver estadísticas del sistema\n" .
              "• `/ayuda` \\- Mostrar esta ayuda\n\n" .
              "*Ejemplos:*\n" .
              "• `/login miusuario miclave`\n" .
              "• `/buscar usuario@gmail\\.com Netflix`\n" .
              "• `/codigo 12345`\n\n" .
              "💡 *Tip:* Envía los comandos exactamente como se muestran\\!",

    'unauthorized' => "🚫 *Acceso denegado*\n\n" .
                      "Lo siento, no estás autorizado para usar este bot de WhatsApp\\.\n\n" .
                      "Si crees que esto es un error, contacta al administrador del sistema\\.",

    'search_instructions' => "🔍 *Cómo buscar códigos:*\n\n" .
                            "*Formato:* `/buscar <email> <plataforma>`\n\n" .
                            "*Ejemplos:*\n" .
                            "• `/buscar juan@gmail\\.com Netflix`\n" .
                            "• `/buscar maria@hotmail\\.com Amazon`\n" .
                            "• `/buscar carlos@yahoo\\.com PayPal`\n\n" .
                            "*Plataformas disponibles:*\n" .
                            "Netflix, Amazon, PayPal, Steam, Epic Games, Spotify y más\\.\n\n" .
                            "💡 *Tip:* El email debe ser exacto y la plataforma sin espacios\\.",

    'code_instructions' => "🆔 *Obtener código por ID:*\n\n" .
                          "*Formato:* `/codigo <numero_id>`\n\n" .
                          "*Ejemplo:*\n" .
                          "• `/codigo 12345`\n\n" .
                          "El ID lo obtienes cuando realizas una búsqueda exitosa\\.",

    'invalid_format' => "❌ *Formato incorrecto*\n\n" .
                       "Por favor verifica el formato de tu comando\\.\n" .
                       "Envía /ayuda para ver los ejemplos correctos\\.",

    'searching' => "🔍 *Buscando\\.\\.\\.*\n\n" .
                   "Consultando en los servidores\\.\\.\\.\n" .
                   "Esto puede tardar unos segundos\\.",

    'no_results' => "😔 *Sin resultados*\n\n" .
                    "No se encontraron códigos para tu búsqueda\\.\n\n" .
                    "🔹 Verifica que el email sea correcto\n" .
                    "🔹 Asegúrate de que la plataforma esté bien escrita\n" .
                    "🔹 Revisa que tengas permisos para este email",

    'error_generic' => "⚠️ *Error del sistema*\n\n" .
                      "Ha ocurrido un error interno\\.\n" .
                      "Intenta nuevamente en unos minutos\\.\n\n" .
                      "Si el problema persiste, contacta al administrador\\.",

    'rate_limit' => "⏰ *Demasiadas solicitudes*\n\n" .
                   "Has realizado muchas consultas muy rápido\\.\n" .
                   "Espera un momento antes de intentar nuevamente\\.",

    'admin_only' => "👨‍💼 *Solo Administradores*\n\n" .
                   "Este comando está disponible únicamente para administradores del sistema\\.",

    'stats_info' => "📊 *Estadísticas del Sistema*\n\n" .
                   "Vista general del uso del bot y actividad de usuarios\\.",

    'unknown_command' => "Comando no reconocido\\. Envía /ayuda para ver comandos disponibles\\.",
    'usage_search' => "Uso: /buscar <email> <plataforma>",
    'usage_code' => "Uso: /codigo <id_numerico>",
    'error_code' => "Error obteniendo el código\\.",
    'server_error' => "Error interno del servidor\\. Intenta nuevamente\\.",

    'maintenance' => "🔧 *Mantenimiento*\n\n" .
                    "El sistema está en mantenimiento\\.\n" .
                    "Por favor intenta más tarde\\.",

    'success_prefix' => "✅ *¡Éxito\\!*\n\n",
    
    'error_prefix' => "❌ *Error:*\n\n",
    
    'info_prefix' => "ℹ️ *Información:*\n\n",
    
    'warning_prefix' => "⚠️ *Advertencia:*\n\n"
];