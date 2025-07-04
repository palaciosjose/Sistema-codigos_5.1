<?php
namespace TelegramBot\Services;

use Shared\DatabaseManager;

/**
 * Manejo de autenticación de usuarios mediante Telegram.
 */
class TelegramAuth
{
    private $db;

    /** Duración de la sesión en segundos (30 minutos por defecto) */
    const SESSION_LIFETIME = 1800;

    public function __construct()
    {
        $this->db = DatabaseManager::getInstance()->getConnection();
    }

    /** Limpia sesiones expiradas */
    private function cleanupSessions()
    {
        $this->db->query("DELETE FROM telegram_sessions WHERE expires_at < NOW() OR is_active = 0");
    }

    /** Obtiene una sesión activa y la extiende */
    private function getActiveSession($telegramId)
    {
        $stmt = $this->db->prepare(
            'SELECT ts.id as session_id, u.* FROM telegram_sessions ts JOIN users u ON ts.user_id = u.id
             WHERE ts.telegram_id=? AND ts.is_active=1 AND ts.expires_at > NOW() LIMIT 1'
        );
        $stmt->bind_param('i', $telegramId);
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
    private function createSession($telegramId, $userId)
    {
        $token = bin2hex(random_bytes(16));
        $expires = date('Y-m-d H:i:s', time() + self::SESSION_LIFETIME);
        $stmt = $this->db->prepare(
            'INSERT INTO telegram_sessions (telegram_id, user_id, session_token, expires_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('iiss', $telegramId, $userId, $token, $expires);
        $stmt->execute();
        $stmt->close();
    }

    /** Extiende el tiempo de expiración de una sesión */
    private function extendSession($sessionId)
    {
        $expires = date('Y-m-d H:i:s', time() + self::SESSION_LIFETIME);
        $stmt = $this->db->prepare('UPDATE telegram_sessions SET expires_at=? WHERE id=?');
        $stmt->bind_param('si', $expires, $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /** Maneja estados de inicio de sesión */
    public function setLoginState($telegramId, $data)
    {
        $type = 'login_' . $telegramId;
        $json = json_encode($data);
        $stmt = $this->db->prepare(
            'INSERT INTO telegram_temp_data (user_id, data_type, data_content, created_at)
             VALUES (0, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE data_content=VALUES(data_content), created_at=NOW()'
        );
        $stmt->bind_param('ss', $type, $json);
        $stmt->execute();
        $stmt->close();
    }

    public function getLoginState($telegramId)
    {
        $type = 'login_' . $telegramId;
        $stmt = $this->db->prepare(
            'SELECT data_content FROM telegram_temp_data WHERE user_id=0 AND data_type=? LIMIT 1'
        );
        $stmt->bind_param('s', $type);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row ? json_decode($row['data_content'], true) : null;
    }

    public function clearLoginState($telegramId)
    {
        $type = 'login_' . $telegramId;
        $stmt = $this->db->prepare('DELETE FROM telegram_temp_data WHERE user_id=0 AND data_type=?');
        $stmt->bind_param('s', $type);
        $stmt->execute();
        $stmt->close();
    }

    /** Inicia sesión con credenciales */
public function loginWithCredentials($telegramId, $username, $password)
{
    error_log("=== LOGIN DEBUG ===");
    error_log("Telegram ID: $telegramId");
    error_log("Username recibido: '$username'");
    error_log("Password recibido: '$password'");
    
    $stmt = $this->db->prepare('SELECT * FROM users WHERE username=? AND status=1 LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if (!$user) {
        error_log("ERROR: Usuario no encontrado para username: '$username'");
        return null;
    }
    
    error_log("Usuario encontrado - ID: " . $user['id'] . ", Username: " . $user['username']);
    error_log("Password en BD: " . substr($user['password'], 0, 20) . "...");
    
    $passwordMatch = password_verify($password, $user['password']);
    error_log("Password verify result: " . ($passwordMatch ? 'TRUE' : 'FALSE'));

    if (!$passwordMatch) {
        error_log("ERROR: Password no coincide");
        return null;
    }

    error_log("✅ Login exitoso para usuario: " . $user['username']);
    
    $this->createSession($telegramId, (int)$user['id']);

    // Registrar actividad inicial
    $stmt = $this->db->prepare(
        'UPDATE users SET telegram_username=?, last_telegram_activity=NOW() WHERE id=?'
    );
    $stmt->bind_param('si', $username, $user['id']);
    $stmt->execute();
    $stmt->close();

    return $user;
}

    public function authenticateUser($telegramId, $username)
    {
        $this->cleanupSessions();

        $user = $this->findUserByTelegramId($telegramId);
        if (!$user || (int)$user['status'] !== 1) {
            $user = $this->getActiveSession($telegramId);
            if (!$user || (int)$user['status'] !== 1) {
                return null;
            }
        }

        $stmt = $this->db->prepare(
            'UPDATE users SET telegram_username=?, last_telegram_activity=NOW() WHERE id=?'
        );
        $stmt->bind_param('si', $username, $user['id']);
        $stmt->execute();
        $stmt->close();

        return $user;
    }

    public function findUserByTelegramId($telegramId)
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE telegram_id=? LIMIT 1');
        $stmt->bind_param('i', $telegramId);
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