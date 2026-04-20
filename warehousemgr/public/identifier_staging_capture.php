<?php
/**
 * warehousemgr kommentelt forrás
 * AJAX végpont a mobil / kamerás ideiglenes beolvasásokhoz.
 * Egyetlen beolvasott kódot vagy rövid csomagot ment a staging táblába.
 */
 declare(strict_types=1);
 require_once __DIR__ . '/../app/bootstrap.php';
 
 header('Content-Type: application/json; charset=utf-8');
 
 if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
     http_response_code(405);
     echo json_encode([
         'ok' => false,
         'message' => 'Csak POST kérés engedélyezett.',
     ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
     exit;
 }
 
 // Ez a végpont kliensoldali AJAX kérésből kap beolvasott kódot,
// majd ugyanazt a staging mentési logikát hívja, mint a kézi gyűjtőoldal.
try {
     if (!warehouse_material_identifier_feature_ready($config)) {
         throw new RuntimeException('Az egyedi azonosítós bővítés adatbázis része még nincs telepítve.');
     }
     if (!warehouse_identifier_staging_feature_ready($config)) {
         throw new RuntimeException('Az ideiglenes azonosító beolvasó adatbázis része még nincs telepítve.');
     }
 
     $identifierValue = trim((string)($_POST['identifier_value'] ?? ''));
     $captureSource = trim((string)($_POST['capture_source'] ?? ''));
     $captureNote = trim((string)($_POST['capture_note'] ?? ''));
 
     if ($identifierValue === '') {
         throw new RuntimeException('Nincs menthető azonosító.');
     }
 
     $result = warehouse_identifier_staging_capture_bulk($config, $identifierValue, $captureSource, $captureNote);
     $inserted = (int)($result['inserted_rows'] ?? 0);
     $errors = array_values(array_map('strval', (array)($result['errors'] ?? [])));
 
     if ($inserted > 0) {
         echo json_encode([
             'ok' => true,
             'inserted' => true,
             'identifier_value' => $identifierValue,
             'message' => 'Ideiglenesen mentve.',
             'result' => $result,
         ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
         exit;
     }
 
     http_response_code(409);
     echo json_encode([
         'ok' => false,
         'inserted' => false,
         'identifier_value' => $identifierValue,
         'message' => $errors[0] ?? 'Ez az azonosító most nem menthető.',
         'errors' => $errors,
         'result' => $result,
     ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
 } catch (Throwable $e) {
     http_response_code(400);
     echo json_encode([
         'ok' => false,
         'inserted' => false,
         'message' => $e->getMessage(),
     ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
 }
