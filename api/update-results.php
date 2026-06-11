<?php
/**
 * =========================================================
 * API: POST /api/update-results.php
 * =========================================================
 *
 * Dispara la sincronizacion con ESPN.
 * El frontend lo llama al pulsar el boton "Actualizar Resultados".
 *
 * Se puede invocar tambien como cron (cada 5 minutos):
 *   cron: 5 min interval -> curl -s http://localhost/monitoreos/calendariomundial2026/api/update-results.php
 *
 * Nota: el simbolo asterisco-barra cierra bloques de comentario PHP,
 * por eso la expresion cron se describe en texto, no como codigo cron literal.
 *
 * Respuesta JSON:
 *   { success, message, updated, last_updated, has_live }
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// Mas tiempo de ejecucion: ESPN puede tardar varios segundos en responder
set_time_limit(60);

require_once dirname(__FILE__) . '/../backend/data_service.php';

// PHP 5.4 no tiene Throwable (interfaz de PHP 7.0+); usar Exception
try {
    // refreshData() devuelve true si se actualizo, false si cache aun es valido
    $updated = DataService::refreshData();

    echo json_encode(array(
        'success'      => true,
        'message'      => $updated ? 'Datos actualizados desde ESPN' : 'Cache valido, sin cambios',
        'updated'      => $updated,
        'last_updated' => Database::getSetting('last_updated', ''),
        'has_live'     => DataService::hasLiveMatches(),
    ));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'message' => $e->getMessage(),
    ));
}
