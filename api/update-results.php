<?php
/**
 * =========================================================
 * API: POST /api/update-results.php
 * =========================================================
 *
 * Dispara la sincronización con football-data.org.
 * El frontend lo llama al pulsar el botón "Actualizar Resultados".
 *
 * Se puede invocar también como cron:
 *   */5 * * * * curl -s http://localhost/monitoreos/calendariomundial2026/api/update-results.php
 *
 * Respuesta JSON:
 *   { success, message, updated, last_updated, has_live }
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// Aumentar tiempo de ejecución para la descarga (la API puede tardar)
set_time_limit(30);

require_once __DIR__ . '/../backend/data_service.php';

try {
    // Ejecutar la sincronización completa
    $result = DataService::refreshData();

    // Añadir metadatos actualizados a la respuesta
    $result['last_updated'] = Database::getSetting('last_updated', '');
    $result['has_live']     = DataService::hasLiveMatches();

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
