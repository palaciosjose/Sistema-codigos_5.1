<?php
require_once __DIR__ . '/../config/path_constants.php';
session_start();
require_once PROJECT_ROOT . '/shared/DatabaseManager.php';
require_once PROJECT_ROOT . '/funciones.php';
require_once SECURITY_DIR . '/auth.php';
require_once CACHE_DIR . '/cache_helper.php';
use Shared\DatabaseManager;

// Verificar si el usuario es admin
authorize('manage_plataforma', '../index.php', false); 

// Crear una conexión a la base de datos
try {
    $conn = DatabaseManager::getInstance()->getConnection();
} catch (\Throwable $e) {
    die("Error de conexión: " . $e->getMessage());
}

$allowed_actions = ['add_platform','edit_platform','delete_platform','add_subject','delete_subject','update_platform_order','edit_subject','get_subjects'];
$action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
if ($action === null) {
    $action = filter_input(INPUT_GET, 'action', FILTER_SANITIZE_STRING);
}
if (!in_array($action, $allowed_actions, true)) {
    $action = null;
}

// ----- OPERACIONES CRUD PARA PLATAFORMAS (POST) -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'add_platform':
            $platform_name = filter_var(trim($_POST['platform_name'] ?? ''), FILTER_SANITIZE_STRING);
            if (!empty($platform_name)) {
                $logo_file_name = null;
                // Procesar logo si se sube un archivo
                if (isset($_FILES['platform_logo']) && $_FILES['platform_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                    if ($_FILES['platform_logo']['error'] === UPLOAD_ERR_OK) {
                        $tmp_name = $_FILES['platform_logo']['tmp_name'];
                        $mime_type = mime_content_type($tmp_name);
                        if ($mime_type === 'image/png') {
                            $upload_dir = PROJECT_ROOT . '/images/platforms/';
                            $logo_file_name = uniqid('platform_', true) . '.png';
                            if (!move_uploaded_file($tmp_name, $upload_dir . $logo_file_name)) {
                                $_SESSION['platform_error'] = 'Error al mover el archivo de logo.';
                                header('Location: admin.php?tab=platforms');
                                exit();
                            }
                        } else {
                            $_SESSION['platform_error'] = 'Solo se permiten archivos PNG para el logo.';
                            header('Location: admin.php?tab=platforms');
                            exit();
                        }
                    } else {
                        $_SESSION['platform_error'] = 'Error en la subida del archivo de logo.';
                        header('Location: admin.php?tab=platforms');
                        exit();
                    }
                }

                $stmt_check = $conn->prepare("SELECT id FROM platforms WHERE name = ?");
                $stmt_check->bind_param("s", $platform_name);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows == 0) {
                    $stmt_insert = $conn->prepare("INSERT INTO platforms (name, logo) VALUES (?, ?)");
                    $stmt_insert->bind_param("ss", $platform_name, $logo_file_name);
                    if ($stmt_insert->execute()) {
                        $_SESSION['platform_message'] = 'Plataforma \"' . htmlspecialchars($platform_name) . '\" añadida correctamente.';
                        SimpleCache::clear_platforms_cache();
                    } else {
                        // Si falla la inserción, eliminar el logo subido
                        if ($logo_file_name) {
                            @unlink('../images/platforms/' . $logo_file_name);
                        }
                        $_SESSION['platform_error'] = 'Error al añadir la plataforma: ' . $stmt_insert->error;
                    }
                    $stmt_insert->close();
                } else {
                    // Si ya existe, eliminar el logo subido
                    if ($logo_file_name) {
                        @unlink('../images/platforms/' . $logo_file_name);
                    }
                    $_SESSION['platform_error'] = 'El nombre de la plataforma ya existe.';
                }
                $stmt_check->close();
            } else {
                $_SESSION['platform_error'] = 'El nombre de la plataforma no puede estar vacío.';
            }
            header('Location: admin.php?tab=platforms');
            break;

        case 'edit_platform':
            $platform_id = filter_var($_POST['platform_id'] ?? null, FILTER_VALIDATE_INT);
            $platform_name = filter_var(trim($_POST['platform_name'] ?? ''), FILTER_SANITIZE_STRING);
            $delete_logo = !empty($_POST['delete_logo']);
            if ($platform_id && !empty($platform_name)) {
                // Verificar si el nuevo nombre ya existe en otra plataforma
                $stmt_check = $conn->prepare("SELECT id FROM platforms WHERE name = ? AND id != ?");
                $stmt_check->bind_param("si", $platform_name, $platform_id);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows == 0) {
                    $upload_dir = '../images/platforms/';

                    // Obtener logo actual
                    $stmt_logo = $conn->prepare("SELECT logo FROM platforms WHERE id = ?");
                    $stmt_logo->bind_param("i", $platform_id);
                    $stmt_logo->execute();
                    $stmt_logo->bind_result($old_logo);
                    $stmt_logo->fetch();
                    $stmt_logo->close();

                    $new_logo_name = $old_logo;
                    $uploaded_new_logo = false;

                    if (isset($_FILES['platform_logo']) && $_FILES['platform_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                        if ($_FILES['platform_logo']['error'] === UPLOAD_ERR_OK) {
                            $tmp_name = $_FILES['platform_logo']['tmp_name'];
                            $mime_type = mime_content_type($tmp_name);
                            if ($mime_type === 'image/png') {
                                $new_logo_name = uniqid('platform_', true) . '.png';
                                if (!move_uploaded_file($tmp_name, $upload_dir . $new_logo_name)) {
                                    $_SESSION['platform_error'] = 'Error al mover el archivo de logo.';
                                    header('Location: admin.php?tab=platforms');
                                    exit();
                                }
                                $uploaded_new_logo = true;
                            } else {
                                $_SESSION['platform_error'] = 'Solo se permiten archivos PNG para el logo.';
                                header('Location: admin.php?tab=platforms');
                                exit();
                            }
                        } else {
                            $_SESSION['platform_error'] = 'Error en la subida del archivo de logo.';
                            header('Location: admin.php?tab=platforms');
                            exit();
                        }
                    }

                    if ($delete_logo && !$uploaded_new_logo) {
                        $new_logo_name = null;
                    }

                    $stmt_update = $conn->prepare("UPDATE platforms SET name = ?, logo = ? WHERE id = ?");
                    $stmt_update->bind_param("ssi", $platform_name, $new_logo_name, $platform_id);

                    if ($stmt_update->execute()) {
                        if (($uploaded_new_logo && $old_logo) || ($delete_logo && $old_logo && !$uploaded_new_logo)) {
                            @unlink($upload_dir . $old_logo);
                        }
                        $_SESSION['platform_message'] = 'Plataforma actualizada correctamente.';
                        SimpleCache::clear_platforms_cache();
                    } else {
                        if ($uploaded_new_logo) {
                            @unlink($upload_dir . $new_logo_name);
                        }
                        $_SESSION['platform_error'] = 'Error al actualizar la plataforma: ' . $stmt_update->error;
                    }
                    $stmt_update->close();
                } else {
                    $_SESSION['platform_error'] = 'El nombre de la plataforma ya está en uso por otra plataforma.';
                }
                $stmt_check->close();
            } else {
                $_SESSION['platform_error'] = 'Datos inválidos para actualizar la plataforma.';
            }
            header('Location: admin.php?tab=platforms');
            break;

        case 'delete_platform':
            $platform_id = filter_var($_POST['platform_id'] ?? null, FILTER_VALIDATE_INT);
            if ($platform_id) {
                // Obtener el nombre del logo antes de eliminar la plataforma
                $logo = null;
                $stmt_logo = $conn->prepare("SELECT logo FROM platforms WHERE id = ?");
                $stmt_logo->bind_param("i", $platform_id);
                $stmt_logo->execute();
                $stmt_logo->bind_result($logo);
                $stmt_logo->fetch();
                $stmt_logo->close();

                $stmt_delete = $conn->prepare("DELETE FROM platforms WHERE id = ?");
                $stmt_delete->bind_param("i", $platform_id);
                if ($stmt_delete->execute()) {
                    // Eliminar archivo de logo si existe
                    if ($logo) {
                        $logo_path = '../images/platforms/' . $logo;
                        if (file_exists($logo_path)) {
                            @unlink($logo_path);
                        }
                    }
                    // ON DELETE CASCADE se encargará de los asuntos
                    $_SESSION['platform_message'] = 'Plataforma y sus asuntos asociados eliminados correctamente.';
                    SimpleCache::clear_platforms_cache();
                } else {
                    $_SESSION['platform_error'] = 'Error al eliminar la plataforma: ' . $stmt_delete->error;
                }
                $stmt_delete->close();
            } else {
                $_SESSION['platform_error'] = 'ID de plataforma inválido.';
            }
            header('Location: admin.php?tab=platforms');
            break;

        // ----- OPERACIONES CRUD PARA ASUNTOS (POST - AJAX) -----
        case 'add_subject':
            header('Content-Type: application/json');
            $platform_id = filter_var($_POST['platform_id'] ?? null, FILTER_VALIDATE_INT);
            $subject_text = filter_var(trim($_POST['subject'] ?? ''), FILTER_SANITIZE_STRING);
            $response = ['success' => false];

            if ($platform_id && !empty($subject_text)) {
                // Opcional: Verificar si el asunto ya existe para esta plataforma
                $stmt_check = $conn->prepare("SELECT id FROM platform_subjects WHERE platform_id = ? AND subject = ?");
                $stmt_check->bind_param("is", $platform_id, $subject_text);
                $stmt_check->execute();
                $stmt_check->store_result();
                
                if ($stmt_check->num_rows == 0) {
                    $stmt_insert = $conn->prepare("INSERT INTO platform_subjects (platform_id, subject) VALUES (?, ?)");
                    $stmt_insert->bind_param("is", $platform_id, $subject_text);
                    if ($stmt_insert->execute()) {
                        $response['success'] = true;
                    } else {
                        $response['error'] = 'Error al añadir asunto: ' . $stmt_insert->error;
                    }
                    $stmt_insert->close();
                } else {
                     $response['error'] = 'Este asunto ya existe para esta plataforma.';
                }
                $stmt_check->close();
            } else {
                $response['error'] = 'Datos inválidos para añadir asunto.';
            }
            echo json_encode($response);
            exit(); // Importante salir después de respuesta AJAX
            break;

        case 'delete_subject':
             header('Content-Type: application/json');
            $subject_id = filter_var($_POST['subject_id'] ?? null, FILTER_VALIDATE_INT);
            $response = ['success' => false];

            if ($subject_id) {
                 $stmt_delete = $conn->prepare("DELETE FROM platform_subjects WHERE id = ?");
                $stmt_delete->bind_param("i", $subject_id); 
                if ($stmt_delete->execute()) {
                    $response['success'] = true;
                } else {
                    $response['error'] = 'Error al eliminar asunto: ' . $stmt_delete->error;
                }
                $stmt_delete->close();
            } else {
                $response['error'] = 'ID de asunto inválido.';
            }
            echo json_encode($response);
             exit(); // Importante salir después de respuesta AJAX
            break;

        // *** NUEVO: Actualizar Orden de Plataformas (POST - AJAX) ***
        case 'update_platform_order':
            header('Content-Type: application/json');
            $ordered_ids_json = filter_var($_POST['ordered_ids'] ?? '[]', FILTER_UNSAFE_RAW);
            $ordered_ids = json_decode($ordered_ids_json, true);
            $response = ['success' => false];

            if (is_array($ordered_ids)) {
                $conn->begin_transaction(); // Iniciar transacción para asegurar atomicidad
                try {
                    $stmt_update_order = $conn->prepare("UPDATE platforms SET sort_order = ? WHERE id = ?");
                    foreach ($ordered_ids as $index => $platform_id) {
                        $sort_order = $index; // El índice del array es el nuevo orden (0, 1, 2, ...)
                        $platform_id_int = filter_var($platform_id, FILTER_VALIDATE_INT);
                        if ($platform_id_int === false) {
                            throw new Exception("ID de plataforma inválido encontrado: " . $platform_id);
                        }
                        $stmt_update_order->bind_param("ii", $sort_order, $platform_id_int);
                        if (!$stmt_update_order->execute()) {
                            throw new Exception("Error al actualizar el orden para platform ID " . $platform_id_int . ": " . $stmt_update_order->error);
                        }
                    }
                    $stmt_update_order->close();
                    $conn->commit(); // Confirmar transacción si todo fue bien
                    $response['success'] = true;
                } catch (Exception $e) {
                    $conn->rollback(); // Revertir cambios si algo falló
                    $response['error'] = "Error en la transacción: " . $e->getMessage();
                    error_log($response['error']); // Registrar el error detallado
                }
            } else {
                $response['error'] = 'Datos de orden inválidos.';
            }
            echo json_encode($response);
            exit();
            break;
            // *** FIN: Actualizar Orden ***

        // Añadir una nueva acción para editar asuntos
        case 'edit_subject':
            header('Content-Type: application/json');
            $subject_id = filter_var($_POST['subject_id'] ?? null, FILTER_VALIDATE_INT);
            $subject_text = filter_var(trim($_POST['subject_text'] ?? ''), FILTER_SANITIZE_STRING);
            $platform_id = filter_var($_POST['platform_id'] ?? null, FILTER_VALIDATE_INT);
            $response = ['success' => false];

            if ($subject_id && !empty($subject_text) && $platform_id) {
                // Verificar si el nuevo texto ya existe para otra entrada en esta plataforma
                $stmt_check = $conn->prepare("SELECT id FROM platform_subjects WHERE platform_id = ? AND subject = ? AND id != ?");
                $stmt_check->bind_param("isi", $platform_id, $subject_text, $subject_id);
                $stmt_check->execute();
                $stmt_check->store_result();
                
                if ($stmt_check->num_rows == 0) {
                    $stmt_update = $conn->prepare("UPDATE platform_subjects SET subject = ? WHERE id = ?");
                    $stmt_update->bind_param("si", $subject_text, $subject_id);
                    if ($stmt_update->execute()) {
                        $response['success'] = true;
                    } else {
                        $response['error'] = 'Error al actualizar asunto: ' . $stmt_update->error;
                    }
                    $stmt_update->close();
                } else {
                    $response['error'] = 'Este asunto ya existe para esta plataforma.';
                }
                $stmt_check->close();
            } else {
                $response['error'] = 'Datos inválidos para actualizar asunto.';
            }
            echo json_encode($response);
            exit();
            break;
    }
}

// ----- OBTENER ASUNTOS (GET - AJAX) -----
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_subjects') {
    header('Content-Type: application/json');
    $platform_id = filter_var($_GET['platform_id'] ?? null, FILTER_VALIDATE_INT);
    $response = ['success' => false, 'subjects' => []];

    if ($platform_id) {
        $stmt = $conn->prepare("SELECT id, subject FROM platform_subjects WHERE platform_id = ? ORDER BY subject ASC");
        $stmt->bind_param("i", $platform_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $response['subjects'][] = $row;
            }
            $response['success'] = true;
        } else {
            $response['error'] = 'Error al obtener asuntos: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $response['error'] = 'ID de plataforma no válido.';
    }
    echo json_encode($response);
    exit();
}

// Si no es una acción reconocida o método incorrecto, redirigir
if (!in_array($action, $allowed_actions, true)) {
    $_SESSION['platform_error'] = 'Acción no válida.';
    header('Location: admin.php?tab=platforms');
    exit();
}

// Si llegamos aquí y es una petición GET que no fue manejada
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action !== 'get_subjects') {
    header('Location: admin.php?tab=platforms');
    exit();
}

?>
