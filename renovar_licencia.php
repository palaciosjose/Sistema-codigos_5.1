<?php
// ── Renovar Licencia - Sistema de Códigos ─────────────────────────────────
// Permite renovar la licencia sin reinstalar el sistema

// ── Configuración e Inicialización ────────────────────────────────────────
define('INSTALLER_MODE', true);
session_start();
require_once __DIR__ . '/license_client.php';

// ── Verificación de Instalación ──────────────────────────────────────────
if (!file_exists('config/db_credentials.php')) {
    header('Location: instalacion/instalador.php');
    exit();
}

// ── Cliente de Licencia y Variables para Mensajes ─────────────────────────
$license_client      = new ClientLicense();
$license_success     = '';
$license_error       = '';
$license_warning     = '';
$renewal_successful  = false;

// ── Procesamiento del Formulario de Renovación ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renew_license'])) {
    $license_key = trim($_POST['license_key'] ?? '');

    if ($license_key === '') {
        $license_error = 'Por favor, ingrese una clave de licencia válida.';
    } else {
        try {
            $activation_result = $license_client->activateLicense($license_key);

            if ($activation_result['success']) {
                $verification_attempts = 0;
                $max_attempts          = 3;
                $license_verified      = false;

                while ($verification_attempts < $max_attempts && !$license_verified) {
                    sleep(1);
                    $license_verified = $license_client->isLicenseValid();
                    $verification_attempts++;
                }

                if ($license_verified) {
                    $license_success    = 'Licencia renovada y verificada exitosamente. El sistema ya está disponible.';
                    $renewal_successful = true;
                } else {
                    $license_warning = 'Licencia renovada exitosamente, pero la verificación tardó más de lo esperado. Intente acceder al sistema en unos momentos.';
                }
            } else {
                $license_error = $activation_result['message'];
            }
        } catch (Exception $e) {
            $license_error = 'Error durante la renovación: ' . $e->getMessage();
            error_log('Error renovación licencia: ' . $e->getMessage());
        }
    }
}

// ── Obtener Información Actual y Diagnósticos ─────────────────────────────
$current_license_info = $license_client->getLicenseInfo();
$diagnostic_info      = $license_client->getDiagnosticInfo();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Renovar Licencia - Sistema de Códigos</title>

    <!-- ── Estilos Externos ─────────────────────────────────────────── -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles/modern_global.css">
    <link rel="stylesheet" href="styles/modern_admin.css">

    <!-- ── Estilos Neon Mejorados ──────────────────────────────────────── -->
    <style>
        :root {
            --bg-primary: #0a0a0f;
            --bg-secondary: #1a1a2e;
            --bg-card: #16213e;
            --accent-primary: #00ff88;
            --accent-secondary: #0066ff;
            --accent-danger: #ff0055;
            --accent-warning: #ffaa00;
            --text-primary: #ffffff;
            --text-secondary: #a0a0a0;
            --text-muted: #707070;
            --border-glow: rgba(0, 255, 136, 0.5);
            --border-secondary: rgba(0, 102, 255, 0.3);
            --shadow-primary: 0 0 30px rgba(0, 255, 136, 0.3);
            --shadow-secondary: 0 0 20px rgba(0, 102, 255, 0.2);
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-secondary) 100%);
            color: var(--text-primary);
            min-height: 100vh;
            margin: 0;
            padding: 0;
            position: relative;
            overflow-x: hidden;
        }

        /* ── Fondo Animado ──────────────────────────────────────────── */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 30%, rgba(0, 255, 136, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(0, 102, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 40% 80%, rgba(255, 0, 85, 0.05) 0%, transparent 50%);
            pointer-events: none;
            z-index: -1;
        }

        /* ── Contenedor Principal ────────────────────────────────────── */
        .license-renewal-container {
            max-width: 900px;
            margin: 2rem auto;
            padding: 0 1rem;
            position: relative;
        }

        /* ── Cabecera con Efectos Neon ────────────────────────────────── */
        .header-section {
            text-align: center;
            padding: 3rem 0;
            position: relative;
        }

        .header-icon {
            font-size: 4rem;
            color: var(--accent-primary);
            text-shadow: 
                0 0 20px var(--accent-primary),
                0 0 40px var(--accent-primary),
                0 0 60px var(--accent-primary);
            animation: pulse-glow 2s ease-in-out infinite alternate;
        }

        @keyframes pulse-glow {
            from { 
                text-shadow: 
                    0 0 20px var(--accent-primary),
                    0 0 40px var(--accent-primary),
                    0 0 60px var(--accent-primary);
            }
            to { 
                text-shadow: 
                    0 0 30px var(--accent-primary),
                    0 0 60px var(--accent-primary),
                    0 0 90px var(--accent-primary);
            }
        }

        .header-title {
            font-family: 'Poppins', sans-serif;
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-primary);
            text-shadow: 0 0 20px rgba(255, 255, 255, 0.5);
            margin: 1rem 0;
            letter-spacing: 1px;
        }

        .header-subtitle {
            font-size: 1.1rem;
            color: var(--text-secondary);
            margin-bottom: 2rem;
        }

        /* ── Tarjetas con Efectos Neon ────────────────────────────────── */
        .neon-card {
            background: linear-gradient(135deg, var(--bg-card) 0%, rgba(22, 33, 62, 0.8) 100%);
            border: 2px solid var(--border-glow);
            border-radius: 20px;
            padding: 2rem;
            margin: 2rem 0;
            box-shadow: 
                var(--shadow-primary),
                inset 0 0 20px rgba(0, 255, 136, 0.1);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            position: relative;
        }

        .neon-card::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(45deg, var(--accent-primary), var(--accent-secondary));
            border-radius: 20px;
            z-index: -1;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .neon-card:hover::before {
            opacity: 0.3;
        }

        .neon-card:hover {
            transform: translateY(-5px);
            box-shadow: 
                0 0 40px rgba(0, 255, 136, 0.4),
                inset 0 0 20px rgba(0, 255, 136, 0.2);
        }

        /* ── Sección de Estado de Licencia ────────────────────────────── */
        .license-status-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin: 2rem 0;
        }

        .status-card {
            background: linear-gradient(135deg, rgba(0, 255, 136, 0.1) 0%, rgba(0, 102, 255, 0.1) 100%);
            border: 1px solid var(--border-secondary);
            border-radius: 15px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .status-card:hover {
            transform: scale(1.05);
            border-color: var(--accent-primary);
            box-shadow: 0 0 20px rgba(0, 255, 136, 0.3);
        }

        .status-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .status-active .status-icon {
            color: var(--accent-primary);
        }

        .status-warning .status-icon {
            color: var(--accent-warning);
        }

        .status-error .status-icon {
            color: var(--accent-danger);
        }

        /* ── Formulario de Renovación ───────────────────────────────── */
        .renewal-form-section {
            background: linear-gradient(135deg, rgba(0, 102, 255, 0.1) 0%, rgba(0, 255, 136, 0.1) 100%);
            border: 2px solid var(--border-secondary);
            border-radius: 20px;
            padding: 2.5rem;
            margin: 2rem 0;
            position: relative;
        }

        .form-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .form-title {
            font-family: 'Poppins', sans-serif;
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--accent-primary);
            text-shadow: 0 0 10px var(--accent-primary);
            margin-bottom: 1rem;
        }

        .neon-input-group {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .neon-input {
            background: rgba(0, 0, 0, 0.4);
            border: 2px solid var(--border-secondary);
            border-radius: 10px;
            padding: 1rem 1rem 1rem 3rem;
            font-size: 1.1rem;
            color: var(--text-primary);
            width: 100%;
            transition: all 0.3s ease;
        }

        .neon-input:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 20px rgba(0, 255, 136, 0.3);
            background: rgba(0, 0, 0, 0.6);
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--accent-primary);
            font-size: 1.2rem;
        }

        .neon-btn {
            background: linear-gradient(45deg, var(--accent-primary), var(--accent-secondary));
            border: none;
            border-radius: 10px;
            padding: 1rem 2rem;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--bg-primary);
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .neon-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .neon-btn:hover::before {
            left: 100%;
        }

        .neon-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 30px rgba(0, 255, 136, 0.5);
        }

        .neon-btn-secondary {
            background: transparent;
            border: 2px solid var(--accent-secondary);
            color: var(--accent-secondary);
        }

        .neon-btn-secondary:hover {
            background: var(--accent-secondary);
            color: var(--bg-primary);
        }

        /* ── Alertas Mejoradas ────────────────────────────────────────── */
        .neon-alert {
            border: none;
            border-radius: 15px;
            padding: 1.5rem;
            margin: 1.5rem 0;
            border-left: 4px solid;
            backdrop-filter: blur(10px);
        }

        .neon-alert-success {
            background: linear-gradient(135deg, rgba(0, 255, 136, 0.2) 0%, rgba(0, 255, 136, 0.1) 100%);
            border-left-color: var(--accent-primary);
            color: var(--text-primary);
        }

        .neon-alert-warning {
            background: linear-gradient(135deg, rgba(255, 170, 0, 0.2) 0%, rgba(255, 170, 0, 0.1) 100%);
            border-left-color: var(--accent-warning);
            color: var(--text-primary);
        }

        .neon-alert-error {
            background: linear-gradient(135deg, rgba(255, 0, 85, 0.2) 0%, rgba(255, 0, 85, 0.1) 100%);
            border-left-color: var(--accent-danger);
            color: var(--text-primary);
        }

        .neon-alert-info {
            background: linear-gradient(135deg, rgba(0, 102, 255, 0.2) 0%, rgba(0, 102, 255, 0.1) 100%);
            border-left-color: var(--accent-secondary);
            color: var(--text-primary);
        }

        /* ── Información de Diagnóstico ────────────────────────────────── */
        .diagnostic-section {
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.4) 0%, rgba(0, 0, 0, 0.2) 100%);
            border: 1px solid var(--border-secondary);
            border-radius: 15px;
            padding: 2rem;
            margin: 2rem 0;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
        }

        .diagnostic-header {
            color: var(--accent-secondary);
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            text-shadow: 0 0 10px var(--accent-secondary);
        }

        .diagnostic-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .diagnostic-item:last-child {
            border-bottom: none;
        }

        .diagnostic-label {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .diagnostic-value {
            font-weight: 600;
            padding: 0.3rem 0.8rem;
            border-radius: 5px;
        }

        .diagnostic-value.success {
            background: rgba(0, 255, 136, 0.2);
            color: var(--accent-primary);
        }

        .diagnostic-value.error {
            background: rgba(255, 0, 85, 0.2);
            color: var(--accent-danger);
        }

        /* ── Footer ──────────────────────────────────────────────────── */
        .footer-section {
            text-align: center;
            padding: 2rem 0;
            border-top: 1px solid var(--border-secondary);
            margin-top: 3rem;
        }

        .footer-text {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .footer-text a {
            color: var(--accent-primary);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .footer-text a:hover {
            color: var(--accent-secondary);
            text-shadow: 0 0 10px var(--accent-secondary);
        }

        /* ── Responsividad ───────────────────────────────────────────── */
        @media (max-width: 768px) {
            .license-renewal-container {
                padding: 0 0.5rem;
            }

            .header-title {
                font-size: 2rem;
            }

            .header-icon {
                font-size: 3rem;
            }

            .neon-card {
                padding: 1.5rem;
            }

            .renewal-form-section {
                padding: 2rem;
            }

            .license-status-section {
                grid-template-columns: 1fr;
            }
        }

        /* ── Animaciones Adicionales ────────────────────────────────── */
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-slide-in {
            animation: slideInUp 0.6s ease-out;
        }

        .animate-slide-in-delay-1 {
            animation: slideInUp 0.6s ease-out 0.2s both;
        }

        .animate-slide-in-delay-2 {
            animation: slideInUp 0.6s ease-out 0.4s both;
        }

        .animate-slide-in-delay-3 {
            animation: slideInUp 0.6s ease-out 0.6s both;
        }
    </style>
</head>
<body>
    <div class="license-renewal-container">
        <!-- ── Cabecera con Efectos Neon ────────────────────────────────── -->
        <div class="header-section animate-slide-in">
            <div class="header-icon">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h1 class="header-title">RENOVAR LICENCIA</h1>
            <p class="header-subtitle">
                Actualice su licencia para continuar usando el sistema sin interrupciones
            </p>
        </div>

        <!-- ── Mensajes de Estado ──────────────────────────────────────── -->
        <div class="animate-slide-in-delay-1">
            <?php if (!empty($license_success)): ?>
                <div class="neon-alert neon-alert-success">
                    <i class="fas fa-check-circle me-3"></i>
                    <strong>¡Excelente!</strong> <?= htmlspecialchars($license_success) ?>
                </div>
                <div class="text-center mt-4">
                    <a href="index.php" class="neon-btn">
                        <i class="fas fa-home me-2"></i>Ir al Sistema
                    </a>
                </div>
            <?php elseif (!empty($license_warning)): ?>
                <div class="neon-alert neon-alert-warning">
                    <i class="fas fa-exclamation-triangle me-3"></i>
                    <strong>Atención:</strong> <?= htmlspecialchars($license_warning) ?>
                </div>
            <?php elseif (!empty($license_error)): ?>
                <div class="neon-alert neon-alert-error">
                    <i class="fas fa-times-circle me-3"></i>
                    <strong>Error:</strong> <?= htmlspecialchars($license_error) ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Estado de Licencia Actual ────────────────────────────────── -->
        <?php if ($current_license_info): ?>
        <div class="license-status-section animate-slide-in-delay-2">
            <div class="status-card status-active">
                <div class="status-icon">
                    <i class="fas fa-server"></i>
                </div>
                <h5>Dominio Actual</h5>
                <p><?= htmlspecialchars($current_license_info['domain']) ?></p>
            </div>
            <div class="status-card <?= ($current_license_info['status'] === 'active') ? 'status-active' : 'status-warning' ?>">
                <div class="status-icon">
                    <i class="fas fa-<?= ($current_license_info['status'] === 'active') ? 'check-circle' : 'exclamation-circle' ?>"></i>
                </div>
                <h5>Estado</h5>
                <p><?= htmlspecialchars($current_license_info['status']) ?></p>
            </div>
            <div class="status-card status-active">
                <div class="status-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h5>Última Verificación</h5>
                <p><?= htmlspecialchars($current_license_info['last_check']) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Formulario de Renovación ───────────────────────────────── -->
        <?php if (!$renewal_successful): ?>
        <div class="renewal-form-section animate-slide-in-delay-3">
            <div class="form-header">
                <h3 class="form-title">
                    <i class="fas fa-key me-2"></i>Nueva Clave de Licencia
                </h3>
            </div>
            
            <form method="POST">
                <div class="neon-input-group">
                    <i class="fas fa-shield-alt input-icon"></i>
                    <input
                        type="text"
                        class="neon-input"
                        id="license_key"
                        name="license_key"
                        placeholder="Ingrese su clave de licencia"
                        value="<?= htmlspecialchars($_POST['license_key'] ?? '') ?>"
                        required>
                </div>

                <div class="neon-alert neon-alert-info mb-4">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Información importante:</strong><br>
                    • La licencia se activará para el dominio: <strong><?= htmlspecialchars($_SERVER['HTTP_HOST']) ?></strong><br>
                    • Se verificará automáticamente la validez con el servidor de licencias<br>
                    • La renovación no afectará sus datos ni configuraciones existentes<br>
                    • Se requiere conexión a internet para la activación
                </div>

                <div class="d-grid gap-3 d-md-flex justify-content-md-center">
                    <button type="submit" name="renew_license" class="neon-btn">
                        <i class="fas fa-sync-alt me-2"></i>Renovar Licencia
                    </button>
                    <a href="mailto:soporte@tudominio.com" class="neon-btn neon-btn-secondary">
                        <i class="fas fa-envelope me-2"></i>Contactar Soporte
                    </a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- ── Información de Diagnóstico ────────────────────────────────── -->
        <div class="diagnostic-section animate-slide-in-delay-3">
            <div class="diagnostic-header">
                <i class="fas fa-wrench me-2"></i>Diagnóstico del Sistema
            </div>
            
            <div class="diagnostic-item">
                <span class="diagnostic-label">Directorio de licencias:</span>
                <span class="diagnostic-value <?= $diagnostic_info['directory_exists'] ? 'success' : 'error' ?>">
                    <?= $diagnostic_info['directory_exists'] ? 'Existe' : 'No existe' ?>
                </span>
            </div>
            
            <div class="diagnostic-item">
                <span class="diagnostic-label">Archivo de licencia:</span>
                <span class="diagnostic-value <?= $diagnostic_info['file_exists'] ? 'success' : 'error' ?>">
                    <?= $diagnostic_info['file_exists'] ? 'Existe' : 'No existe' ?>
                </span>
            </div>
            
            <div class="diagnostic-item">
                <span class="diagnostic-label">Permisos de escritura:</span>
                <span class="diagnostic-value <?= $diagnostic_info['directory_writable'] ? 'success' : 'error' ?>">
                    <?= $diagnostic_info['directory_writable'] ? 'Correctos' : 'Incorrectos' ?>
                </span>
            </div>
            
            <div class="diagnostic-item">
                <span class="diagnostic-label">Dominio actual:</span>
                <span class="diagnostic-value success">
                    <?= htmlspecialchars($_SERVER['HTTP_HOST']) ?>
                </span>
            </div>
        </div>

        <!-- ── Footer ──────────────────────────────────────────────────── -->
        <div class="footer-section">
            <p class="footer-text">
                <i class="fas fa-question-circle me-2"></i>
                ¿Problemas con la renovación?
                <a href="mailto:soporte@tudominio.com">Contacte al soporte técnico</a>
            </p>
        </div>
    </div>

    <!-- ── Scripts ──────────────────────────────────────────────────── -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <?php if ($renewal_successful): ?>
    <script>
        // Auto-redirect con confirmación
        setTimeout(function() {
            if (confirm('Licencia renovada exitosamente. ¿Desea ir al sistema ahora?')) {
                window.location.href = 'index.php';
            }
        }, 3000);
    </script>
    <?php endif; ?>
</body>
</html>