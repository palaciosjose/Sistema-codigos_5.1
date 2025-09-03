<?php

require_once __DIR__ . '/LinkPatterns.php';
require_once __DIR__ . '/AuditLogger.php';

/**
 * Motor unificado de búsqueda de emails - VERSIÓN COMPLETA Y DEFINITIVA
 * Compatible 100% con funciones.php del sistema web + TODA la funcionalidad avanzada del bot
 * Versión 2.4 - SIN PERDER NADA: Códigos, Enlaces, Fragmentos, Confianza, etc.
 */
class UnifiedQueryEngine
{
    private $db;
    private $settings;
    private $lastLogId = 0;
    private $telegram_mode = false;
    
    public function __construct($db)
    {
        $this->db = $db;
        $this->loadSettings();
    }
    
    private function loadSettings(): void
    {
        $this->settings = [];
        $query = "SELECT name, value FROM settings";
        $result = $this->db->query($query);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $this->settings[$row['name']] = $row['value'];
            }
        }
    }
    
    /**
     * Configuración específica para Telegram Bot
     * Garantiza encontrar el último correo no leído
     */
    public function enableTelegramMode(): void
    {
        $this->telegram_mode = true;
        $this->logPerformance("TELEGRAM_MODE: Activado para garantizar último correo");
    }
    
    /**
     * SISTEMA DE LOGGING - IGUAL AL WEB
     */
    private function logPerformance($message) {
        $logging_enabled = ($this->settings['PERFORMANCE_LOGGING'] ?? '0') === '1';
        
        if ($logging_enabled) {
            error_log("PERFORMANCE: " . $message);
        }
    }
    
    /**
     * Busca emails para un correo y plataforma específicos
     * ✅ MISMO FLUJO QUE EL SISTEMA WEB + PROCESAMIENTO AVANZADO
     */
    public function searchEmails(string $email, string $platform, int $userId): array
    {
        try {
            // Validar parámetros
            $email = filter_var($email, FILTER_SANITIZE_EMAIL);
            $platform = trim($platform);
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->createErrorResponse('Email inválido');
            }
            
            if (empty($platform)) {
                return $this->createErrorResponse('Plataforma requerida');
            }
            
            // ✅ VALIDACIÓN IGUAL AL WEB: hasUserSubjectAccess
            if (!$this->hasUserSubjectAccess($userId, $platform)) {
                return $this->createErrorResponse('No tienes permisos para buscar en esta plataforma con los asuntos configurados.');
            }
            
            // Obtener asuntos para la plataforma
            $subjects = $this->getSubjectsForPlatform($platform);
            
            // ✅ FILTRADO IGUAL AL WEB: filterSubjectsForUser
            $subjects = $this->filterSubjectsForUser($userId, $platform, $subjects);
            
            if (empty($subjects)) {
                return $this->createErrorResponse('No tienes asuntos asignados para esta plataforma.');
            }
            
            // Obtener servidores habilitados
            $servers = $this->getEnabledServers();
            if (empty($servers)) {
                return $this->createErrorResponse('No hay servidores configurados');
            }
            
            // Registrar el intento de búsqueda
            $this->logSearchAttempt($userId, $email, $platform);
            
            // ✅ USAR MISMO FLUJO QUE EL WEB: searchInServers
            $result = $this->searchInServers($email, $subjects, $servers);
            
            // ✅ PROCESAMIENTO AVANZADO PARA TELEGRAM
            if ($this->telegram_mode && $result['found']) {
                $result = $this->procesarResultadosBusquedaMejorado($result, $platform);
            }
            
            // Actualizar log con resultado
            $this->updateSearchLog($this->lastLogId, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            error_log("Error en UnifiedQueryEngine::searchEmails: " . $e->getMessage());
            return $this->createErrorResponse('Error interno del servidor');
        }
    }
    
    /**
     * ✅ FUNCIÓN IGUAL AL SISTEMA WEB
     * Búsqueda en múltiples servidores con estrategia optimizada
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
     * ✅ FUNCIÓN CORREGIDA - MISMO FLUJO QUE WEB + PROCESAMIENTO AVANZADO
     * Búsqueda en un servidor individual
     */
    private function searchInSingleServer($email, $subjects, $server_config) {
        try {
            // Usar los nombres de campos correctos de tu BD
            $connectionString = sprintf(
                '{%s:%d/imap/ssl/novalidate-cert}INBOX',
                $server_config['imap_server'],
                $server_config['imap_port']
            );
            
            // Configurar timeout
            $timeout = (int)($this->settings['IMAP_CONNECTION_TIMEOUT'] ?? 10);
            $old_timeout = ini_get('default_socket_timeout');
            ini_set('default_socket_timeout', $timeout);
            
            // Abrir conexión
            $connection = @imap_open(
                $connectionString,
                $server_config['imap_user'],
                $server_config['imap_password']
            );
            
            if (!$connection) {
                $error = imap_last_error();
                error_log("Error conectando a " . $server_config['server_name'] . ": " . $error);
                return [
                    'found' => false, 
                    'error' => 'Error de conexión',
                    'message' => 'No se pudo conectar al servidor ' . $server_config['server_name'],
                    'type' => 'connection_error'
                ];
            }
            
            try {
                // ✅ USAR MISMO MÉTODO QUE EL WEB: executeSearch
                $email_ids = $this->executeSearch($connection, $email, $subjects);
                
                if (empty($email_ids)) {
                    return [
                        'found' => false,
                        'message' => '0 mensajes encontrados.',
                        'search_performed' => true,
                        'emails_found_count' => 0,
                        'type' => 'not_found'
                    ];
                }
                
                // Caso 2: SÍ encontró emails
                $emails_found_count = count($email_ids);
                $this->logPerformance("Encontrados {$emails_found_count} emails en servidor: " . $server_config['server_name']);
                
                // ✅ MODO TELEGRAM: PROCESAMIENTO AVANZADO CON EMAILS ESTRUCTURADOS
                if ($this->telegram_mode) {
                    // Ordenar por más recientes primero
                    rsort($email_ids);
                    
                    // Procesar emails y construir datos estructurados
                    $emails_data = [];
                    $processed_count = 0;
                    
                    foreach (array_slice($email_ids, 0, 3) as $email_id) {
                        try {
                            $header = imap_headerinfo($connection, $email_id);
                            if (!$header) continue;
                            
                            $email_data = $this->buildEmailDataAdvanced($connection, $email_id, $header);
                            if ($email_data) {
                                $emails_data[] = $email_data;
                                $processed_count++;
                            }
                        } catch (Exception $e) {
                            continue;
                        }
                    }
                    
                    if (!empty($emails_data)) {
                        return [
                            'found' => true,
                            'content' => '', // Para compatibilidad
                            'server' => $server_config['server_name'],
                            'emails_found_count' => $emails_found_count,
                            'emails_processed' => $processed_count,
                            'emails' => $emails_data,
                            'type' => 'success'
                        ];
                    }
                }
                
                // ✅ MODO WEB: Procesamiento estándar
                $emails_processed = 0;
                $last_error = '';
                
                // Ordenar por más recientes primero
                rsort($email_ids);
                
                // Intentar procesar hasta 3 emails recientes
                $max_attempts = min(3, $emails_found_count);
                
                for ($i = 0; $i < $max_attempts; $i++) {
                    try {
                        $email_content = $this->processFoundEmail($connection, $email_ids[$i]);
                        
                        if ($email_content) {
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
                if ($connection) {
                    imap_close($connection);
                }
                ini_set('default_socket_timeout', $old_timeout);
            }
            
        } catch (\Exception $e) {
            error_log("Error buscando en servidor {$server_config['server_name']}: " . $e->getMessage());
            return ['found' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * ✅ FUNCIÓN IGUAL AL SISTEMA WEB
     * Ejecución de búsqueda con múltiples estrategias
     */
    private function executeSearch($inbox, $email, $subjects) {
        // Estrategia 1: Búsqueda optimizada
        $emails = $this->searchOptimized($inbox, $email, $subjects);

        if (!empty($emails)) {
            return $emails;
        }
        
        // Estrategia 2: Búsqueda simple (fallback)
        $result = $this->searchSimple($inbox, $email, $subjects);
        $this->logPerformance("Búsqueda simple encontró: " . count($result) . " emails");
        return $result;
    }
    
    /**
     * ✅ FUNCIÓN CORREGIDA - IGUAL AL SISTEMA WEB
     * Búsqueda optimizada con IMAP - MEJORADA para zonas horarias
     */
    private function searchOptimized($inbox, $email, $subjects) {
        try {
            // ✅ USAR CONFIGURACIÓN DINÁMICA IGUAL AL WEB
            $search_hours = (int)($this->settings['TIMEZONE_DEBUG_HOURS'] ?? 48);
            $search_date = date("d-M-Y", time() - ($search_hours * 3600));
            
            $this->logPerformance("Búsqueda ampliada: últimas {$search_hours}h desde " . $search_date);
            
            // Construir criterio de búsqueda con rango amplio
            $criteria = 'TO "' . $email . '" SINCE "' . $search_date . '"';
            $all_emails = imap_search($inbox, $criteria);
            
            if (!$all_emails) {
                $this->logPerformance("No se encontraron emails en rango amplio para: " . $email);
                return [];
            }

            $this->logPerformance("Emails encontrados en rango amplio: " . count($all_emails));

            // ✅ USAR MISMO FILTRADO QUE EL WEB
            $filtered = $this->filterEmailsByTimeAndSubject($inbox, $all_emails, $subjects);
            return $filtered;
            
        } catch (Exception $e) {
            $this->logPerformance("Error en búsqueda optimizada: " . $e->getMessage());
            return [];
        }
    }

    /**
     * ✅ FUNCIÓN IGUAL AL SISTEMA WEB
     * Búsqueda simple (fallback confiable)
     */
    private function searchSimple($inbox, $email, $subjects) {
        try {
            $this->logPerformance("Iniciando búsqueda simple (fallback)");
            
            // Usar búsqueda amplia sin restricción de fecha como fallback
            $criteria = 'TO "' . $email . '"';
            $all_emails = imap_search($inbox, $criteria);
            
            if (!$all_emails) {
                $this->logPerformance("No se encontraron emails en búsqueda simple");
                return [];
            }
            
            $this->logPerformance("Búsqueda simple encontró: " . count($all_emails) . " emails totales");

            // Ordenar por más recientes y limitar para performance
            rsort($all_emails);
            $emails_to_check = array_slice($all_emails, 0, 30);

            // ✅ USAR MISMO FILTRADO QUE EL WEB
            return $this->filterEmailsByTimeAndSubject($inbox, $emails_to_check, $subjects);
            
        } catch (Exception $e) {
            $this->logPerformance("Error en búsqueda simple: " . $e->getMessage());
            return [];
        }
    }

    /**
     * ✅ FUNCIÓN CORREGIDA - IGUAL AL SISTEMA WEB
     * Filtrar emails por tiempo preciso y asunto
     */
    private function filterEmailsByTimeAndSubject($inbox, $email_ids, $subjects) {
        $found_emails = [];
        
        // ✅ USAR CONFIGURACIONES DINÁMICAS IGUAL AL WEB
        $max_check = (int)($this->settings['MAX_EMAILS_TO_CHECK'] ?? 50);
        $time_limit_minutes = (int)($this->settings['EMAIL_QUERY_TIME_LIMIT_MINUTES'] ?? 30);
        $cutoff_timestamp = time() - ($time_limit_minutes * 60);
        
        $this->logPerformance("Filtrando emails: límite " . $time_limit_minutes . " minutos, timestamp corte: " . date('Y-m-d H:i:s', $cutoff_timestamp));
        
        // Ordenar emails por más recientes primero
        rsort($email_ids);
        $emails_to_check = array_slice($email_ids, 0, $max_check);
        
        $checked_count = 0;
        $time_filtered_count = 0;
        $subject_matched_count = 0;
        
        // ✅ LÓGICA ESPECIAL PARA TELEGRAM (RECOPILAR TODOS)
        $emails_validos = [];
        
        foreach ($emails_to_check as $email_id) {
            try {
                $checked_count++;
                $header = imap_headerinfo($inbox, $email_id);
                
                if (!$header || !isset($header->date)) {
                    continue;
                }
                
                // ✅ MISMO PARSEO QUE EL SISTEMA WEB
                $email_timestamp = $this->parseEmailTimestamp($header->date);
                if ($email_timestamp === false) {
                    $this->logPerformance("No se pudo parsear fecha: " . $header->date);
                    continue;
                }
                
                // ✅ MISMO FILTRO DE TIEMPO QUE EL SISTEMA WEB
                if ($email_timestamp < $cutoff_timestamp) {
                    continue;
                }
                
                $time_filtered_count++;
                $email_age_minutes = round((time() - $email_timestamp) / 60, 1);
                $this->logPerformance("Email válido por tiempo: " . date('Y-m-d H:i:s', $email_timestamp) . " (hace " . $email_age_minutes . " min)");
                
                // ✅ MISMO FILTRO DE ASUNTO QUE EL SISTEMA WEB
                if (!isset($header->subject)) {
                    continue;
                }
                
                $decoded_subject = $this->decodeMimeSubject($header->subject);
                
                foreach ($subjects as $subject) {
                    if ($this->subjectMatches($decoded_subject, $subject)) {
                        
                        if ($this->telegram_mode) {
                            // MODO TELEGRAM: Guardar todos los válidos para ordenar por timestamp
                            $emails_validos[] = [
                                'email_id' => $email_id,
                                'timestamp' => $email_timestamp,
                                'date_str' => $header->date,
                                'subject' => $decoded_subject
                            ];
                            $this->logPerformance("TELEGRAM_DEBUG: Email válido - ID:$email_id, Timestamp:" . date('Y-m-d H:i:s', $email_timestamp));
                        } else {
                            // MODO WEB: Early stopping normal
                            $found_emails[] = $email_id;
                            $subject_matched_count++;
                            
                            $this->logPerformance("¡MATCH! Asunto: '" . substr($decoded_subject, 0, 50) . "...' con patrón: '" . substr($subject, 0, 30) . "...'");
                            
                            // Early stop si está habilitado
                            if (($this->settings['EARLY_SEARCH_STOP'] ?? '1') === '1') {
                                $this->logPerformance("Early stop activado, deteniendo búsqueda");
                                return $found_emails;
                            }
                        }
                        break;
                    }
                }
                
            } catch (Exception $e) {
                $this->logPerformance("Error procesando email ID " . $email_id . ": " . $e->getMessage());
                continue;
            }
        }
        
        // ✅ LÓGICA ESPECIAL PARA TELEGRAM: DEVOLVER EL MÁS RECIENTE
        if ($this->telegram_mode && !empty($emails_validos)) {
            // Ordenar por timestamp real (más reciente primero)
            usort($emails_validos, function($a, $b) {
                return $b['timestamp'] - $a['timestamp'];
            });
            
            $email_mas_reciente = $emails_validos[0];
            $this->logPerformance("TELEGRAM_DEBUG: Email más reciente seleccionado - ID:" . $email_mas_reciente['email_id'] . ", Fecha:" . date('Y-m-d H:i:s', $email_mas_reciente['timestamp']));
            
            return [$email_mas_reciente['email_id']];
        }
        
        $this->logPerformance("Resumen filtrado - Revisados: $checked_count, Válidos por tiempo: $time_filtered_count, Con asunto coincidente: $subject_matched_count");
        
        return $found_emails;
    }
    
    // ===============================================
    // ✅ FUNCIONES AVANZADAS DE PROCESAMIENTO - COMPLETAS
    // ===============================================
    
    /**
     * ✅ PROCESAMIENTO AVANZADO DE RESULTADOS - COMPLETO
     * Procesa emails con detección de códigos Y enlaces
     */
    private function procesarResultadosBusquedaMejorado($resultado, $plataforma = '') {
        if (!$resultado['found']) {
            return $resultado;
        }
        
        if (!isset($resultado['emails'])) {
            $resultado['emails'] = [];
        }
        
        foreach ($resultado['emails'] as $index => $emailData) {
            $this->logPerformance("=== PROCESANDO EMAIL $index ===");
            $this->logPerformance("Subject: " . substr($emailData['subject'] ?? 'Sin asunto', 0, 50));
            
            // 1. LIMPIAR CONTENIDO CON FUNCIÓN AVANZADA
            $bodyLimpio = $this->limpiarContenidoEmail($emailData['body'] ?? '');
            $emailData['body_clean'] = $bodyLimpio;
            
            $this->logPerformance("Contenido limpio (200 chars): " . substr($bodyLimpio, 0, 200));
            
            // 2. EXTRAER CÓDIGO/ENLACE CON FUNCIÓN AVANZADA
            $codigoInfo = $this->extraerCodigoOEnlaceMejorado($bodyLimpio, $emailData['subject'] ?? '');
            
            if ($codigoInfo['tipo'] === 'codigo') {
                $emailData['verification_code'] = $codigoInfo['valor'];
                $emailData['tipo_acceso'] = 'codigo';
                $emailData['confianza_deteccion'] = $codigoInfo['confianza'];
                $emailData['fragmento_deteccion'] = $this->extraerContextoCompletoEmail(
                    $emailData['body'] ?? '', 
                    $emailData['subject'] ?? '', 
                    $codigoInfo['valor'], 
                    $plataforma
                );
                $emailData['patron_usado'] = $codigoInfo['patron'] ?? 0;
                
                $this->logPerformance("✅ CÓDIGO DETECTADO: " . $codigoInfo['valor'] . " (confianza: " . $codigoInfo['confianza'] . ")");
                if (!empty($emailData['fragmento_deteccion'])) {
                    $this->logPerformance("✅ FRAGMENTO GUARDADO: " . substr($emailData['fragmento_deteccion'], 0, 100));
                }
                
            } elseif ($codigoInfo['tipo'] === 'enlace') {
                $emailData['access_link'] = $codigoInfo['valor'];
                $emailData['tipo_acceso'] = 'enlace';
                $emailData['confianza_deteccion'] = $codigoInfo['confianza'];
                $emailData['fragmento_deteccion'] = $codigoInfo['fragmento'] ?? '';
                
                $this->logPerformance("✅ ENLACE DETECTADO: " . substr($codigoInfo['valor'], 0, 50));
                if (!empty($emailData['fragmento_deteccion'])) {
                    $this->logPerformance("✅ FRAGMENTO GUARDADO: " . substr($emailData['fragmento_deteccion'], 0, 100));
                }
                
            } else {
                $this->logPerformance("⚠️ NO SE DETECTÓ CÓDIGO NI ENLACE");
            }
            
            // 3. MEJORAR REMITENTE
            $emailData['from'] = $this->extraerRemitenteEmail($emailData);
            $this->logPerformance("✅ REMITENTE: " . $emailData['from']);
            
            // 4. CREAR VISTA PREVIA MEJORADA
            $emailData['body_preview'] = $this->crearVistaPreviaConFormato($bodyLimpio);
            
            $this->logPerformance("=== EMAIL PROCESADO ===");
            $this->logPerformance("From: " . $emailData['from']);
            $this->logPerformance("Tipo: " . ($emailData['tipo_acceso'] ?? 'ninguno'));
            $this->logPerformance("Tiene fragmento: " . (isset($emailData['fragmento_deteccion']) ? 'SÍ' : 'NO'));
            
            // ✅ CRÍTICO: Guardar los cambios de vuelta al array original
            $resultado['emails'][$index] = $emailData;
        }
        
        return $resultado;
    }
    
    /**
     * ✅ CONSTRUCCIÓN AVANZADA DE DATOS DE EMAIL
     * Construye email con toda la información necesaria
     */
    private function buildEmailDataAdvanced($connection, $email_id, $header): ?array
    {
        try {
            $emailData = [
                'email_id' => $email_id,
                'date' => $header->date ?? '',
                'subject' => $this->decodeMimeSubject($header->subject ?? ''),
                'from' => $this->extraerFromHeader($header)
            ];
            
            // Obtener cuerpo del email
            $body = imap_body($connection, $email_id);
            if ($body) {
                $emailData['body'] = $body;
                $bodyLimpio = $this->limpiarContenidoEmail($body);
                $emailData['body_clean'] = $bodyLimpio;
                
                // Extraer código o enlace
                $codigoInfo = $this->extraerCodigoOEnlaceMejorado($bodyLimpio, $emailData['subject']);
                
                if ($codigoInfo['tipo'] === 'codigo') {
                    $emailData['verification_code'] = $codigoInfo['valor'];
                    $emailData['tipo_acceso'] = 'codigo';
                    $emailData['confianza_deteccion'] = $codigoInfo['confianza'];
                    $emailData['fragmento_deteccion'] = $codigoInfo['fragmento'] ?? '';
                    $emailData['patron_usado'] = $codigoInfo['patron'] ?? 0;
                    
                } elseif ($codigoInfo['tipo'] === 'enlace') {
                    $emailData['access_link'] = $codigoInfo['valor'];
                    $emailData['tipo_acceso'] = 'enlace';
                    $emailData['confianza_deteccion'] = $codigoInfo['confianza'];
                    $emailData['fragmento_deteccion'] = $codigoInfo['fragmento'] ?? '';
                }
                
                // Mejorar remitente y vista previa
                $emailData['from'] = $this->extraerRemitenteEmail($emailData);
                $emailData['body_preview'] = $this->crearVistaPreviaConFormato($bodyLimpio);
            }
            
            return $emailData;
            
        } catch (\Exception $e) {
            error_log("Error construyendo datos de email: " . $e->getMessage());
            return null;
        }
    }
    
    /**
 * ✅ EXTRACCIÓN AVANZADA DE CÓDIGOS Y ENLACES - CON PRIORIDAD PARA NETFLIX
 * Detecta enlaces de Netflix primero, luego códigos, y finalmente otros enlaces.
 */
private function extraerCodigoOEnlaceMejorado($body, $subject = '') {
    $textCompleto = $subject . ' ' . $body;

    // =========================================================
    // PASO 1: (MÁXIMA PRIORIDAD) DETECCIÓN DE ENLACE DE NETFLIX
    // =========================================================
    $enlaceInfo = detectNetflixLink($body);

    if ($enlaceInfo && filter_var($enlaceInfo['enlace'], FILTER_VALIDATE_URL)) {
        $posicion = $enlaceInfo['posicion'];
        $enlace   = $enlaceInfo['enlace'];
        $fragmento = $this->extraerFragmentoContexto($body, $posicion, $enlace);

        $this->logPerformance("ENLACE NETFLIX PRIORITARIO DETECTADO: " . substr($enlace, 0, 70));
        return [
            'tipo'      => 'enlace',
            'valor'     => $enlace,
            'confianza' => 'alta', // Confianza alta por ser un patrón específico
            'fragmento' => $fragmento,
            'posicion'  => $posicion,
            'patron'    => $enlaceInfo['patron'] ?? 0
        ];
    }

    // ===========================================
    // PASO 2: DETECCIÓN DE CÓDIGOS NUMÉRICOS
    // ===========================================
    $patronesCodigo = [
        // Patrón específico para códigos extraídos de HTML
        '/CODIGO_ENCONTRADO:\s*(\d{4,8})/i',
        
        // Extraer código del subject si está explícito (ChatGPT style)
        '/(?:code|código)\s+(?:is|es)\s+(\d{4,8})/i',
        '/passcode\s*(?:is|es|:)?\s*(\d{4,8})/iu',
        
        // Patrones generales mejorados con más variaciones
        '/(?:código|code|passcode|verification|verificación|otp|pin|access|acceso)[\s:]*(\d{4,8})/iu',
        '/(?:your|tu|el|su)\s+(?:código|code|passcode|verification|otp|pin)[\s:]*(\d{4,8})/iu',
        '/(?:enter|ingresa|introduce|usa|use)\s+(?:this|este|el|the)?\s*(?:code|código|passcode)[\s:]*(\d{4,8})/iu',
        
        // Servicios específicos con contexto
        '/disney\+?.*?(\d{6})/i',
        '/netflix.*?(\d{4,6})/i',
        '/amazon.*?(\d{6})/i',
        '/microsoft.*?(\d{6})/i',
        '/google.*?(\d{6})/i',
        '/apple.*?(\d{6})/i',
        '/chatgpt.*?(\d{6})/i',
        '/openai.*?(\d{6})/i',
        
        // Contexto español mejorado
        '/(?:acceso|inicio|sesión|verificar|verifica).*?(\d{4,8})/iu',
        '/(?:expira|vence|válido|temporal).*?(\d{4,8})/iu',
        '/(?:solicitud|dispositivo).*?(\d{4,8})/iu',
        
        // Patrones específicos por longitud y contexto
        '/\b(\d{6})\b(?=\s*(?:is|es|será|will|expires|vence|válido|valid|temporal|minutos))/iu',
        '/\b(\d{6})\b(?!\d)/', // 6 dígitos aislados (más comunes)
        '/\b(\d{5})\b(?=\s*(?:is|es|será|will|expires|vence|válido|valid|temporal|minutos))/iu',
        '/\b(\d{4})\b(?=\s*(?:is|es|será|will|expires|vence|válido|valid|temporal|minutos))/iu',
        
        // Fallback para 4-8 dígitos en contexto
        '/\b(\d{4,8})\b(?=\s*(?:to|para|sign|log|access|acceder|iniciar))/iu',
        
        // Último recurso: cualquier secuencia de 4-8 dígitos
        '/\b(\d{4,8})\b/',
    ];
    
    foreach ($patronesCodigo as $i => $patron) {
        if (preg_match($patron, $textCompleto, $matches, PREG_OFFSET_CAPTURE)) {
            $codigo = $matches[1][0];
            $longitud = strlen($codigo);

            if ($longitud >= 4 && $longitud <= 8) {
                $posicion = $matches[1][1];
                $confianza = $i < 8 ? 'alta' : ($i < 15 ? 'media' : 'baja');
                $fragmento = $this->extraerFragmentoContexto($textCompleto, $posicion, $codigo);
                
                $this->logPerformance("CÓDIGO DETECTADO: $codigo (patrón $i, confianza $confianza)");
                return [
                    'tipo'      => 'codigo',
                    'valor'     => $codigo,
                    'confianza' => $confianza,
                    'patron'    => $i,
                    'fragmento' => $fragmento,
                    'posicion'  => $posicion
                ];
            }
        }
    }

    // =========================================================
    // PASO 3: (FALLBACK) DETECCIÓN DE OTROS ENLACES
    // =========================================================
    $patronesEnlaceGenericos = [
        // Servicios específicos con verificación
        '/(https?:\/\/[^\s\)]+(?:verify|verification|code|codigo|passcode|auth|login|access)[^\s\)]*)/i',
        
        // Enlaces con texto descriptivo en español e inglés
        '/(?:click|press|tap|toca|pulsa|accede|obtener|get)\s+(?:here|aquí|below|abajo|button|botón|código|code|passcode)[^.]*?(https?:\/\/[^\s\)]+)/i',
        '/(?:verify|verifica|confirm|confirma|access|acceder)[^.]*?(https?:\/\/[^\s\)]+)/i',
        '/(?:get|obtener|generate|generar)\s+(?:code|código|passcode)[^.]*?(https?:\/\/[^\s\)]+)/i',
        
        // Enlaces en HTML
        '/href=["\']([^"\']+(?:verify|access|login|auth|code|codigo|passcode|travel)[^"\']*)["\']/',
        '/href=["\']([^"\']+)["\'][^>]*>.*?(?:verify|verifica|código|code|passcode|access|obtener|get)/i',
        
        // Servicios específicos (dominios conocidos)
        '/(https?:\/\/(?:[^\/\s]+\.)?(?:disney|amazon|microsoft|google|apple|openai)\.com[^\s]*(?:verify|code|auth|login|travel|access)[^\s]*)/i',
        
        // Enlaces genéricos en contextos de verificación
        '/(https?:\/\/[^\s\)]+)(?=\s*.*(?:verify|code|passcode|access|login|temporal|vence))/i',
    ];

    foreach ($patronesEnlaceGenericos as $patron) {
        if (preg_match($patron, $body, $matches, PREG_OFFSET_CAPTURE)) {
            $enlace = isset($matches[1]) ? $matches[1][0] : $matches[0][0];
            $posicion = isset($matches[1]) ? $matches[1][1] : $matches[0][1];
            $enlace = trim($enlace, '"\'<>()[]');

            if (filter_var($enlace, FILTER_VALIDATE_URL)) {
                $fragmento = $this->extraerFragmentoContexto($body, $posicion, $enlace);
                $this->logPerformance("ENLACE GENÉRICO DETECTADO: " . substr($enlace, 0, 50));
                return [
                    'tipo'      => 'enlace',
                    'valor'     => $enlace,
                    'confianza' => 'media',
                    'fragmento' => $fragmento,
                    'posicion'  => $posicion
                ];
            }
        }
    }
    
    // Si no se encuentra nada
    $this->logPerformance("NO SE DETECTÓ CONTENIDO PRIORITARIO en: " . substr($textCompleto, 0, 100));
    return ['tipo' => 'ninguno', 'valor' => '', 'confianza' => 'ninguna'];
}
    
    /**
     * ✅ LIMPIEZA AVANZADA DE CONTENIDO - COMPLETA
     * Limpia y extrae contenido relevante de emails
     */
    private function limpiarContenidoEmail($body) {
        if (empty($body)) return '';
        
        // 1. Decodificar quoted-printable si está presente
        if (strpos($body, '=') !== false && strpos($body, '=\r\n') !== false) {
            $body = quoted_printable_decode($body);
        }
        
        // 2. Decodificar entidades HTML
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // 3. NUEVO: Usar extractor inteligente de texto
        if (strpos($body, '<') !== false) {
            // Intentar extraer usando el método específico primero
            $textoLimpio = $this->extraerTextoLimpioParaUsuario($body);
            if (!empty($textoLimpio)) {
                return $textoLimpio;
            }
            
            // Fallback al método original mejorado
            $body = $this->extraerTextoImportanteHTML($body);
            $body = strip_tags($body);
        }
        
        // 4. Limpiar caracteres especiales y espacios
        $body = preg_replace('/\s+/', ' ', $body);
        $body = preg_replace('/[\x00-\x1F\x7F-\x9F]/', '', $body);
        $body = trim($body);
        
        return $body;
    }
    
    /**
     * ✅ EXTRACTOR DE TEXTO IMPORTANTE DE HTML - COMPLETO
     * Busca códigos en HTML antes de limpiar tags
     */
    private function extraerTextoImportanteHTML($html) {
        $textImportant = '';
        
        // Buscar patrones comunes para códigos en HTML
        $patronesHTML = [
            // Disney+ - TD con estilos específicos (font-size grande y letter-spacing)
            '/<td[^>]*font-size:\s*(?:2[4-9]|[3-9]\d)px[^>]*letter-spacing[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/td>/i',
            
            // Amazon - TD con clase 'data' específica
            '/<td[^>]*class="data"[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/td>/i',
            
            // Netflix - TD con clase 'copy lrg-number'
            '/<td[^>]*class="[^"]*lrg-number[^"]*"[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/td>/i',
            
            // ChatGPT/OpenAI - H1 con códigos
            '/<h1[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/h1>/i',
            
            // Genérico - TD con font-size grande
            '/<td[^>]*font-size:\s*(?:2[4-9]|[3-9]\d)px[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/td>/i',
            
            // Números grandes con letra-spacing
            '/<[^>]*letter-spacing[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/[^>]*>/i',
            
            // Divs o spans con clases que sugieren códigos
            '/<(?:div|span|p)[^>]*(?:code|codigo|verification|otp|pin)[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/(?:div|span|p)>/i',
            
            // Headers (H1-H6) con códigos
            '/<h[1-6][^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/h[1-6]>/i',
            
            // Texto en negrita o destacado
            '/<(?:b|strong|em)[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/(?:b|strong|em)>/i',
            
            // Buscar en atributos alt o title
            '/(?:alt|title)=["\'][^"\']*(\d{4,8})[^"\']*["\']/i',
        ];
        
        foreach ($patronesHTML as $patron) {
            if (preg_match_all($patron, $html, $matches)) {
                foreach ($matches[1] as $match) {
                    $textImportant .= " CODIGO_ENCONTRADO: $match ";
                }
            }
        }
        
        return $textImportant . $html;
    }
    
    /**
     * ✅ EXTRACTOR DE TEXTO LIMPIO PARA USUARIO - COMPLETO
     * Extrae solo contenido relevante y legible
     */
    private function extraerTextoLimpioParaUsuario($html, $subject = '') {
        if (empty($html)) return '';
        
        // 1. Eliminar elementos que nunca queremos mostrar
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $html);
        
        // 2. Buscar contenido específico por servicio ANTES de limpiar
        $contenidoEspecifico = $this->extraerContenidoPorServicio($html, $subject);
        if (!empty($contenidoEspecifico)) {
            return $contenidoEspecifico;
        }
        
        // 3. Extraer texto de elementos importantes (preservando estructura)
        $textoImportante = '';
        
        // Patrones para extraer contenido relevante por orden de importancia
        $patronesContenido = [
            // H1-H3 con códigos o texto relevante
            '/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is',
            
            // Párrafos con códigos o palabras clave
            '/<p[^>]*>(.*?(?:código|code|verification|acceso|expira|minutos|disney|netflix|amazon).*?)<\/p>/is',
            
            // Divs con clases importantes
            '/<div[^>]*(?:code|verification|main|content)[^>]*>(.*?)<\/div>/is',
            
            // TDs con contenido relevante
            '/<td[^>]*>(.*?(?:\d{4,8}|código|code|verification).*?)<\/td>/is',
            
            // Spans importantes
            '/<span[^>]*>(.*?(?:\d{4,8}|código|expira|minutos).*?)<\/span>/is',
        ];
        
        foreach ($patronesContenido as $patron) {
            if (preg_match_all($patron, $html, $matches)) {
                foreach ($matches[1] as $match) {
                    $textoLimpio = strip_tags($match);
                    $textoLimpio = html_entity_decode($textoLimpio, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $textoLimpio = preg_replace('/\s+/', ' ', trim($textoLimpio));
                    
                    if (preg_match('/\b\d{4,8}\b/', $textoLimpio, $codMatch)) {
                        $textoImportante .= " CODIGO_ENCONTRADO: {$codMatch[0]} ";
                    }

                    if (strlen($textoLimpio) > 10) {
                        $textoImportante .= $textoLimpio . ' ';
                    }
                }
            }
        }
        
        // 4. Si no encontramos nada específico, usar método general mejorado
        if (empty($textoImportante)) {
            $html = strip_tags($html);
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html = preg_replace('/\s+/', ' ', $html);
            $textoImportante = $html;
        }
        
        return trim($textoImportante);
    }
    
    /**
     * ✅ EXTRACTOR DE CONTEXTO COMPLETO - COMPLETO
     * Extrae contexto relevante según la plataforma
     */
    private function extraerContextoCompletoEmail($body, $subject, $codigo, $plataforma) {
        // 1. Limpiar el body primero
        $bodyLimpio = $body;
        
        // Decodificar quoted-printable
        if (strpos($bodyLimpio, '=') !== false && preg_match('/=[0-9A-F]{2}/', $bodyLimpio)) {
            $bodyLimpio = quoted_printable_decode($bodyLimpio);
        }
        
        // Decodificar entidades HTML
        $bodyLimpio = html_entity_decode($bodyLimpio, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Si hay HTML, extraer solo el texto
        if (strpos($bodyLimpio, '<') !== false) {
            $bodyLimpio = strip_tags($bodyLimpio);
        }
        
        // Limpiar espacios múltiples
        $bodyLimpio = preg_replace('/\s+/', ' ', $bodyLimpio);
        $bodyLimpio = trim($bodyLimpio);
        
        // 2. Detectar plataforma si no se especifica
        if (empty($plataforma)) {
            if (preg_match('/disney/i', $subject)) {
                $plataforma = 'Disney+';
            } elseif (preg_match('/netflix/i', $subject)) {
                $plataforma = 'Netflix';
            } elseif (preg_match('/amazon/i', $subject)) {
                $plataforma = 'Amazon';
            }
        }
        
        // 3. Extraer según la plataforma
        switch (strtolower($plataforma)) {
            case 'disney+':
            case 'disney':
                return $this->extraerContextoDisney($bodyLimpio, $subject, $codigo);
            case 'netflix':
                return $this->extraerContextoNetflix($bodyLimpio, $subject, $codigo);
            case 'amazon':
                return $this->extraerContextoAmazon($bodyLimpio, $subject, $codigo);
            default:
                return $this->extraerContextoGenerico($bodyLimpio, $subject, $codigo);
        }
    }
    
    /**
     * ✅ EXTRACTOR DE FRAGMENTO DE CONTEXTO - COMPLETO
     * Extrae fragmento alrededor del código/enlace encontrado
     */
    private function extraerFragmentoContexto($texto, $posicion, $valorEncontrado) {
        // 1. PRIMERO: Intentar extraer usando el método específico por servicio
        $textoLimpio = $this->extraerTextoLimpioParaUsuario($texto);
        
        // 2. Si el texto limpio contiene el valor, usarlo como base
        if (strpos($textoLimpio, $valorEncontrado) !== false) {
            $texto = $textoLimpio;
            // Recalcular posición en el texto limpio
            $posicion = strpos($texto, $valorEncontrado);
            if ($posicion === false) $posicion = 0;
        }
        
        $longitudTexto = strlen($texto);
        $longitudValor = strlen($valorEncontrado);
        
        // 3. Buscar una oración completa que contenga el código
        $oracionCompleta = $this->extraerOracionCompleta($texto, $posicion, $valorEncontrado);
        if (!empty($oracionCompleta)) {
            return $this->limpiarFragmentoCompleto($oracionCompleta, $valorEncontrado);
        }
        
        // 4. Fallback al método original pero con contexto más pequeño
        $contextoAntes = 60;
        $contextoDespues = 60;
        
        $inicio = max(0, $posicion - $contextoAntes);
        $fin = min($longitudTexto, $posicion + $longitudValor + $contextoDespues);
        
        $fragmento = substr($texto, $inicio, $fin - $inicio);
        
        // Agregar indicadores si se cortó
        if ($inicio > 0) {
            $fragmento = '...' . $fragmento;
        }
        if ($fin < $longitudTexto) {
            $fragmento = $fragmento . '...';
        }
        
        return $this->limpiarFragmentoCompleto($fragmento, $valorEncontrado);
    }
    
    // ===============================================
    // ✅ FUNCIONES AUXILIARES - COMPLETAS
    // ===============================================
    
    private function extraerContenidoPorServicio($html, $subject) {
        $servicioDetectado = '';
        
        // Detectar servicio por subject
        if (preg_match('/disney/i', $subject)) {
            $servicioDetectado = 'disney';
        } elseif (preg_match('/netflix/i', $subject)) {
            $servicioDetectado = 'netflix';
        } elseif (preg_match('/amazon/i', $subject)) {
            $servicioDetectado = 'amazon';
        } elseif (preg_match('/microsoft|outlook|xbox/i', $subject)) {
            $servicioDetectado = 'microsoft';
        } elseif (preg_match('/google|gmail/i', $subject)) {
            $servicioDetectado = 'google';
        } elseif (preg_match('/apple|icloud/i', $subject)) {
            $servicioDetectado = 'apple';
        } elseif (preg_match('/chatgpt|openai/i', $subject)) {
            $servicioDetectado = 'openai';
        }
        
        switch ($servicioDetectado) {
            case 'disney':
                return $this->extraerContenidoDisney($html);
            case 'netflix':
                return $this->extraerContenidoNetflix($html);
            case 'amazon':
                return $this->extraerContenidoAmazon($html);
            case 'microsoft':
                return $this->extraerContenidoMicrosoft($html);
            case 'google':
                return $this->extraerContenidoGoogle($html);
            case 'apple':
                return $this->extraerContenidoApple($html);
            case 'openai':
                return $this->extraerContenidoOpenAI($html);
            default:
                return '';
        }
    }
    
    private function extraerContenidoDisney($html) {
        $patrones = [
            '/Es necesario que verifiques.*?(\d{4,8}).*?minutos\./is',
            '/código de acceso único.*?(\d{4,8}).*?minutos\./is',
            '/verificar.*?cuenta.*?(\d{4,8}).*?vencer/is',
        ];
        
        foreach ($patrones as $patron) {
            if (preg_match($patron, $html, $matches)) {
                $contenido = strip_tags($matches[0]);
                $contenido = html_entity_decode($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $contenido = preg_replace('/\s+/', ' ', trim($contenido));
                return $contenido;
            }
        }
        
        return '';
    }
    
    private function extraerContenidoNetflix($html) {
        $patrones = [
            '/código de inicio de sesión.*?(\d{4,8})/is',
            '/verificación.*?(\d{4,8}).*?minutos/is',
            '/acceso temporal.*?(\d{4,8})/is',
        ];
        
        foreach ($patrones as $patron) {
            if (preg_match($patron, $html, $matches)) {
                $contenido = strip_tags($matches[0]);
                $contenido = html_entity_decode($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $contenido = preg_replace('/\s+/', ' ', trim($contenido));
                return $contenido;
            }
        }
        
        return '';
    }
    
    private function extraerContenidoAmazon($html) {
        $patrones = [
            '/código de verificación.*?(\d{4,8})/is',
            '/Amazon.*?(\d{4,8}).*?verificar/is',
            '/Prime.*?(\d{4,8}).*?acceso/is',
        ];
        
        foreach ($patrones as $patron) {
            if (preg_match($patron, $html, $matches)) {
                $contenido = strip_tags($matches[0]);
                $contenido = html_entity_decode($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $contenido = preg_replace('/\s+/', ' ', trim($contenido));
                return $contenido;
            }
        }
        
        return '';
    }
    
    private function extraerContenidoMicrosoft($html) {
        $patrones = [
            '/Microsoft.*?(\d{4,8}).*?verificar/is',
            '/código de seguridad.*?(\d{4,8})/is',
            '/Outlook.*?(\d{4,8})/is',
        ];
        
        foreach ($patrones as $patron) {
            if (preg_match($patron, $html, $matches)) {
                $contenido = strip_tags($matches[0]);
                $contenido = html_entity_decode($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $contenido = preg_replace('/\s+/', ' ', trim($contenido));
                return $contenido;
            }
        }
        
        return '';
    }
    
    private function extraerContenidoGoogle($html) {
        $patrones = [
            '/Google.*?(\d{4,8}).*?verificar/is',
            '/código de verificación.*?(\d{4,8})/is',
            '/Gmail.*?(\d{4,8})/is',
        ];
        
        foreach ($patrones as $patron) {
            if (preg_match($patron, $html, $matches)) {
                $contenido = strip_tags($matches[0]);
                $contenido = html_entity_decode($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $contenido = preg_replace('/\s+/', ' ', trim($contenido));
                return $contenido;
            }
        }
        
        return '';
    }
    
    private function extraerContenidoApple($html) {
        $patrones = [
            '/Apple.*?(\d{4,8}).*?verificar/is',
            '/iCloud.*?(\d{4,8})/is',
            '/código de verificación.*?(\d{4,8})/is',
        ];
        
        foreach ($patrones as $patron) {
            if (preg_match($patron, $html, $matches)) {
                $contenido = strip_tags($matches[0]);
                $contenido = html_entity_decode($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $contenido = preg_replace('/\s+/', ' ', trim($contenido));
                return $contenido;
            }
        }
        
        return '';
    }
    
    private function extraerContenidoOpenAI($html) {
        $patrones = [
            '/ChatGPT.*?(\d{4,8})/is',
            '/OpenAI.*?(\d{4,8})/is',
            '/código de verificación.*?(\d{4,8})/is',
        ];
        
        foreach ($patrones as $patron) {
            if (preg_match($patron, $html, $matches)) {
                $contenido = strip_tags($matches[0]);
                $contenido = html_entity_decode($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $contenido = preg_replace('/\s+/', ' ', trim($contenido));
                return $contenido;
            }
        }
        
        return '';
    }
    
    private function extraerContextoDisney($bodyLimpio, $subject, $codigo) {
        $contexto = "**" . $subject . "**\n\n";
        
        // Buscar el párrafo principal que contiene la explicación
        $patronPrincipal = '/(?:Es necesario|Necesitas|You need).*?(?:vencerá|expire|expir).*?(?:minutos|minutes)\.?/is';
        if (preg_match($patronPrincipal, $bodyLimpio, $matches)) {
            $contexto .= trim($matches[0]) . "\n\n";
        }
        
        // Agregar el código resaltado
        $contexto .= "**" . $codigo . "**\n\n";
        
        // Buscar información adicional
        $posicionCodigo = strpos($bodyLimpio, $codigo);
        if ($posicionCodigo !== false) {
            $despuesCodigo = substr($bodyLimpio, $posicionCodigo + strlen($codigo));
            
            $patronAdicional = '/[^.]*(?:solicitaste|Centro de ayuda|help|support|no request).*?\.?/i';
            if (preg_match($patronAdicional, $despuesCodigo, $matches)) {
                $infoAdicional = trim($matches[0]);
                if (!empty($infoAdicional)) {
                    $contexto .= $infoAdicional;
                }
            }
        }
        
        return trim($contexto);
    }
    
    private function extraerContextoNetflix($bodyLimpio, $subject, $codigo) {
        $contexto = "**" . $subject . "**\n\n";
        
        $patronPrincipal = '/(?:código|code).*?(?:Netflix|streaming|device).*?(?:minutos|minutes|expire)\.?/is';
        if (preg_match($patronPrincipal, $bodyLimpio, $matches)) {
            $contexto .= trim($matches[0]) . "\n\n";
        }
        
        $contexto .= "**" . $codigo . "**\n\n";
        
        $posicionCodigo = strpos($bodyLimpio, $codigo);
        if ($posicionCodigo !== false) {
            $despuesCodigo = substr($bodyLimpio, $posicionCodigo + strlen($codigo));
            $patronAdicional = '/[^.]*(?:expire|valid|válido|device).*?\.?/i';
            if (preg_match($patronAdicional, $despuesCodigo, $matches)) {
                $contexto .= trim($matches[0]);
            }
        }
        
        return trim($contexto);
    }
    
    private function extraerContextoAmazon($bodyLimpio, $subject, $codigo) {
        $contexto = "**" . $subject . "**\n\n";
        
        $patronPrincipal = '/(?:código|code).*?(?:Amazon|Prime|verification).*?\.?/is';
        if (preg_match($patronPrincipal, $bodyLimpio, $matches)) {
            $contexto .= trim($matches[0]) . "\n\n";
        }
        
        $contexto .= "**" . $codigo . "**\n\n";
        
        return trim($contexto);
    }
    
    private function extraerContextoGenerico($bodyLimpio, $subject, $codigo) {
        $contexto = "**" . $subject . "**\n\n";
        
        $posicionCodigo = strpos($bodyLimpio, $codigo);
        if ($posicionCodigo !== false) {
            $inicio = max(0, $posicionCodigo - 200);
            $fin = min(strlen($bodyLimpio), $posicionCodigo + strlen($codigo) + 200);
            $fragmento = substr($bodyLimpio, $inicio, $fin - $inicio);
            
            $fragmento = trim($fragmento);
            $contexto .= $fragmento . "\n\n";
        }
        
        $contexto .= "**" . $codigo . "**";
        
        return trim($contexto);
    }
    
    private function extraerOracionCompleta($texto, $posicion, $valorEncontrado) {
        $inicioOracion = $posicion;
        $finOracion = $posicion + strlen($valorEncontrado);
        
        // Retroceder hasta encontrar inicio de oración
        while ($inicioOracion > 0) {
            $char = $texto[$inicioOracion - 1];
            if ($char === '.' || $char === '!' || $char === '?' || $char === "\n") {
                break;
            }
            $inicioOracion--;
            
            if ($posicion - $inicioOracion > 200) break;
        }
        
        // Avanzar hasta encontrar fin de oración
        while ($finOracion < strlen($texto)) {
            $char = $texto[$finOracion];
            if ($char === '.' || $char === '!' || $char === '?') {
                $finOracion++;
                break;
            }
            $finOracion++;
            
            if ($finOracion - $posicion > 200) break;
        }
        
        $oracion = substr($texto, $inicioOracion, $finOracion - $inicioOracion);
        $oracion = trim($oracion);
        
        if (strlen($oracion) > 15 && strlen($oracion) < 300 && strpos($oracion, $valorEncontrado) !== false) {
            return $oracion;
        }
        
        return '';
    }
    
    private function limpiarFragmentoCompleto($fragmento, $valorEncontrado) {
        // 1. Decodificar quoted-printable PRIMERO
        if (strpos($fragmento, '=') !== false && preg_match('/=[0-9A-F]{2}/', $fragmento)) {
            $fragmento = quoted_printable_decode($fragmento);
        }
        
        // 2. Decodificar entidades HTML
        $fragmento = html_entity_decode($fragmento, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // 3. Convertir a UTF-8 válido si es necesario
        if (!mb_check_encoding($fragmento, 'UTF-8')) {
            $fragmento = mb_convert_encoding($fragmento, 'UTF-8', ['UTF-8', 'ISO-8859-1', 'Windows-1252']);
        }
        
        // 4. Limpiar caracteres de control y espacios múltiples
        $fragmento = preg_replace('/\s+/', ' ', $fragmento);
        $fragmento = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $fragmento);
        
        // 5. Eliminar elementos técnicos no deseados
        $patronesTecnicos = [
            '/CODIGO_ENCONTRADO:\s*/',
            '/------=_Part_\d+_\d+\.\d+/',
            '/Content-Type:.*?charset=UTF-8/i',
            '/Content-Transfer-Encoding:.*$/m',
            '/@font-face\s*\{[^}]*\}/',
            '/font-family:\s*[^;]+;/',
            '/\*\s*\{[^}]*\}/',
            '/http[s]?:\/\/[^\s]+\.(woff|woff2|ttf|eot)/',
        ];
        
        foreach ($patronesTecnicos as $patron) {
            $fragmento = preg_replace($patron, '', $fragmento);
        }
        
        // 6. Limpiar espacios y puntuación múltiple
        $fragmento = preg_replace('/\s*\.\s*\.+\s*/', '. ', $fragmento);
        $fragmento = preg_replace('/\s*,\s*,+\s*/', ', ', $fragmento);
        $fragmento = preg_replace('/\s+/', ' ', $fragmento);
        
        // 7. Trim y validar longitud
        $fragmento = trim($fragmento);
        
        // 8. Truncar inteligentemente si es muy largo
        if (strlen($fragmento) > 200) {
            $fragmentoCorto = substr($fragmento, 0, 197);
            $ultimoPunto = strrpos($fragmentoCorto, '.');
            $ultimoEspacio = strrpos($fragmentoCorto, ' ');
            
            $mejorCorte = $ultimoPunto !== false && $ultimoPunto > 150 ? $ultimoPunto : $ultimoEspacio;
            
            if ($mejorCorte !== false && $mejorCorte > 100) {
                $fragmento = substr($fragmento, 0, $mejorCorte) . '...';
            } else {
                $fragmento = $fragmentoCorto . '...';
            }
        }
        
        return $fragmento;
    }
    
    private function extraerRemitenteEmail($emailData) {
        $from = '';
        
        if (isset($emailData['from'])) {
            $from = $emailData['from'];
        } elseif (isset($emailData['From'])) {
            $from = $emailData['From'];
        } elseif (isset($emailData['sender'])) {
            $from = $emailData['sender'];
        }
        
        if (empty($from)) {
            $subject = $emailData['subject'] ?? '';
            if (preg_match('/(?:from|de)\s+([^,\n]+)/i', $subject, $matches)) {
                $from = trim($matches[1]);
            }
        }
        
        $from = $this->limpiarCampoFromMejorado($from);
        
        $servicio = $this->detectarServicioPorEmail($from, $emailData['subject'] ?? '');
        if ($servicio) {
            return $servicio;
        }
        
        return $from ?: 'Remitente desconocido';
    }
    
    private function limpiarCampoFromMejorado($from) {
        if (empty($from)) return '';
        
        if (strpos($from, '=') !== false) {
            $from = quoted_printable_decode($from);
        }
        
        $from = html_entity_decode($from, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $from = trim($from, '"\'<>()');
        $from = preg_replace('/\s+/', ' ', $from);
        
        if (preg_match('/^(.+?)\s*<[^>]+>$/', $from, $matches)) {
            $from = trim($matches[1], '"\'');
        }
        
        if (strlen($from) > 50) {
            $from = substr($from, 0, 47) . '...';
        }
        
        return $from;
    }
    
    private function detectarServicioPorEmail($from, $subject) {
        $servicios = [
            'Disney+' => [
                'patterns' => ['/disney/i', '/disneyplus/i'],
                'domains' => ['disney.com', 'disneyplus.com', 'bamgrid.com'],
                'subjects' => ['/disney\+/i', '/mydisney/i']
            ],
            'Netflix' => [
                'patterns' => ['/netflix/i'],
                'domains' => ['netflix.com', 'nflxext.com'],
                'subjects' => ['/netflix/i']
            ],
            'Amazon Prime' => [
                'patterns' => ['/amazon/i', '/prime/i'],
                'domains' => ['amazon.com', 'amazon.es', 'primevideo.com', 'amazonses.com'],
                'subjects' => ['/amazon/i', '/prime/i']
            ],
            'Microsoft' => [
                'patterns' => ['/microsoft/i', '/outlook/i', '/xbox/i'],
                'domains' => ['microsoft.com', 'outlook.com', 'xbox.com', 'live.com'],
                'subjects' => ['/microsoft/i', '/outlook/i', '/xbox/i']
            ],
            'Google' => [
                'patterns' => ['/google/i', '/gmail/i'],
                'domains' => ['google.com', 'gmail.com', 'googlemail.com'],
                'subjects' => ['/google/i', '/gmail/i']
            ],
            'Apple' => [
                'patterns' => ['/apple/i', '/icloud/i'],
                'domains' => ['apple.com', 'icloud.com', 'me.com'],
                'subjects' => ['/apple/i', '/icloud/i']
            ],
            'ChatGPT' => [
                'patterns' => ['/chatgpt/i', '/openai/i'],
                'domains' => ['openai.com', 'tm.openai.com'],
                'subjects' => ['/chatgpt/i', '/openai/i']
            ],
        ];
        
        $texto = $from . ' ' . $subject;
        
        foreach ($servicios as $nombre => $config) {
            if (isset($config['subjects'])) {
                foreach ($config['subjects'] as $pattern) {
                    if (preg_match($pattern, $subject)) {
                        return $nombre;
                    }
                }
            }
            
            foreach ($config['patterns'] as $pattern) {
                if (preg_match($pattern, $texto)) {
                    return $nombre;
                }
            }
            
            foreach ($config['domains'] as $domain) {
                if (strpos(strtolower($from), $domain) !== false) {
                    return $nombre;
                }
            }
        }
        
        return null;
    }
    
    private function crearVistaPreviaConFormato($bodyLimpio) {
        $lineas = explode("\n", $bodyLimpio);
        $lineasUtiles = [];
        
        foreach ($lineas as $linea) {
            $linea = trim($linea);
            
            if (strlen($linea) < 10) continue;
            if (preg_match('/^(From:|To:|Subject:|Date:|Content-|CODIGO_ENCONTRADO)/i', $linea)) continue;
            if (preg_match('/^[\-=]{3,}/', $linea)) continue;
            if (preg_match('/^@font-face|^</', $linea)) continue;
            
            if (preg_match('/(?:código|code|passcode|verification|acceso|disney|netflix)/i', $linea)) {
                array_unshift($lineasUtiles, $linea);
            } else {
                $lineasUtiles[] = $linea;
            }
            
            if (count($lineasUtiles) >= 4) break;
        }
        
        $preview = implode(' ', $lineasUtiles);
        
        if (strlen($preview) > 250) {
            $preview = substr($preview, 0, 247) . '...';
        }
        
        return $preview;
    }
    
    private function extraerFromHeader($header) {
        if (isset($header->from) && is_array($header->from) && count($header->from) > 0) {
            $from = $header->from[0];
            return ($from->mailbox ?? '') . '@' . ($from->host ?? '');
        }
        return 'Desconocido';
    }
    
    // ===============================================
    // ✅ FUNCIONES BÁSICAS DEL SISTEMA WEB
    // ===============================================
    
    /**
     * ✅ FUNCIÓN IGUAL AL SISTEMA WEB
     * Parsear timestamp de email de forma robusta
     */
    private function parseEmailTimestamp(string $email_date): int|false
    {
        if (empty($email_date)) {
            return false;
        }
        
        try {
            $timestamp = strtotime($email_date);
            
            if ($timestamp !== false && $timestamp > 0) {
                $now = time();
                $one_year_ago = $now - (365 * 24 * 3600);
                $one_day_future = $now + (24 * 3600);
                
                if ($timestamp >= $one_year_ago && $timestamp <= $one_day_future) {
                    return $timestamp;
                } else {
                    $this->logPerformance("Timestamp fuera de rango razonable: " . date('Y-m-d H:i:s', $timestamp) . " de fecha: " . $email_date);
                }
            }
            
            $datetime = new \DateTime($email_date);
            $timestamp = $datetime->getTimestamp();
            
            if ($timestamp >= $one_year_ago && $timestamp <= $one_day_future) {
                return $timestamp;
            }
            
            $this->logPerformance("DateTime timestamp fuera de rango: " . date('Y-m-d H:i:s', $timestamp) . " de fecha: " . $email_date);
            return false;
            
        } catch (\Exception $e) {
            $this->logPerformance("Error parseando fecha '" . $email_date . "': " . $e->getMessage());
            
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
                } catch (\Exception $regex_error) {
                    $this->logPerformance("Error en parseo regex: " . $regex_error->getMessage());
                }
            }
            
            return false;
        }
    }
    
    /**
     * ✅ FUNCIÓN IGUAL AL SISTEMA WEB
     * Decodificación segura de asuntos MIME
     */
    private function decodeMimeSubject(string $subject): string
    {
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
        } catch (\Exception $e) {
            return $subject;
        }
    }
    
    /**
     * ✅ FUNCIÓN IGUAL AL SISTEMA WEB
     * Verificación de coincidencia de asuntos
     */
    private function subjectMatches(string $decoded_subject, string $pattern): bool
    {
        if (stripos($decoded_subject, trim($pattern)) !== false) {
            return true;
        }
        
        return $this->flexibleSubjectMatch($decoded_subject, $pattern);
    }
    
    /**
     * ✅ FUNCIÓN IGUAL AL SISTEMA WEB
     * Coincidencia flexible de asuntos
     */
    private function flexibleSubjectMatch(string $subject, string $pattern): bool
    {
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
        return $match_ratio >= 0.7;
    }
    
    /**
     * Procesar email encontrado - VERSIÓN PARA WEB
     */
    private function processFoundEmail($connection, int $email_id): ?string
    {
        try {
            $header = imap_headerinfo($connection, $email_id);
            if (!$header) {
                return '<div style="padding: 15px; color: #ff0000;">Error: No se pudo obtener la información del mensaje.</div>';
            }

            $body = imap_body($connection, $email_id);
            
            if (!empty($body)) {
                $processed = $this->processEmailBodyBasic($body);
                return $processed;
            }
            
            return '<div style="padding: 15px; color: #666;">No se pudo extraer el contenido del mensaje.</div>';
            
        } catch (\Exception $e) {
            return '<div style="padding: 15px; color: #ff0000;">Error al procesar el mensaje: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
    
    /**
     * Procesamiento básico del cuerpo del email
     */
    private function processEmailBodyBasic($body): string
    {
        if (strpos($body, '=') !== false && preg_match('/=[0-9A-F]{2}/', $body)) {
            $body = quoted_printable_decode($body);
        }
        
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        if (strpos($body, '<') !== false) {
            $body = strip_tags($body);
        }
        
        $body = preg_replace('/\s+/', ' ', $body);
        $body = trim($body);
        
        if (strlen($body) > 1000) {
            $body = substr($body, 0, 997) . '...';
        }
        
        return '<div style="padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px;">' . 
               '<pre style="white-space: pre-wrap; word-wrap: break-word;">' . 
               htmlspecialchars($body) . 
               '</pre></div>';
    }
    
    // ===============================================
    // FUNCIONES DE CONFIGURACIÓN Y VALIDACIÓN
    // ===============================================
    
    private function getSubjectsForPlatform(string $platform): array
    {
        $stmt = $this->db->prepare("
            SELECT ps.subject 
            FROM platforms p 
            JOIN platform_subjects ps ON p.id = ps.platform_id 
            WHERE p.name = ? AND p.status = 1
        ");
        $stmt->bind_param('s', $platform);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $subjects = [];
        while ($row = $result->fetch_assoc()) {
            $subjects[] = $row['subject'];
        }
        $stmt->close();
        
        return $subjects;
    }
    
    private function getEnabledServers(): array
    {
        $query = "SELECT * FROM email_servers WHERE enabled = 1 ORDER BY priority ASC";
        $result = $this->db->query($query);
        
        $servers = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $servers[] = $row;
            }
        }
        
        return $servers;
    }
    
    /**
     * ✅ FUNCIÓN CORREGIDA - IGUAL AL SISTEMA WEB
     * Verificar si el usuario tiene acceso a buscar en la plataforma
     */
    private function hasUserSubjectAccess(int $userId, string $platform): bool
    {
        $enabled = ($this->settings['USER_SUBJECT_RESTRICTIONS_ENABLED'] ?? '0') === '1';
        if (!$enabled) {
            return true;
        }
        
        $stmt = $this->db->prepare("SELECT role FROM users WHERE id = ?");
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
        
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM user_platform_subjects 
            WHERE user_id = ? AND platform_id = ?
        ");
        $stmt->bind_param('ii', $userId, $platformId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row['count'] > 0;
    }
    
    /**
     * ✅ FUNCIÓN CORREGIDA - IGUAL AL SISTEMA WEB
     * Filtrar asuntos según los permisos del usuario
     */
    private function filterSubjectsForUser(int $userId, string $platform, array $allSubjects): array
    {
        $enabled = ($this->settings['USER_SUBJECT_RESTRICTIONS_ENABLED'] ?? '0') === '1';
        if (!$enabled) {
            return $allSubjects;
        }
        
        $stmt = $this->db->prepare("SELECT role FROM users WHERE id = ?");
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
            return [];
        }
        
        $stmt = $this->db->prepare("
            SELECT subject_keyword 
            FROM user_platform_subjects 
            WHERE user_id = ? AND platform_id = ?
        ");
        $stmt->bind_param('ii', $userId, $platformId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $allowedSubjects = [];
        while ($row = $result->fetch_assoc()) {
            $allowedSubjects[] = $row['subject_keyword'];
        }
        $stmt->close();
        
        // ✅ CAMBIO CRÍTICO: IGUAL AL SISTEMA WEB
        if (empty($allowedSubjects)) {
            return [];
        }
        
        return array_intersect($allSubjects, $allowedSubjects);
    }
    
    /**
     * Obtener ID de plataforma por nombre
     */
    private function getPlatformId(string $platform): ?int
    {
        $stmt = $this->db->prepare("SELECT id FROM platforms WHERE name = ? LIMIT 1");
        $stmt->bind_param('s', $platform);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row ? (int)$row['id'] : null;
    }
    
    // ===============================================
    // FUNCIONES DE LOGGING Y UTILIDADES
    // ===============================================
    
    private function logSearchAttempt(int $userId, string $email, string $platform): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO search_logs (user_id, email, platform, created_at, status) 
                VALUES (?, ?, ?, NOW(), 'searching')
            ");
            $stmt->bind_param('iss', $userId, $email, $platform);
            $stmt->execute();
            $this->lastLogId = $this->db->insert_id;
            $stmt->close();
            \Shared\AuditLogger::log($userId, 'search:' . $platform);
        } catch (\Exception $e) {
            error_log("Error logging search attempt: " . $e->getMessage());
        }
    }
    
    private function updateSearchLog(int $logId, array $result): void
    {
        if ($logId <= 0) return;
        
        try {
            $status = $result['found'] ? 'found' : 'not_found';
            $details = json_encode($result);
            
            $stmt = $this->db->prepare("
                UPDATE search_logs 
                SET status = ?, result_details = ?, completed_at = NOW() 
                WHERE id = ?
            ");
            $stmt->bind_param('ssi', $status, $details, $logId);
            $stmt->execute();
            $stmt->close();
        } catch (\Exception $e) {
            error_log("Error updating search log: " . $e->getMessage());
        }
    }
    
    private function createErrorResponse(string $message): array
    {
        return [
            'found' => false,
            'error' => $message,
            'message' => $message,
            'type' => 'error'
        ];
    }
    
    private function createNotFoundResponse(): array
    {
        return [
            'found' => false,
            'message' => 'No se encontraron códigos para tu búsqueda.',
            'type' => 'not_found'
        ];
    }
}