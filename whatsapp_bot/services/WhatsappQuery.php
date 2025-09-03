<?php
// whatsapp_bot/services/WhatsappQuery.php
namespace WhatsappBot\Services;

use Shared\DatabaseManager;
use Shared\UnifiedQueryEngine;
use Shared\TelegramIntegration;

/**
 * Procesamiento de solicitudes de búsqueda provenientes de WhatsApp.
 */
class WhatsappQuery
{
    private \mysqli $db;
    private WhatsappAuth $auth;
    private UnifiedQueryEngine $engine;
    private TelegramIntegration $integration;

    /** @var array */
    private array $settings;

    public function __construct(WhatsappAuth $auth)
    {
        $this->db = DatabaseManager::getInstance()->getConnection();
        $this->auth = $auth;
        $this->engine = new UnifiedQueryEngine($this->db);
        $this->integration = new TelegramIntegration($this->db);
        $this->settings = $this->loadSettings();
    }

    /**
     * Carga configuraciones relevantes desde la base de datos.
     */
    private function loadSettings(): array
    {
        $settings = [];
        $query = "SELECT name, value FROM settings WHERE name IN ('EMAIL_AUTH_ENABLED','USER_EMAIL_RESTRICTIONS_ENABLED','USER_SUBJECT_RESTRICTIONS_ENABLED')";
        $res = $this->db->query($query);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $settings[$row['name']] = $row['value'];
            }
            $res->close();
        }
        return $settings;
    }

    /**
     * Registra actividad de usuario.
     */
    public function logActivity(int $whatsappId, string $action, array $details = []): bool
    {
        return $this->integration->logActivity($whatsappId, $action, $details);
    }

    private function isEmailAllowed(int $userId, string $email): bool
    {
        if (($this->settings['EMAIL_AUTH_ENABLED'] ?? '0') !== '1') {
            return true;
        }

        $stmtRole = $this->db->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
        $stmtRole->bind_param('i', $userId);
        $stmtRole->execute();
        $roleRes = $stmtRole->get_result();
        $roleRow = $roleRes->fetch_assoc();
        $stmtRole->close();
        if ($roleRow && ($roleRow['role'] === 'admin' || $roleRow['role'] === 'superadmin')) {
            return true;
        }

        $stmt = $this->db->prepare('SELECT id FROM authorized_emails WHERE email=? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return false;
        }

        if (($this->settings['USER_EMAIL_RESTRICTIONS_ENABLED'] ?? '0') !== '1') {
            return true;
        }

        $authId = $row['id'];
        $stmt = $this->db->prepare('SELECT 1 FROM user_authorized_emails WHERE user_id=? AND authorized_email_id=? LIMIT 1');
        $stmt->bind_param('ii', $userId, $authId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }

    private function hasPlatformAccess(int $userId, string $platform): bool
    {
        if (($this->settings['USER_SUBJECT_RESTRICTIONS_ENABLED'] ?? '0') !== '1') {
            return true;
        }

        $stmtRole = $this->db->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
        $stmtRole->bind_param('i', $userId);
        $stmtRole->execute();
        $roleRes = $stmtRole->get_result();
        $roleRow = $roleRes->fetch_assoc();
        $stmtRole->close();
        if ($roleRow && ($roleRow['role'] === 'admin' || $roleRow['role'] === 'superadmin')) {
            return true;
        }

        $stmt = $this->db->prepare('SELECT id FROM platforms WHERE name=? LIMIT 1');
        $stmt->bind_param('s', $platform);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return false;
        }
        $platformId = $row['id'];

        $stmt = $this->db->prepare('SELECT 1 FROM user_platform_subjects WHERE user_id=? AND platform_id=? LIMIT 1');
        $stmt->bind_param('ii', $userId, $platformId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }

    /**
     * Procesa una solicitud de búsqueda.
     */
    public function processSearchRequest(int $whatsappId, int $chatId, string $email, string $platform, string $username = ''): array
    {
        $user = $this->auth->authenticateUser($whatsappId);
        if (!$user) {
            return ['error' => 'Usuario no autorizado o inactivo'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Formato de email inválido'];
        }

        if (!$this->isEmailAllowed((int)$user['id'], $email)) {
            return ['error' => 'No tienes permiso para consultar este correo'];
        }

        if (!$this->hasPlatformAccess((int)$user['id'], $platform)) {
            return ['error' => 'No tienes permisos para esta plataforma'];
        }

        $result = $this->engine->searchEmails($email, $platform, (int)$user['id']);

        $logId = $this->engine->getLastLogId();
        if ($logId > 0) {
            $this->integration->markLogAsWhatsApp($logId, (string)$chatId);
        }

        return $result;
    }

    /**
     * Obtiene un código específico por ID.
     */
    public function getCodeById(int $whatsappId, int $codeId, string $username = ''): array
    {
        $user = $this->auth->authenticateUser($whatsappId);
        if (!$user) {
            return ['error' => 'Usuario no autorizado o inactivo'];
        }

        $code = $this->integration->getCodeById($codeId);
        if (!$code) {
            return ['error' => 'Código no encontrado o no tienes permisos para verlo'];
        }

        return [
            'found' => true,
            'content' => $code,
            'message' => 'Código encontrado'
        ];
    }
}
