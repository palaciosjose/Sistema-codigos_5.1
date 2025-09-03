<?php
namespace WhatsappBot\Services;

use Shared\DatabaseManager;
use Shared\AuditLogger;
use TelegramBot\Services\LogService;

/**
 * Manejo de autenticación de usuarios mediante WhatsApp.
 */
class WhatsappAuth
{
    private $db;
    private LogService $logger;

    /** Duración de la sesión en segundos (30 minutos por defecto) */
    const SESSION_LIFETIME = 1800;

    public function __construct()
    {
        $this->db = DatabaseManager::getInstance()->getConnection();
        $this->logger = new LogService();
    }

    /** Limpia sesiones expiradas */
    private function cleanupSessions()
    {
        $this->db->query("DELETE FROM whatsapp_sessions WHERE expires_at < NOW() OR is_active = 0");
    }

    /** Obtiene una sesión activa y la extiende */
    private function getActiveSession($whatsappId)
    {
        $stmt = $this->db->prepare(
            'SELECT ws.id as session_id, u.* FROM whatsapp_sessions ws JOIN users u ON ws.user_id = u.id
             WHERE ws.whatsapp_id=? AND ws.is_active=1 AND ws.expires_at > NOW() LIMIT 1'
        );
        $stmt->bind_param('i', $whatsappId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if ($row) {
            $this->extendSession((int)$row['session_id']);
            return $row;
        }

        return null;
    }

    /** Crea una nueva sesión */
    private function createSession($whatsappId, $userId)
    {
        $token = bin2hex(random_bytes(16));
        $expires = date('Y-m-d H:i:s', time() + self::SESSION_LIFETIME);
        $stmt = $this->db->prepare(
            'INSERT INTO whatsapp_sessions (whatsapp_id, user_id, session_token, expires_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('iiss', $whatsappId, $userId, $token, $expires);
        $stmt->execute();
        $stmt->close();
    }

    /** Extiende el tiempo de expiración de una sesión */
    private function extendSession($sessionId)
    {
        $expires = date('Y-m-d H:i:s', time() + self::SESSION_LIFETIME);
        $stmt = $this->db->prepare('UPDATE whatsapp_sessions SET expires_at=? WHERE id=?');
        $stmt->bind_param('si', $expires, $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /** Maneja estados de inicio de sesión */
    public function setLoginState($whatsappId, $data)
    {
        $type = 'login_' . $whatsappId;
        $json = json_encode($data);
        $stmt = $this->db->prepare(
            'INSERT INTO whatsapp_temp_data (user_id, data_type, data_content, created_at)
             VALUES (0, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE data_content=VALUES(data_content), created_at=NOW()'
        );
        $stmt->bind_param('ss', $type, $json);
        $stmt->execute();
        $stmt->close();
    }

    public function getLoginState($whatsappId)
    {
        $type = 'login_' . $whatsappId;
        $stmt = $this->db->prepare(
            'SELECT data_content FROM whatsapp_temp_data WHERE user_id=0 AND data_type=? ORDER BY id DESC LIMIT 1'
        );
        $stmt->bind_param('s', $type);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row ? json_decode($row['data_content'], true) : null;
    }

    public function clearLoginState($whatsappId)
    {
        $type = 'login_' . $whatsappId;
        $stmt = $this->db->prepare('DELETE FROM whatsapp_temp_data WHERE user_id=0 AND data_type=?');
        $stmt->bind_param('s', $type);
        $stmt->execute();
        $stmt->close();
    }

    /** Inicia sesión con credenciales */
    public function loginWithCredentials($whatsappId, $username, $password)
    {
        $this->logger->info('Login attempt', [
            'whatsapp_id' => $whatsappId,
            'username' => $username,
        ]);

        $stmt = $this->db->prepare('SELECT * FROM users WHERE username=? AND status=1 LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $this->logger->error('User not found', [
                'whatsapp_id' => $whatsappId,
                'username' => $username,
            ]);
            return null;
        }

        $this->logger->info('User retrieved', [
            'user_id' => $user['id'],
        ]);

        $passwordMatch = password_verify($password, $user['password']);
        $this->logger->info('Password verification', [
            'user_id' => $user['id'],
            'success' => $passwordMatch,
        ]);

        if (!$passwordMatch) {
            $this->logger->error('Invalid password', [
                'user_id' => $user['id'],
            ]);
            return null;
        }

        $this->logger->info('Login successful', [
            'user_id' => $user['id'],
        ]);

        $this->createSession($whatsappId, (int)$user['id']);

        $stmt = $this->db->prepare(
            'UPDATE users SET last_whatsapp_activity=NOW() WHERE id=?'
        );
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $stmt->close();

        AuditLogger::log((int)$user['id'], 'login_whatsapp', 'whatsapp');
        return $user;
    }

    public function authenticateUser($whatsappId)
    {
        $this->cleanupSessions();

        $user = $this->findUserByWhatsappId($whatsappId);
        if (!$user || (int)$user['status'] !== 1) {
            $user = $this->getActiveSession($whatsappId);
            if (!$user || (int)$user['status'] !== 1) {
                return null;
            }
        }

        $stmt = $this->db->prepare(
            'UPDATE users SET last_whatsapp_activity=NOW() WHERE id=?'
        );
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $stmt->close();

        return $user;
    }

    public function findUserByWhatsappId($whatsappId)
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE whatsapp_id=? LIMIT 1');
        $stmt->bind_param('i', $whatsappId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function getUserPermissions($userId)
    {
        $permissions = ['emails' => [], 'subjects' => []];

        $query = 'SELECT ae.email FROM user_authorized_emails uae
                  JOIN authorized_emails ae ON uae.authorized_email_id = ae.id
                  WHERE uae.user_id = ?';
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $permissions['emails'][] = $row['email'];
        }
        $stmt->close();

        $query = 'SELECT p.name, ups.subject_keyword FROM user_platform_subjects ups
                  JOIN platforms p ON ups.platform_id = p.id WHERE ups.user_id = ?';
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $permissions['subjects'][$row['name']][] = $row['subject_keyword'];
        }
        $stmt->close();

        return $permissions;
    }
}
