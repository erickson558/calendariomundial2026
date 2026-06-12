<?php
/**
 * =========================================================
 * API: GET /api/get-data.php
 * =========================================================
 *
 * Devuelve el payload JSON completo al frontend.
 * Parametros GET opcionales:
 *   group  - filtra partidos y posiciones por grupo (A-L)
 *
 * Auto-refresh en primera carga:
 *   Si el sistema esta en modo demo Y nunca se ha intentado ESPN
 *   (last_updated vacio), intenta ESPN automaticamente para que
 *   el usuario vea datos reales desde el primer clic sin necesidad
 *   de pulsar "Actualizar".
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

require_once dirname(__FILE__) . '/../backend/data_service.php';

try {
    $group = isset($_GET['group']) ? strtoupper(trim($_GET['group'])) : null;
    if ($group && !preg_match('/^[A-L]$/', $group)) {
        $group = null;
    }

    // Auto-intentar ESPN en la primera carga (last_updated vacio).
    // Esto reemplaza los partidos sembrados con datos en tiempo real de ESPN.
    // En visitas posteriores el TTL de cache controla cuando volver a llamar ESPN.
    $lastUpdated = Database::getSetting('last_updated', '');
    if (empty($lastUpdated)) {
        DataService::refreshData();
    }

    echo json_encode(DataService::buildPayload($group), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array(
        'error'   => true,
        'message' => $e->getMessage(),
    ), JSON_UNESCAPED_UNICODE);
}
