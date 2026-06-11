<?php
/**
 * =========================================================
 * API: GET /api/get-data.php
 * =========================================================
 *
 * Devuelve el payload JSON completo al frontend.
 * Parámetros GET opcionales:
 *   group  — filtra partidos y posiciones por grupo (A–L)
 *
 * Respuesta:
 *   { status, matches, today, standings, teams }
 */

// Encabezados JSON y CORS para llamadas AJAX locales
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../backend/data_service.php';

try {
    // Leer filtro de grupo de la URL (?group=A)
    $group = isset($_GET['group']) ? strtoupper(trim($_GET['group'])) : null;
    if ($group && !preg_match('/^[A-L]$/', $group)) {
        $group = null;  // Ignorar valores inválidos
    }

    // Construir y devolver el payload completo
    echo json_encode(DataService::buildPayload($group), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // Devolver error estructurado para que el frontend lo muestre
    http_response_code(500);
    echo json_encode(array(
        'error'   => true,
        'message' => $e->getMessage(),
    ), JSON_UNESCAPED_UNICODE);
}
