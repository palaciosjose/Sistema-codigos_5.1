<?php
require_once __DIR__ . '/config/path_constants.php';
/**
 * Cliente de Licencias - VERSIÓN CORREGIDA
 * Solución al problema de rutas relativas
 */

class ClientLicense {
    private $license_server;
    private $license_dir;
    private $license_file;

    public function __construct($license_server = null) {
        $this->license_server = $license_server ?: 'https://scode.warsup.shop/api.php';

        $this->license_dir = LICENSE_DIR;
        $this->license_file = LICENSE_FILE;

        // Asegurar que el directorio existe
        $this->ensureLicenseDirectoryExists();
    }
    
    /**
     * Asegurar que el directorio de licencias existe
     */
    private function ensureLicenseDirectoryExists() {
        if (!file_exists($this->license_dir)) {
            if (!mkdir($this->license_dir, 0755, true)) {
                error_log("Error: No se pudo crear el directorio de licencias: " . $this->license_dir);
                return false;
            }
            
            // Crear .htaccess para proteger el directorio
            $htaccess_content = "Deny from all\n<Files \"*.dat\">\nDeny from all\n</Files>";
            file_put_contents($this->license_dir . '/.htaccess', $htaccess_content);
        }
        
        return true;
    }
    
    /**
     * Activar licencia
     */
    public function activateLicense($license_key) {
        try {
            $domain = $_SERVER['HTTP_HOST'];
            $ip = $_SERVER['SERVER_ADDR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
            
            $data = [
                'action' => 'activate',
                'license_key' => $license_key,
                'domain' => $domain,
                'ip' => $ip,
                'software' => 'Sistema de Códigos',
                'version' => '2.0'
            ];
            
            $response = $this->makeRequest($data);
            
            if ($response && $response['success']) {
                // Guardar la licencia en el archivo correcto
                $license_data = [
                    'license_key' => $license_key,
                    'domain' => $domain,
                    'activated_at' => date('Y-m-d H:i:s'),
                    'last_check' => time(),
                    'status' => 'active',
                    'expires_at' => $response['expires_at'] ?? null,
                    'license_type' => $response['license_type'] ?? null,
                    'server_response' => $response
                ];
                
                if ($this->saveLicenseData($license_data)) {
                    error_log("Licencia guardada exitosamente en: " . $this->license_file);
                    return ['success' => true, 'message' => 'Licencia activada correctamente'];
                } else {
                    error_log("Error guardando licencia en: " . $this->license_file);
                    return ['success' => false, 'message' => 'Error guardando la licencia'];
                }
            } else {
                $error_msg = $response['message'] ?? 'Error desconocido del servidor';
                return ['success' => false, 'message' => $error_msg];
            }
            
        } catch (Exception $e) {
            error_log("Error activando licencia: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error de conexión: ' . $e->getMessage()];
        }
    }
    
    /**
     * Obtener el estado actual de la licencia
     */
    public function getLicenseStatus() {
        // Modo instalador: solo verifica la existencia del archivo
        if (defined('INSTALLER_MODE') && INSTALLER_MODE) {
            if ($this->hasLicense()) {
                $data = $this->getLicenseData() ?: [];
                return [
                    'status' => 'active',
                    'message' => 'Modo instalador',
                    'expires_at' => $data['expires_at'] ?? null,
                    'last_check' => $data['last_check'] ?? null,
                    'grace_remaining' => 0
                ];
            }
            return [
                'status' => 'invalid',
                'message' => 'No se encontró licencia',
                'expires_at' => null,
                'last_check' => null,
                'grace_remaining' => 0
            ];
        }

        if (!$this->hasLicense()) {
            return [
                'status' => 'invalid',
                'message' => 'No se encontró licencia',
                'expires_at' => null,
                'last_check' => null,
                'grace_remaining' => 0
            ];
        }

        $license_data = $this->getLicenseData();
        if (!$license_data) {
            return [
                'status' => 'invalid',
                'message' => 'Datos de licencia corruptos',
                'expires_at' => null,
                'last_check' => null,
                'grace_remaining' => 0
            ];
        }

        $expires_at = $license_data['expires_at'] ?? null;
        $last_check = $license_data['last_check'] ?? 0;
        if ($expires_at && time() > strtotime($expires_at)) {
            return [
                'status' => 'expired',
                'message' => 'La licencia ha expirado',
                'expires_at' => $expires_at,
                'last_check' => $last_check,
                'grace_remaining' => 0
            ];
        }

        // Validar remotamente cada 24h
        if ((time() - $last_check) > 86400) {
            return $this->validateWithServer($license_data);
        }

        $grace_period = 7 * 24 * 3600;
        $grace_remaining = (int) max(0, $grace_period - (time() - $last_check));

        return [
            'status' => $license_data['status'] ?? 'invalid',
            'message' => ($license_data['status'] ?? '') === 'active' ? 'Licencia válida' : 'Licencia inválida',
            'expires_at' => $expires_at,
            'last_check' => $last_check,
            'grace_remaining' => $grace_remaining
        ];
    }

    /**
     * Forzar validación remota de la licencia
     */
    public function forceRemoteValidation() {
        $license_data = $this->getLicenseData();
        if (!$license_data) {
            return [
                'status' => 'invalid',
                'message' => 'No se encontró licencia',
                'expires_at' => null,
                'last_check' => null,
                'grace_remaining' => 0
            ];
        }
        return $this->validateWithServer($license_data);
    }

    /**
     * Verificar si la licencia es válida
     */
    public function isLicenseValid(&$details = null) {
        $status = $this->getLicenseStatus();
        if ($details !== null) {
            $details = $status;
        }
        return $status['status'] === 'active';
    }
    
    /**
     * Verificar si existe archivo de licencia
     */
    public function hasLicense() {
        return file_exists($this->license_file) && is_readable($this->license_file);
    }
    
    /**
     * Obtener información de la licencia
     */
    public function getLicenseInfo() {
        if (!$this->hasLicense()) {
            return null;
        }
        
        $license_data = $this->getLicenseData();
        if (!$license_data) {
            return null;
        }
        
        return [
            'domain' => $license_data['domain'] ?? '',
            'activated_at' => $license_data['activated_at'] ?? '',
            'status' => $license_data['status'] ?? 'unknown',
            'expires_at' => $license_data['expires_at'] ?? null,
            'last_check' => date('Y-m-d H:i:s', $license_data['last_check'] ?? 0),
            'license_key' => $license_data['license_key'] ?? '',
            'license_type' => $license_data['license_type'] ?? ($license_data['server_response']['license_type'] ?? null),
            'file_path' => $this->license_file
        ];
    }
    
    /**
     * Guardar datos de licencia en archivo
     */
    private function saveLicenseData($data) {
        try {
            $encoded = base64_encode(serialize($data));
            $result = file_put_contents($this->license_file, $encoded, LOCK_EX);
            
            if ($result !== false) {
                chmod($this->license_file, 0644);
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error guardando licencia: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Leer datos de licencia desde archivo
     */
    private function getLicenseData() {
        try {
            if (!$this->hasLicense()) {
                return null;
            }
            
            $content = file_get_contents($this->license_file);
            if ($content === false) {
                return null;
            }
            
            $decoded = base64_decode($content);
            if ($decoded === false) {
                return null;
            }
            
            $data = unserialize($decoded);
            if ($data === false) {
                return null;
            }
            
            return $data;
        } catch (Exception $e) {
            error_log("Error leyendo licencia: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Validar licencia con el servidor remoto
     *
     * En caso de errores de red se mantiene el valor de
     * `last_check` para que el periodo de gracia comience
     * desde la última validación exitosa. Si el servidor
     * responde con un código HTTP 4xx se considera que la
     * licencia es inválida inmediatamente.
     */
    private function validateWithServer($license_data) {
        try {
            $data = [
                'action' => 'validate',
                'license_key' => $license_data['license_key'] ?? '',
                'domain' => $_SERVER['HTTP_HOST'],
                'current_domain' => $license_data['domain'] ?? ''
            ];

            $response = $this->makeRequest($data);

            if ($response && $response['success']) {
                $license_data['last_check'] = time();
                $license_data['status'] = 'active';
                if (!empty($response['expires_at'])) {
                    $license_data['expires_at'] = $response['expires_at'];
                }
                if (!empty($response['license_type'])) {
                    $license_data['license_type'] = $response['license_type'];
                }
                $this->saveLicenseData($license_data);
                return [
                    'status' => 'active',
                    'message' => $response['message'] ?? 'Licencia válida',
                    'expires_at' => $license_data['expires_at'] ?? null,
                    'last_check' => $license_data['last_check'],
                    'grace_remaining' => 0
                ];
            } else {
                $license_data['last_check'] = time();
                $license_data['status'] = 'invalid';
                if (!empty($response['expires_at'])) {
                    $license_data['expires_at'] = $response['expires_at'];
                }
                if (!empty($response['license_type'])) {
                    $license_data['license_type'] = $response['license_type'];
                }
                $this->saveLicenseData($license_data);

                $status = 'invalid';
                if (!empty($response['error_code']) && $response['error_code'] === 'expired') {
                    $status = 'expired';
                }

                return [
                    'status' => $status,
                    'message' => $response['message'] ?? 'Licencia inválida',
                    'expires_at' => $license_data['expires_at'] ?? null,
                    'last_check' => $license_data['last_check'],
                    'grace_remaining' => 0
                ];
            }
        } catch (Exception $e) {
            error_log("Error validando licencia: " . $e->getMessage());

            $status = 'network_error';
            if (preg_match('/HTTP Error:\s*(\d+)/', $e->getMessage(), $m)) {
                $http = (int)$m[1];
                if ($http >= 400 && $http < 500) {
                    $license_data['last_check'] = time();
                    $license_data['status'] = 'invalid';
                    $this->saveLicenseData($license_data);
                    return [
                        'status' => 'invalid',
                        'message' => 'HTTP ' . $http,
                        'expires_at' => $license_data['expires_at'] ?? null,
                        'last_check' => $license_data['last_check'],
                        'grace_remaining' => 0
                    ];
                }
                if ($http >= 500) {
                    $status = 'server_unreachable';
                }
            }

            $last_check = $license_data['last_check'] ?? 0;
            $grace_period = 7 * 24 * 3600;
            $grace_remaining = (int) max(0, $grace_period - (time() - $last_check));

            return [
                'status' => $status,
                'message' => $e->getMessage(),
                'expires_at' => $license_data['expires_at'] ?? null,
                'last_check' => $last_check,
                'grace_remaining' => $grace_remaining
            ];
        }
    }
    
    /**
     * Realizar petición HTTP al servidor de licencias
     */
    private function makeRequest($data, $timeout = 10) {
        if (!function_exists('curl_init')) {
            throw new Exception('cURL no está disponible');
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->license_server,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'License-Client/2.0'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false || !empty($error)) {
            throw new Exception("Error cURL: " . $error);
        }
        
        if ($http_code !== 200) {
            throw new Exception("HTTP Error: " . $http_code);
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Error decodificando respuesta JSON");
        }
        
        return $decoded;
    }
    
    /**
     * Obtener información de diagnóstico
     */
    public function getDiagnosticInfo() {
        return [
            'license_dir' => $this->license_dir,
            'license_file' => $this->license_file,
            'directory_exists' => file_exists($this->license_dir),
            'directory_writable' => is_writable($this->license_dir),
            'file_exists' => file_exists($this->license_file),
            'file_readable' => is_readable($this->license_file),
            'file_writable' => is_writable($this->license_file),
            'project_root' => PROJECT_ROOT,
            'script_dir' => __DIR__,
            'constants_defined' => [
                'LICENSE_DIR' => defined('LICENSE_DIR'),
                'LICENSE_FILE' => defined('LICENSE_FILE'),
                'PROJECT_ROOT' => defined('PROJECT_ROOT'),
                'INSTALLER_MODE' => defined('INSTALLER_MODE')
            ]
        ];
    }
}

// ==========================================
// VERIFICACIÓN AUTOMÁTICA (SOLO SI NO ES INSTALADOR)
// ==========================================
if (!defined('INSTALLER_MODE') || !INSTALLER_MODE) {
    $license_client = new ClientLicense();
    
    // Verificar licencia en páginas públicas
    $exempt_files = ['index.php', 'inicio.php'];
    $current_file = basename($_SERVER['SCRIPT_NAME']);
    
    if (!in_array($current_file, $exempt_files)) {
        if (!$license_client->isLicenseValid()) {
            // Redirigir a página de error de licencia o bloquear acceso
            header('HTTP/1.1 403 Forbidden');
            echo '<!DOCTYPE html>
            <html>
            <head>
                <title>Licencia Requerida</title>
                <style>
                    body { font-family: Arial, sans-serif; text-align: center; margin-top: 100px; }
                    .error-box { background: #f8d7da; border: 1px solid #f5c6cb; padding: 20px; margin: 20px auto; width: 50%; border-radius: 5px; }
                </style>
            </head>
            <body>
                <div class="error-box">
                    <h1>Licencia Requerida</h1>
                    <p>Este software requiere una licencia válida para funcionar.</p>
                    <p>Contacte al administrador del sistema.</p>
                </div>
            </body>
            </html>';
            exit;
        }
    }
}

?>