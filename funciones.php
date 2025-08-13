<?php
/**
 * Sistema de Consulta de Códigos por Email - Funciones Optimizadas
 * Versión: 2.2 - Corrección de Consistencia de Resultados
 * CAMBIO PRINCIPAL: Elimina contradicción entre "¡Éxito!" y "0 mensajes encontrados"
 */

// Inicializar sesión de forma segura
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== INTEGRACIÓN DEL SISTEMA DE LICENCIAS CORREGIDA =====
require_once __DIR__ . '/license_client.php';

// Verificar licencia automáticamente (excepto en instalador)
if (!defined('INSTALLER_MODE')) {
    try {
        $license_client = new ClientLicense();
        $status = $license_client->getLicenseStatus();

        if ($status['status'] === 'network_error' && ($status['grace_remaining'] ?? 0) > 0) {
            showLicenseError($status);
        }

        if (!$license_client->isLicenseValid()) {
            showLicenseError($status);
        }
    } catch (Exception $e) {
        error_log("Error verificando licencia: " . $e->getMessage());
        showLicenseError();
    }
}

function showLicenseError($status = null) {
    $license_client = new ClientLicense();
    if ($status === null) {
        $status = $license_client->getLicenseStatus();
    }
    $diagnostic_info = $license_client->getDiagnosticInfo();

    if ($status['status'] === 'network_error' && ($status['grace_remaining'] ?? 0) > 0) {
        $days = ceil($status['grace_remaining'] / 86400);
        echo '<div style="background:#fff3cd;border:1px solid #ffeeba;padding:10px;text-align:center;">Acceso temporal por ' . $days . ' día' . ($days !== 1 ? 's' : '') . ' restantes</div>';
        return;
    }

    // Verificar si el sistema está instalado
    $system_installed = is_installed();

    // Limpiar cualquier salida previa
    if (ob_get_level()) {
        ob_end_clean();
    }

    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/html; charset=utf-8');

    $status_messages = [
        'expired' => 'Licencia expirada',
        'invalid' => 'Licencia inválida o no encontrada',
        'network_error' => 'Error de red al verificar la licencia',
        'server_unreachable' => 'Servidor de licencias no alcanzable'
    ];
    $status_message = $status_messages[$status['status']] ?? 'Licencia no válida';

    echo '<!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Licencia Requerida</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .license-error-container {
                background: white;
                border-radius: 15px;
                padding: 2rem;
                max-width: 600px;
                box-shadow: 0 20px 40px rgba(0,0,0,0.1);
                text-align: center;
            }
            .license-icon {
                font-size: 4rem;
                color: #dc3545;
                margin-bottom: 1rem;
            }
            .diagnostic-info {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 1rem;
                margin-top: 1.5rem;
                text-align: left;
                font-family: monospace;
                font-size: 0.9rem;
            }
            .btn-renew {
                background: linear-gradient(45deg, #28a745, #20c997);
                border: none;
                color: white;
                font-weight: 600;
                padding: 12px 30px;
                border-radius: 8px;
                transition: all 0.3s ease;
            }
            .btn-renew:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
                color: white;
            }
            .btn-install {
                background: linear-gradient(45deg, #007bff, #0056b3);
                border: none;
                color: white;
                font-weight: 600;
                padding: 12px 30px;
                border-radius: 8px;
                transition: all 0.3s ease;
            }
            .btn-install:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0, 123, 255, 0.4);
                color: white;
            }
        </style>
    </head>
    <body>
        <div class="license-error-container">
            <div class="license-icon">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h1 class="h3 mb-3">Licencia Requerida</h1>
            <p class="text-muted mb-4">
                Este software requiere una licencia válida para funcionar correctamente.
            </p>

            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Estado:</strong> ' . $status_message . '
            </div>';

    if ($system_installed) {
        switch ($status['status']) {
            case 'expired':
                echo '
            <div class="mb-4">
                <p class="mb-3">La licencia ha expirado. Puede verificarla manualmente o renovarla:</p>
                <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                    <a href="manual_license_check.php" class="btn btn-install btn-lg me-md-2">
                        <i class="fas fa-sync-alt me-2"></i>Verificar Licencia Manualmente
                    </a>
                    <a href="renovar_licencia.php" class="btn btn-renew btn-lg">
                        <i class="fas fa-credit-card me-2"></i>Renovar Licencia
                    </a>
                </div>
            </div>';
                break;
            case 'network_error':
            case 'server_unreachable':
                $grace_label = '';
                if (!empty($status['grace_remaining'])) {
                    $days = floor($status['grace_remaining'] / 86400);
                    $hours = floor(($status['grace_remaining'] % 86400) / 3600);
                    $parts = [];
                    if ($days > 0) { $parts[] = $days . ' día' . ($days > 1 ? 's' : ''); }
                    if ($hours > 0) { $parts[] = $hours . ' hora' . ($hours > 1 ? 's' : ''); }
                    $grace_label = 'Tiempo restante de gracia: ' . implode(' ', $parts) . '.';
                }
                echo '
            <div class="mb-4">
                <p class="mb-3">No se pudo contactar al servidor de licencias. ' . $grace_label . '</p>
                <div id="verification-status" class="d-flex align-items-center justify-content-center mb-3">
                    <div class="spinner-border text-primary me-2" role="status" style="display:none;"></div>
                    <span id="status-text">Esperando reintento...</span>
                </div>
                <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                    <button id="retry-btn" class="btn btn-install btn-lg me-md-2">
                        <i class="fas fa-sync-alt me-2"></i>Reintentar Verificación
                    </button>
                </div>
            </div>';
                break;
            case 'invalid':
            default:
                echo '
            <div class="mb-4">
                <p class="mb-3">La licencia es inválida o no se encontró. Configure una nueva licencia o contacte soporte:</p>
                <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                    <a href="renovar_licencia.php" class="btn btn-install btn-lg me-md-2">
                        <i class="fas fa-key me-2"></i>Configurar Nueva Licencia
                    </a>
                    <a href="mailto:soporte@tudominio.com" class="btn btn-outline-secondary btn-lg">
                        <i class="fas fa-envelope me-2"></i>Contactar Soporte
                    </a>
                </div>
            </div>';
                break;
        }
    } else {
        echo '
            <div class="alert alert-warning" role="alert">
                <i class="fas fa-tools me-2"></i>
                El sistema no está instalado completamente.
            </div>

            <div class="mb-4">
                <p class="mb-3">Para comenzar a usar el sistema, debe completar la instalación:</p>
                <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                    <a href="instalacion/instalador.php" class="btn btn-install btn-lg me-md-2">
                        <i class="fas fa-download me-2"></i>Ir al Instalador
                    </a>
                    <a href="mailto:soporte@tudominio.com" class="btn btn-outline-secondary btn-lg">
                        <i class="fas fa-envelope me-2"></i>Contactar Soporte
                    </a>
                </div>
            </div>';
    }

    echo '
            <div class="diagnostic-info">
                <h6><i class="fas fa-wrench me-2"></i>Información de Diagnóstico:</h6>
                <strong>Directorio existe:</strong> ' . ($diagnostic_info['directory_exists'] ? 'Sí' : 'No') . '<br>
                <strong>Archivo existe:</strong> ' . ($diagnostic_info['file_exists'] ? 'Sí' : 'No') . '<br>
                <strong>Archivo legible:</strong> ' . ($diagnostic_info['file_readable'] ? 'Sí' : 'No') . '<br>
                <strong>Dominio actual:</strong> ' . htmlspecialchars($_SERVER['HTTP_HOST']) . '<br>
                <strong>Última verificación:</strong> ' . ($status['last_check'] ? date('Y-m-d H:i:s', $status['last_check']) : 'N/A') . '<br>
            </div>

            <p class="text-muted mt-3">
                <i class="fas fa-info-circle me-1"></i>
                Contacte al administrador del sistema para resolver este problema.
            </p>
        </div>';

    if (in_array($status['status'], ['network_error', 'server_unreachable'])) {
        echo '
        <script>
        document.addEventListener("DOMContentLoaded", function() {
            const statusBox = document.getElementById("verification-status");
            const spinner = statusBox.querySelector(".spinner-border");
            const statusText = document.getElementById("status-text");
            const retryBtn = document.getElementById("retry-btn");
            async function checkLicense() {
                spinner.style.display = "inline-block";
                statusText.textContent = "Verificando licencia...";
                try {
                    const resp = await fetch("manual_license_check.php");
                    const data = await resp.json();
                    if (data.success) {
                        statusText.textContent = "Licencia válida. Recargando...";
                        location.reload();
                    } else {
                        statusText.textContent = data.message || "No se pudo verificar la licencia.";
                        if (data.status === "network_error") {
                            setTimeout(checkLicense, 60000);
                        }
                    }
                } catch (e) {
                    statusText.textContent = "Error de red.";
                    setTimeout(checkLicense, 60000);
                } finally {
                    spinner.style.display = "none";
                }
            }
            retryBtn.addEventListener("click", checkLicense);
            ';
        if ($status['status'] === 'network_error') {
            echo 'setTimeout(checkLicense, 60000);';
        }
        echo '
        });
        </script>';
    }

    echo '
    </body>
    </html>';

    exit();
}

// Incluir dependencias
require_once 'config/config.php';
require_once 'decodificador.php';
require_once 'instalacion/basededatos.php';
require_once 'cache/cache_helper.php';

/**
 * Clase principal para manejo de emails - VERSIÓN CORREGIDA
 */
class EmailSearchEngine {
    private $conn;
    private $settings;
    private $platforms_cache;
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
        $this->loadSettings();
        $this->loadPlatforms();
    }
    
    private function loadSettings() {
        $this->settings = SimpleCache::get_settings($this->conn);
    }
    
    private function loadPlatforms() {
        $this->platforms_cache = SimpleCache::get_platform_subjects($this->conn);
    }
    
    /**
     * Búsqueda principal de emails con fallback automático
     */
public function searchEmails(string $email, string $platform, int $userId): array {
    $start_time = microtime(true);
    
    // Validaciones iniciales
    $validation_result = $this->validateSearchRequest($email, $platform);
    if ($validation_result !== true) {
        return $validation_result;
    }
    
    // Obtener TODOS los asuntos posibles para la plataforma
    $subjects = $this->getSubjectsForPlatform($platform);
    
    // La función filterSubjectsForUser ya maneja correctamente el bypass para 'admin'.
    $subjects = $this->filterSubjectsForUser($userId, $platform, $subjects);
    
    // significa que el usuario no tiene permisos para ninguno.
    if (empty($subjects)) {
        return $this->createErrorResponse('No tienes asuntos autorizados para buscar en esta plataforma.');
    }

    // Obtener servidores habilitados
    $servers = SimpleCache::get_enabled_servers($this->conn);

    if (empty($servers)) {
        return $this->createErrorResponse('No hay servidores IMAP configurados.');
    }

    // Buscar en servidores (solo con los asuntos permitidos)
    $result = $this->searchInServers($email, $subjects, $servers);
    
    // Registrar en log
    $this->logSearch($userId, $email, $platform, $result);
    
    $execution_time = microtime(true) - $start_time;
    $this->logPerformance("Búsqueda completa: " . round($execution_time, 3) . "s");
    
    return $result;
}
    
    /**
     * Validación segura de la solicitud de búsqueda
     */
    private function validateSearchRequest($email, $platform) {
        // Validar formato de email
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->createErrorResponse('Email inválido.');
        }
        
        if (strlen($email) > 50) {
            return $this->createErrorResponse('El email no debe superar los 50 caracteres.');
        }
        
        // Verificar autorización
        if (!$this->isAuthorizedEmail($email)) {
            return $this->createErrorResponse('No tiene permisos para consultar este email.');
        }
        
        return true;
    }
    
    /**
 * Verificación de email autorizado con BYPASS SILENCIOSO para ADMIN
 * Solo logea errores críticos
 */
private function isAuthorizedEmail($email) {
    // 🔑 BYPASS TOTAL PARA ADMIN - SIN LOGS NORMALES
    if (isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin','superadmin'], true)) {
        return true; // Admin acceso sin logs
    }
    
    $auth_enabled = ($this->settings['EMAIL_AUTH_ENABLED'] ?? '0') === '1';
    $user_restrictions_enabled = ($this->settings['USER_EMAIL_RESTRICTIONS_ENABLED'] ?? '0') === '1';
    
    // Si no hay filtro de autorización, permitir todos
    if (!$auth_enabled) {
        return true;
    }
    
    // Verificar si el email está en la lista de autorizados
    $stmt = $this->conn->prepare("SELECT id FROM authorized_emails WHERE email = ? LIMIT 1");
    if (!$stmt) {
        error_log("❌ ERROR SQL: Error preparando consulta de autorización: " . $this->conn->error);
        return false;
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        $stmt->close();
        return false; // Email no autorizado, sin log
    }
    
    $email_data = $result->fetch_assoc();
    $authorized_email_id = $email_data['id'];
    $stmt->close();
    
    // Si las restricciones por usuario están deshabilitadas, permitir
    if (!$user_restrictions_enabled) {
        return true;
    }
    
    // Verificar si el usuario actual tiene acceso a este email específico
    $user_id = $_SESSION['user_id'] ?? null;
    
    // Si no hay usuario logueado, denegar
    if (!$user_id) {
        return false;
    }
    
    // Verificar si el usuario tiene asignado este email específico
    $stmt_user = $this->conn->prepare("
        SELECT 1 FROM user_authorized_emails 
        WHERE user_id = ? AND authorized_email_id = ? 
        LIMIT 1
    ");
    
    if (!$stmt_user) {
        error_log("❌ ERROR SQL: Error preparando consulta de restricción por usuario: " . $this->conn->error);
        return false;
    }
    
    $stmt_user->bind_param("ii", $user_id, $authorized_email_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    $has_access = $result_user->num_rows > 0;
    $stmt_user->close();
    
    return $has_access;
}

/**
 * Verificación de permisos con bypass silencioso para admin
 */
private function checkEmailPermission($email) {
    // 🔑 BYPASS SILENCIOSO PARA ADMIN
    if (isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin','superadmin'], true)) {
        return true;
    }
    
    // Para usuarios normales, usar la validación estándar
    return $this->isAuthorizedEmail($email);
}

    /**
     * Nueva función para obtener emails asignados a un usuario específico
     */
    public function getUserAuthorizedEmails($user_id) {
        $user_restrictions_enabled = ($this->settings['USER_EMAIL_RESTRICTIONS_ENABLED'] ?? '0') === '1';
        
        // Si no hay restricciones por usuario, devolver todos los emails autorizados
        if (!$user_restrictions_enabled) {
            $stmt = $this->conn->prepare("SELECT email FROM authorized_emails ORDER BY email ASC");
            $stmt->execute();
            $result = $stmt->get_result();
            
            $emails = [];
            while ($row = $result->fetch_assoc()) {
                $emails[] = $row['email'];
            }
            $stmt->close();
            return $emails;
        }
        
        // Si hay restricciones, devolver solo los emails asignados al usuario
        $query = "
            SELECT ae.email 
            FROM user_authorized_emails uae 
            JOIN authorized_emails ae ON uae.authorized_email_id = ae.id 
            WHERE uae.user_id = ? 
            ORDER BY ae.email ASC
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $emails = [];
        while ($row = $result->fetch_assoc()) {
            $emails[] = $row['email'];
        }
        $stmt->close();
        
        return $emails;
    }

    /**
     * Obtener asuntos asignados a un usuario para una plataforma
     */
    public function getUserPlatformSubjects($user_id, $platform_id) {
        $stmt = $this->conn->prepare("SELECT subject_keyword FROM user_platform_subjects WHERE user_id = ? AND platform_id = ?");
        $stmt->bind_param("ii", $user_id, $platform_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $subjects = [];
        while ($row = $result->fetch_assoc()) {
            $subjects[] = $row['subject_keyword'];
        }
        $stmt->close();
        return $subjects;
    }

    private function hasUserSubjectAccess(int $userId, string $platform): bool {
        $enabled = ($this->settings['USER_SUBJECT_RESTRICTIONS_ENABLED'] ?? '0') === '1';
        if (!$enabled) {
            return true;
        }

        $stmt = $this->conn->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user && ($user['role'] === 'admin' || $user['role'] === 'superadmin')) {
            return true;
        }

        $platformId = $this->getPlatformId($platform);
        if (!$platformId) {
            return false;
        }

        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM user_platform_subjects WHERE user_id = ? AND platform_id = ?");
        $stmt->bind_param('ii', $userId, $platformId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row['count'] > 0;
    }
        
    /**
     * Obtener asuntos para una plataforma
     */
    private function getSubjectsForPlatform($platform) {
        return $this->platforms_cache[$platform] ?? [];
    }

    private function getPlatformId($platform) {
        $stmt = $this->conn->prepare("SELECT id FROM platforms WHERE name = ? LIMIT 1");
        $stmt->bind_param("s", $platform);
        $stmt->execute();
        $result = $stmt->get_result();
        $id = null;
        if ($row = $result->fetch_assoc()) {
            $id = $row['id'];
        }
        $stmt->close();
        return $id;
    }

    private function filterSubjectsForUser(int $userId, string $platform, array $allSubjects): array {
    $enabled = ($this->settings['USER_SUBJECT_RESTRICTIONS_ENABLED'] ?? '0') === '1';
    if (!$enabled) {
        return $allSubjects;
    }

    try {
        $stmt = $this->conn->prepare("SELECT role FROM users WHERE id = ?");
        if (!$stmt) {
            error_log("ERROR: No se pudo preparar query de usuario: " . $this->conn->error);
            return $allSubjects; // Fallback para admin
        }
        
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user && ($user['role'] === 'admin' || $user['role'] === 'superadmin')) {
            return $allSubjects;
        }
        $platformId = $this->getPlatformId($platform);
        
        if (!$platformId) {
            error_log("ERROR: Platform ID no encontrado");
            return [];
        }

        $stmt = $this->conn->prepare("SELECT subject_keyword FROM user_platform_subjects WHERE user_id = ? AND platform_id = ?");
        $stmt->bind_param('ii', $userId, $platformId);
        $stmt->execute();
        $result = $stmt->get_result();

        $allowedSubjects = [];
        while ($row = $result->fetch_assoc()) {
            $allowedSubjects[] = $row['subject_keyword'];
        }
        $stmt->close();

        if (empty($allowedSubjects) || ($user && ($user['role'] === 'admin' || $user['role'] === 'superadmin'))) {
            return $allSubjects;
        }

        // Filtrar solo asuntos permitidos
        return array_intersect($allSubjects, $allowedSubjects);
        
    } catch (Exception $e) {
        error_log("EXCEPCIÓN en filterSubjectsForUser: " . $e->getMessage());
        return $allSubjects; // Fallback para admin
    }
    }
    
    /**
 * Búsqueda en múltiples servidores con estrategia optimizada - VERSIÓN MEJORADA
 */
private function searchInServers($email, $subjects, $servers) {
    
    $early_stop = ($this->settings['EARLY_SEARCH_STOP'] ?? '1') === '1';
    $all_results = [];
    $total_emails_found = 0;
    $servers_with_emails = 0;
    
    foreach ($servers as $server) {
        try {
            $result = $this->searchInSingleServer($email, $subjects, $server);
            $all_results[] = $result;
            
            // Si encontró y procesó exitosamente, retornar inmediatamente
            if ($result['found']) {
                $this->logPerformance("Email encontrado y procesado en servidor: " . $server['server_name']);
                return $result;
            }
            
            // Acumular estadísticas para reporte final
            if (isset($result['emails_found_count']) && $result['emails_found_count'] > 0) {
                $total_emails_found += $result['emails_found_count'];
                $servers_with_emails++;
            }
            
            // Early stop solo si realmente encontró y procesó contenido
            if ($early_stop && $result['found']) {
                break;
            }
            
        } catch (Exception $e) {
            error_log("ERROR en servidor " . $server['server_name'] . ": " . $e->getMessage());
            continue;
        }
    }

    if ($total_emails_found > 0) {
        // Encontró emails pero no pudo procesarlos
        $message = $servers_with_emails > 1 
            ? "Se encontraron {$total_emails_found} emails en {$servers_with_emails} servidores, pero ninguno contenía datos válidos."
            : "Se encontraron {$total_emails_found} emails, pero ninguno contenía datos válidos.";
            
        return [
            'found' => false,
            'message' => $message,
            'type' => 'found_but_unprocessable',
            'emails_found_count' => $total_emails_found,
            'servers_checked' => count($servers),
            'servers_with_emails' => $servers_with_emails,
            'search_performed' => true,
            'processing_attempted' => true
        ];
    }
    
    // No encontró nada en ningún servidor
    return [
        'found' => false,
        'message' => '0 mensajes encontrados.',
        'type' => 'not_found',
        'servers_checked' => count($servers),
        'search_performed' => true,
        'emails_found_count' => 0
    ];
}
    
    /**
     * Búsqueda en un servidor individual - VERSIÓN CORREGIDA
     */
    private function searchInSingleServer($email, $subjects, $server_config) {
        $inbox = $this->openImapConnection($server_config);
        
        if (!$inbox) {
            return [
                'found' => false, 
                'error' => 'Error de conexión',
                'message' => 'No se pudo conectar al servidor ' . $server_config['server_name'],
                'type' => 'connection_error'
            ];
        }
        try {

            // Estrategia de búsqueda inteligente
            $email_ids = $this->executeSearch($inbox, $email, $subjects);
            
            if (empty($email_ids)) {
                // Caso 1: Realmente no hay emails que coincidan
                return [
                    'found' => false,
                    'message' => '0 mensajes encontrados.',
                    'search_performed' => true,
                    'emails_found_count' => 0,
                    'type' => 'not_found'
                ];
            }
            
            // Caso 2: SÍ encontró emails, ahora intentar procesarlos
            $emails_found_count = count($email_ids);
            $this->logPerformance("Encontrados {$emails_found_count} emails en servidor: " . $server_config['server_name']);
            
            // Intentar procesar múltiples emails, no solo el más reciente
            $emails_processed = 0;
            $last_error = '';
            
            // Ordenar por más recientes primero
            rsort($email_ids);
            
            // Intentar procesar hasta 3 emails recientes para mayor probabilidad de éxito
            $max_attempts = min(3, $emails_found_count);
            
            for ($i = 0; $i < $max_attempts; $i++) {
                try {
                    $email_content = $this->processFoundEmail($inbox, $email_ids[$i]);
                    
                    if ($email_content) {
                        // ¡Éxito! Logró procesar el contenido
                        return [
                            'found' => true,
                            'content' => $email_content,
                            'server' => $server_config['server_name'],
                            'emails_found_count' => $emails_found_count,
                            'emails_processed' => $emails_processed + 1,
                            'attempts_made' => $i + 1,
                            'type' => 'success'
                        ];
                    }
                    
                    $emails_processed++;
                    
                } catch (Exception $e) {
                    $last_error = $e->getMessage();
                    continue;
                }
            }
            
            // Caso 3: Encontró emails pero no pudo procesar ninguno
            return [
                'found' => false,
                'message' => "{$emails_found_count} emails encontrados, pero ninguno contenía datos válidos.",
                'search_performed' => true,
                'emails_found_count' => $emails_found_count,
                'emails_processed' => $emails_processed,
                'processing_error' => $last_error,
                'server' => $server_config['server_name'],
                'type' => 'found_but_unprocessable'
            ];
            
        } catch (Exception $e) {
            error_log("Error en búsqueda: " . $e->getMessage());
            return [
                'found' => false, 
                'error' => $e->getMessage(),
                'message' => 'Error durante la búsqueda: ' . $e->getMessage(),
                'type' => 'search_error'
            ];
        } finally {
            if ($inbox) {
                imap_close($inbox);
            }
        }
    }
    
    /**
     * Ejecución de búsqueda con múltiples estrategias
     */
    private function executeSearch($inbox, $email, $subjects) {
        // Estrategia 1: Búsqueda optimizada
        $emails = $this->searchOptimized($inbox, $email, $subjects);

        if (!empty($emails)) {
            return $emails;
        }
        
        // Estrategia 2: Búsqueda simple (fallback)
        return $this->searchSimple($inbox, $email, $subjects);
        error_log("Búsqueda simple encontró: " . count($result) . " emails");
    }
    
/**
 * Búsqueda optimizada con IMAP - MEJORADA para zonas horarias
 */
private function searchOptimized($inbox, $email, $subjects) {
    try {
        // CAMBIO PRINCIPAL: Usar horas configurables para cubrir diferencias de zona horaria
        $search_hours = (int)($this->settings['TIMEZONE_DEBUG_HOURS'] ?? 48); // Configurable, 48h por defecto
        $search_date = date("d-M-Y", time() - ($search_hours * 3600));
        
        // Construir criterio de búsqueda con rango amplio
        $criteria = 'TO "' . $email . '" SINCE "' . $search_date . '"';
        $all_emails = imap_search($inbox, $criteria);
        
        if (!$all_emails) {
            error_log("❌ imap_search devolvió FALSE");
            error_log("Error IMAP: " . imap_last_error());
            return [];
        }

        // NUEVO: Filtrar por tiempo preciso usando timestamps locales
        $filtered = $this->filterEmailsByTimeAndSubject($inbox, $all_emails, $subjects);
        return $filtered;
        
    } catch (Exception $e) {
        error_log("❌ ERROR en búsqueda optimizada: " . $e->getMessage());
        return [];
    }
}

private function filterEmailsByTimeAndSubject($inbox, $email_ids, $subjects) {
    if (empty($email_ids)) {
        return [];
    }
    
    // CONFIGURACIÓN SIMPLE: 3 días hacia atrás (cubre cualquier zona horaria)
    $days_back = 3; // 72 horas = cubre cualquier diferencia de zona horaria
    $cutoff_timestamp = time() - ($days_back * 24 * 60 * 60);
    
    $found_emails = [];
    $max_check = min(50, count($email_ids));
    
    // Ordenar por más recientes
    rsort($email_ids);
    $emails_to_check = array_slice($email_ids, 0, $max_check);
    
    foreach ($emails_to_check as $email_id) {
        try {
            $header = imap_headerinfo($inbox, $email_id);
            if (!$header || !isset($header->date)) continue;
            
            $email_timestamp = $this->parseEmailTimestamp($header->date);
            if ($email_timestamp === false) continue;
            
            $email_age_hours = round((time() - $email_timestamp) / 3600, 1);
            
            // FILTRO SIMPLE: ¿Está en los últimos 3 días?
            if ($email_timestamp >= $cutoff_timestamp) {
                if (isset($header->subject)) {
                    $decoded_subject = $this->decodeMimeSubject($header->subject);

                    foreach ($subjects as $subject) {
                        if ($this->subjectMatches($decoded_subject, $subject)) {
                            $found_emails[] = $email_id;
                            if (($this->settings['EARLY_SEARCH_STOP'] ?? '1') === '1') {
                                return $found_emails;
                            }
                            break;
                        }
                    }
                }
            } else {
            }
            
        } catch (Exception $e) {
            continue;
        }
    }
    
    return $found_emails;
}

/**
 * Parsear timestamp de email de forma robusta
 * Maneja diferentes formatos de fecha que pueden venir en headers de email
 */
private function parseEmailTimestamp($email_date) {
    if (empty($email_date)) {
        return false;
    }
    
    try {
        // Intentar parseo directo con strtotime (funciona con la mayoría de formatos RFC)
        $timestamp = strtotime($email_date);
        
        if ($timestamp !== false && $timestamp > 0) {
            // Validar que el timestamp sea razonable (no muy viejo ni futuro)
            $now = time();
            $one_year_ago = $now - (365 * 24 * 3600);
            $one_day_future = $now + (24 * 3600);
            
            if ($timestamp >= $one_year_ago && $timestamp <= $one_day_future) {
                return $timestamp;
            } else {
                $this->logPerformance("Timestamp fuera de rango razonable: " . date('Y-m-d H:i:s', $timestamp) . " de fecha: " . $email_date);
            }
        }
        
        // Si el parseo directo falla, intentar con DateTime (más robusto)
        $datetime = new DateTime($email_date);
        $timestamp = $datetime->getTimestamp();
        
        // Validar nuevamente
        if ($timestamp >= $one_year_ago && $timestamp <= $one_day_future) {
            return $timestamp;
        }
        
        $this->logPerformance("DateTime timestamp fuera de rango: " . date('Y-m-d H:i:s', $timestamp) . " de fecha: " . $email_date);
        return false;
        
    } catch (Exception $e) {
        $this->logPerformance("Error parseando fecha '" . $email_date . "': " . $e->getMessage());
        
        // Último intento: extraer timestamp usando regex si es un formato conocido
        if (preg_match('/(\d{1,2})\s+(\w{3})\s+(\d{4})\s+(\d{1,2}):(\d{2}):(\d{2})/', $email_date, $matches)) {
            try {
                $day = $matches[1];
                $month = $matches[2];
                $year = $matches[3];
                $hour = $matches[4];
                $minute = $matches[5];
                $second = $matches[6];
                
                $formatted_date = "$day $month $year $hour:$minute:$second";
                $timestamp = strtotime($formatted_date);
                
                if ($timestamp !== false && $timestamp > 0) {
                    return $timestamp;
                }
            } catch (Exception $regex_error) {
                $this->logPerformance("Error en parseo regex: " . $regex_error->getMessage());
            }
        }
        
        return false;
    }
}
    
    /**
 * Búsqueda simple (fallback confiable) - MEJORADA para zonas horarias
 */
private function searchSimple($inbox, $email, $subjects) {
    try {
        
        // Usar búsqueda amplia sin restricción de fecha como fallback
        $criteria = 'TO "' . $email . '"';
        $all_emails = imap_search($inbox, $criteria);
        
        if (!$all_emails) {
            return [];
        }

        // Ordenar por más recientes y limitar para performance
        rsort($all_emails);
        $emails_to_check = array_slice($all_emails, 0, 30); // Limitar a 30 para búsqueda simple

        // Usar el mismo filtrado preciso por tiempo y asunto
        $filtered = $this->filterEmailsByTimeAndSubject($inbox, $emails_to_check, $subjects);
        return $filtered;
        
    } catch (Exception $e) {
        error_log("❌ ERROR en búsqueda simple: " . $e->getMessage());
        return [];
    }
}


    /**
     * Filtrar emails por asunto
     */
    private function filterEmailsBySubject($inbox, $email_ids, $subjects) {
        $found_emails = [];
        $max_check = (int)($this->settings['MAX_EMAILS_TO_CHECK'] ?? 50);
        
        foreach (array_slice($email_ids, 0, $max_check) as $email_id) {
            try {
                $header = imap_headerinfo($inbox, $email_id);
                if (!$header || !isset($header->subject)) {
                    continue;
                }
                
                $decoded_subject = $this->decodeMimeSubject($header->subject);
                
                foreach ($subjects as $subject) {
                    if ($this->subjectMatches($decoded_subject, $subject)) {
                        $found_emails[] = $email_id;
                        
                        // Early stop si está habilitado
                        if (($this->settings['EARLY_SEARCH_STOP'] ?? '1') === '1') {
                            return $found_emails;
                        }
                        break;
                    }
                }
                
            } catch (Exception $e) {
                continue;
            }
        }
        
        return $found_emails;
    }
    
    /**
     * Decodificación segura de asuntos MIME
     */
    private function decodeMimeSubject($subject) {
        if (empty($subject)) {
            return '';
        }
        
        try {
            $decoded = imap_mime_header_decode($subject);
            $result = '';
            
            foreach ($decoded as $part) {
                $charset = $part->charset ?? 'utf-8';
                if (strtolower($charset) === 'default') {
                    $result .= $part->text;
                } else {
                    $result .= mb_convert_encoding($part->text, 'UTF-8', $charset);
                }
            }
            
            return trim($result);
        } catch (Exception $e) {
            return $subject; // Retornar original si falla la decodificación
        }
    }
    
    /**
     * Verificación de coincidencia de asuntos
     */
    private function subjectMatches($decoded_subject, $pattern) {
        // Coincidencia directa (case insensitive)
        if (stripos($decoded_subject, trim($pattern)) !== false) {
            return true;
        }
        
        // Coincidencia flexible por palabras clave
        return $this->flexibleSubjectMatch($decoded_subject, $pattern);
    }
    
    /**
     * Coincidencia flexible de asuntos
     */
    private function flexibleSubjectMatch($subject, $pattern) {
        $subject_clean = strtolower(strip_tags($subject));
        $pattern_clean = strtolower(strip_tags($pattern));
        
        $subject_words = preg_split('/\s+/', $subject_clean);
        $pattern_words = preg_split('/\s+/', $pattern_clean);
        
        if (count($pattern_words) <= 1) {
            return false;
        }
        
        $matches = 0;
        foreach ($pattern_words as $word) {
            if (strlen($word) > 3) {
                foreach ($subject_words as $subject_word) {
                    if (stripos($subject_word, $word) !== false) {
                        $matches++;
                        break;
                    }
                }
            }
        }
        
        $match_ratio = $matches / count($pattern_words);
        return $match_ratio >= 0.7; // 70% de coincidencia
    }
    
    /**
     * Conexión IMAP optimizada
     */
    private function openImapConnection($server_config) {
        if (empty($server_config['imap_server']) || empty($server_config['imap_user'])) {
            return false;
        }
        
        $timeout = (int)($this->settings['IMAP_CONNECTION_TIMEOUT'] ?? 10);
        $old_timeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', $timeout);
        
        try {
            $mailbox = sprintf(
                '{%s:%d/imap/ssl/novalidate-cert}INBOX',
                $server_config['imap_server'],
                $server_config['imap_port']
            );
            
            $inbox = imap_open(
                $mailbox,
                $server_config['imap_user'],
                $server_config['imap_password'],
                OP_READONLY | CL_EXPUNGE,
                1
            );
            
            return $inbox ?: false;
            
        } catch (Exception $e) {
            error_log("Error conexión IMAP: " . $e->getMessage());
            return false;
        } finally {
            ini_set('default_socket_timeout', $old_timeout);
        }
    }
    
/**
 * Procesar email encontrado - VERSIÓN SIMPLE
 */
private function processFoundEmail($inbox, $email_id) {
    try {
        $header = imap_headerinfo($inbox, $email_id);
        if (!$header) {
            return '<div style="padding: 15px; color: #ff0000;">Error: No se pudo obtener la información del mensaje.</div>';
        }

        // Obtener el cuerpo del email con las nuevas funciones de decodificación
        $body = get_email_body($inbox, $email_id, $header);
        
        if (!empty($body)) {
            // Procesar el cuerpo preservando el contenido original
            return process_email_body($body);
        }
        
        return '<div style="padding: 15px; color: #666;">No se pudo extraer el contenido del mensaje.</div>';
        
    } catch (Exception $e) {
        return '<div style="padding: 15px; color: #ff0000;">Error al procesar el mensaje: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}
    
    /**
     * Crear respuesta de error
     */
    private function createErrorResponse($message) {
        return [
            'found' => false,
            'error' => true,
            'message' => $message,
            'type' => 'error'
        ];
    }
    
    /**
     * Crear respuesta de no encontrado (cuando realmente no hay emails)
     */
    private function createNotFoundResponse() {
        return [
            'found' => false,
            'message' => '0 mensajes encontrados.',
            'type' => 'not_found',
            'search_performed' => true,
            'emails_found_count' => 0
        ];
    }
    
    /**
     * Crear respuesta para emails encontrados pero no procesables
     */
    private function createFoundButUnprocessableResponse($emails_count, $details = '') {
        $message = $emails_count > 1 
            ? "{$emails_count} emails encontrados, pero ninguno contenía datos válidos."
            : "1 email encontrado, pero no contenía datos válidos.";
        
        if ($details) {
            $message .= " ({$details})";
        }
        
        return [
            'found' => false,
            'message' => $message,
            'type' => 'found_but_unprocessable',
            'search_performed' => true,
            'emails_found_count' => $emails_count,
            'processing_attempted' => true
        ];
    }
    
    /**
     * Crear respuesta de éxito
     */
    private function createSuccessResponse($content, $server_name, $additional_info = []) {
        return [
            'found' => true,
            'content' => $content,
            'server' => $server_name,
            'type' => 'success',
            'message' => 'Contenido extraído exitosamente.',
            'emails_found_count' => $additional_info['emails_found_count'] ?? 1,
            'emails_processed' => $additional_info['emails_processed'] ?? 1
        ];
    }
    
    /**
     * Registrar búsqueda en log - VERSIÓN MEJORADA
     */
    private function logSearch($user_id, $email, $platform, $result) {
        try {
            // Determinar el estado más preciso basado en el nuevo sistema
            if ($result['found']) {
                $status = 'Éxito';
                $detail = '[Contenido Encontrado y Procesado]';
            } elseif (isset($result['type'])) {
                switch ($result['type']) {
                    case 'found_but_unprocessable':
                        $status = 'Encontrado Sin Procesar';
                        $emails_count = $result['emails_found_count'] ?? 0;
                        $detail = "Encontrados {$emails_count} emails, pero sin contenido válido";
                        break;
                    case 'not_found':
                        $status = 'No Encontrado';
                        $detail = '0 emails coinciden con los criterios';
                        break;
                    case 'error':
                    case 'connection_error':
                    case 'search_error':
                        $status = 'Error';
                        $detail = $result['message'] ?? 'Error desconocido';
                        break;
                    default:
                        $status = 'No Encontrado';
                        $detail = $result['message'] ?? 'Sin detalles';
                }
            } else {
                // Fallback para compatibilidad
                $status = $result['found'] ? 'Éxito' : 'No Encontrado';
                $detail = $result['found'] ? '[Contenido Omitido]' : ($result['message'] ?? 'Sin detalles');
            }
            
            $stmt = $this->conn->prepare(
                "INSERT INTO logs (user_id, email_consultado, plataforma, ip, resultado) VALUES (?, ?, ?, ?, ?)"
            );
            
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $log_entry = $status . ": " . substr(strip_tags($detail), 0, 200);
            
            $stmt->bind_param("issss", $user_id, $email, $platform, $ip, $log_entry);
            $stmt->execute();
            $stmt->close();
            
        } catch (Exception $e) {
        }
    }
    
    /**
     * Log de performance (configurable)
     */
    private function logPerformance($message) {
        $logging_enabled = ($this->settings['PERFORMANCE_LOGGING'] ?? '0') === '1';
        
        if ($logging_enabled) {
        }
    }
}

// ================================================
// FUNCIONES DE UTILIDAD Y COMPATIBILIDAD
// ================================================

/**
 * Validación de email mejorada
 */
function validate_email($email) {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'El correo electrónico proporcionado es inválido o está vacío.';
    }
    
    if (strlen($email) > 50) {
        return 'El correo electrónico no debe superar los 50 caracteres.';
    }
    
    return '';
}

/**
 * Escape seguro de strings
 */
function escape_string($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Verificar si el sistema está instalado
 */
function is_installed() {
    global $db_host, $db_user, $db_password, $db_name;
    
    if (empty($db_host) || empty($db_user) || empty($db_name)) {
        return false;
    }
    
    try {
        $conn = new mysqli($db_host, $db_user, $db_password, $db_name);
        $conn->set_charset("utf8mb4");
        
        if ($conn->connect_error) {
            return false;
        }
        
        $result = $conn->query("SELECT value FROM settings WHERE name = 'INSTALLED'");
        
        if (!$result || $result->num_rows === 0) {
            $conn->close();
            return false;
        }
        
        $row = $result->fetch_assoc();
        $installed = $row['value'] === '1';
        
        $conn->close();
        return $installed;
        
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Obtener configuraciones (con cache)
 */
function get_all_settings($conn) {
    return SimpleCache::get_settings($conn);
}

/**
 * Verificar configuración habilitada
 */
function is_setting_enabled($setting_name, $conn, $default = false) {
    $settings = SimpleCache::get_settings($conn);
    $value = $settings[$setting_name] ?? ($default ? '1' : '0');
    return $value === '1';
}

/**
 * Obtener valor de configuración
 */
function get_setting_value($setting_name, $conn, $default = '') {
    $settings = SimpleCache::get_settings($conn);
    return $settings[$setting_name] ?? $default;
}

// ================================================
// PROCESAMIENTO DE FORMULARIO PRINCIPAL - VERSIÓN CORREGIDA
// ================================================

if (isset($_POST['email']) && isset($_POST['plataforma'])) {
    try {
        // Conexión a BD
        $conn = new mysqli($db_host, $db_user, $db_password, $db_name);
        $conn->set_charset("utf8mb4");
        
        if ($conn->connect_error) {
            throw new Exception("Error de conexión a la base de datos");
        }
        
        // Inicializar motor de búsqueda
        $search_engine = new EmailSearchEngine($conn);
        
        // Procesar búsqueda
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        $platform = $_POST['plataforma'];
        $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

        $result = $search_engine->searchEmails($email, $platform, $user_id);
        
        // NUEVA LÓGICA: Establecer respuesta en sesión basada en el tipo de resultado
        if ($result['found']) {
            // CASO 1: Éxito real - encontró y procesó contenido
            $_SESSION['resultado'] = $result['content'];
            $_SESSION['resultado_tipo'] = 'success';
            $_SESSION['resultado_info'] = [
                'emails_found' => $result['emails_found_count'] ?? 1,
                'server' => $result['server'] ?? 'Desconocido'
            ];
            unset($_SESSION['error_message']);
            
        } else {
            // CASO 2: No encontró O encontró pero no pudo procesar
            switch ($result['type'] ?? 'unknown') {
                case 'found_but_unprocessable':
                    // Encontró emails pero no pudo procesarlos
                    $_SESSION['resultado'] = $result['message'];
                    $_SESSION['resultado_tipo'] = 'found_but_unprocessable';
                    $_SESSION['resultado_info'] = [
                        'emails_found' => $result['emails_found_count'] ?? 0,
                        'servers_checked' => $result['servers_checked'] ?? 1
                    ];
                    unset($_SESSION['error_message']);
                    break;
                    
                case 'not_found':
                    // Realmente no encontró ningún email
                    $_SESSION['resultado'] = $result['message'];
                    $_SESSION['resultado_tipo'] = 'not_found';
                    $_SESSION['resultado_info'] = [
                        'servers_checked' => $result['servers_checked'] ?? 1
                    ];
                    unset($_SESSION['error_message']);
                    break;
                    
                case 'error':
                case 'connection_error':
                case 'search_error':
                default:
                    // Error real del sistema
                    $_SESSION['error_message'] = $result['message'];
                    $_SESSION['error_tipo'] = 'system_error';
                    unset($_SESSION['resultado'], $_SESSION['resultado_tipo'], $_SESSION['resultado_info']);
                    break;
            }
        }
        
        $conn->close();
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error del sistema. Inténtalo de nuevo más tarde.';
        $_SESSION['error_tipo'] = 'system_error';
        unset($_SESSION['resultado'], $_SESSION['resultado_tipo'], $_SESSION['resultado_info']);
        error_log("Error en procesamiento principal: " . $e->getMessage());
    }
    
    header('Location: inicio.php');
    exit();
}

// ============================
// FUNCIONES DE COMPATIBILIDAD 
// ============================

// Funciones legacy para compatibilidad
function search_email($inbox, $email, $asunto) {
    // Usar nueva clase si está disponible
    return false; // Placeholder
}

function open_imap_connection($server_config) {
    // Usar nueva clase si está disponible
    return false; // Placeholder
}

function close_imap_connection() {
    // Mantenido por compatibilidad
}

?>