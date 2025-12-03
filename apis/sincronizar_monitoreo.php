<?php
require 'db_config.php'; // Debe definir $pdo

// Header de respuesta
header('Content-Type: application/json; charset=utf-8');

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
    exit();
}

// Leer JSON
$json_data = file_get_contents("php://input");
error_log("RAW JSON RECEIVED: " . $json_data);

$data = json_decode($json_data, true);

// Validar estructura básica
if (!is_array($data) || !isset($data['registroId'], $data['ports'], $data['metrics'])) {
    http_response_code(400);
    echo json_encode([
        "error" => "Faltan parámetros: registroId, ports, o metrics.",
        "received" => $data
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$registroId = (int)$data['registroId'];
if ($registroId <= 0) {
    http_response_code(400);
    echo json_encode(["error" => "ID de registro inválido."], JSON_UNESCAPED_UNICODE);
    exit();
}

// $data['ports'] contiene los NÚMEROS DE PUERTO, no los id_protocolo
$portNumbers = is_array($data['ports']) ? $data['ports'] : [];
$metrics = is_array($data['metrics']) ? $data['metrics'] : [];

// Sanitizar lista de números de puerto
$cleanPortNumbers = array_values(array_filter(array_map(function($v) {
    $x = (int)$v;
    return $x > 0 ? $x : null;
}, $portNumbers)));

error_log("PORT NUMBERS RECEIVED: " . implode(", ", $cleanPortNumbers));

// --- CONVERTIR NÚMEROS DE PUERTO A id_protocolo ---
$protocolIdsMap = []; // Mapa: numero_puerto => id_protocolo
$activeProtocolIds = []; // Lista de id_protocolo válidos

if (!empty($cleanPortNumbers)) {
    // Buscar en la tabla Protocolos los id_protocolo correspondientes
    $placeholders = implode(',', array_fill(0, count($cleanPortNumbers), '?'));
    $sqlProtocol = "SELECT id_protocolo, numero FROM Protocolos WHERE numero IN ($placeholders)";
    $stmtProtocol = $pdo->prepare($sqlProtocol);
    $stmtProtocol->execute($cleanPortNumbers);
    
    $foundProtocols = $stmtProtocol->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($foundProtocols as $row) {
        $portNum = (int)$row['numero'];
        $protocolId = (int)$row['id_protocolo'];
        $protocolIdsMap[$portNum] = $protocolId;
        $activeProtocolIds[] = $protocolId;
    }
    
    error_log("PROTOCOL IDS FOUND: " . json_encode($protocolIdsMap));
    
    // Verificar si hay puertos que no se encontraron en la tabla
    $notFoundPorts = array_diff($cleanPortNumbers, array_keys($protocolIdsMap));
    if (!empty($notFoundPorts)) {
        error_log("WARNING: Puertos no encontrados en Protocolos: " . implode(", ", $notFoundPorts));
    }
}

// --- EXTRACCIÓN DE MÉTRICAS ---
$cpu = isset($metrics['CPU']) ? (float)$metrics['CPU'] : 0.0;
$ram = isset($metrics['RAM']) ? (int)$metrics['RAM'] : 0;
$diskFreeGb = isset($metrics['DiskFreeGb']) ? (float)$metrics['DiskFreeGb'] : 0.0;
$diskTime = isset($metrics['DiskTime']) ? (float)$metrics['DiskTime'] : 0.0;
$bytesSent = isset($metrics['BytesSent']) ? (int)$metrics['BytesSent'] : 0;
$bytesRec = isset($metrics['BytesRec']) ? (int)$metrics['BytesRec'] : 0;
$timestampRaw = isset($metrics['Timestamp']) ? $metrics['Timestamp'] : date('Y-m-d H:i:s');

error_log("METRICS RECEIVED - CPU: $cpu, RAM: $ram, DiskFreeGb: $diskFreeGb, DiskTime: $diskTime, BytesSent: $bytesSent, BytesRec: $bytesRec");

// --- SANITIZACIÓN ---
$cpu = min(100.0, max(0.0, $cpu));
$ram = min(100, max(0, $ram));
$diskTime = min(100.0, max(0.0, $diskTime));
$diskFreeGb = max(0.0, round($diskFreeGb, 2));
$bytesSent = max(0, $bytesSent);
$bytesRec = max(0, $bytesRec);

$cpu = round($cpu, 2);
$diskTime = round($diskTime, 2);

// Formato de Timestamp
$timestamp = $timestampRaw;
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp)) {
    $timestamp = date('Y-m-d H:i:s');
}

error_log("VALUES TO INSERT - ID: $registroId, CPU: $cpu, RAM: $ram, DiskFreeGb: $diskFreeGb, DiskTime: $diskTime, BytesSent: $bytesSent, BytesRec: $bytesRec, Timestamp: $timestamp, ProtocolIDsCount: " . count($activeProtocolIds));

// Iniciar transacción
$pdo->beginTransaction();

try {
    // ================= LÓGICA DE PUERTOS =================
    
    // Obtener id_protocolo existentes para este equipo
    $sqlSelect = "SELECT id_protocolo FROM Protocolo_usado WHERE id_equipo = ?";
    $stmt = $pdo->prepare($sqlSelect);
    $stmt->execute([$registroId]);
    $existingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    error_log("EXISTING PROTOCOL IDS: " . implode(", ", $existingIds));
    error_log("ACTIVE PROTOCOL IDS: " . implode(", ", $activeProtocolIds));

    // Calcular diferencias (usando id_protocolo, NO números de puerto)
    $toInsert = array_diff($activeProtocolIds, $existingIds);
    $toDelete = array_diff($existingIds, $activeProtocolIds);
    $toUpdate = array_intersect($activeProtocolIds, $existingIds);

    error_log("TO INSERT: " . implode(", ", $toInsert));
    error_log("TO DELETE: " . implode(", ", $toDelete));
    error_log("TO UPDATE: " . implode(", ", $toUpdate));

    $insertedCount = 0;
    $deletedCount = 0;
    $updatedCount = 0;

    // Insertar nuevos (usando id_protocolo)
    if (!empty($toInsert)) {
        $placeholders = [];
        $values = [];
        foreach ($toInsert as $protocolId) {
            $placeholders[] = '(?, ?, NOW())';
            $values[] = $registroId;
            $values[] = $protocolId;
        }
        $sqlIns = "INSERT INTO Protocolo_usado (id_equipo, id_protocolo, fecha_de_uso) VALUES " . implode(', ', $placeholders);
        $stmtIns = $pdo->prepare($sqlIns);
        $stmtIns->execute($values);
        $insertedCount = $stmtIns->rowCount();
        error_log("INSERTED $insertedCount protocol records");
    }

    // Eliminar cerrados (usando id_protocolo)
    if (!empty($toDelete)) {
        $ph = implode(',', array_fill(0, count($toDelete), '?'));
        $sqlDel = "DELETE FROM Protocolo_usado WHERE id_equipo = ? AND id_protocolo IN ($ph)";
        $stmtDel = $pdo->prepare($sqlDel);
        $stmtDel->execute(array_merge([$registroId], $toDelete));
        $deletedCount = $stmtDel->rowCount();
        error_log("DELETED $deletedCount protocol records");
    }

    // Actualizar fecha de activos (usando id_protocolo)
    if (!empty($toUpdate)) {
        $ph = implode(',', array_fill(0, count($toUpdate), '?'));
        $sqlUpd = "UPDATE Protocolo_usado SET fecha_de_uso = NOW() WHERE id_equipo = ? AND id_protocolo IN ($ph)";
        $stmtUpd = $pdo->prepare($sqlUpd);
        $stmtUpd->execute(array_merge([$registroId], $toUpdate));
        $updatedCount = $stmtUpd->rowCount();
        error_log("UPDATED $updatedCount protocol records");
    }
    
    // ================= LÓGICA DE MÉTRICAS =================

    // Limitar a 2 registros (borrar más antiguos)
    $sqlCount = "SELECT COUNT(*) FROM Metricas WHERE id_equipo = ?";
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute([$registroId]);
    $existingMetricCount = (int)$stmtCount->fetchColumn();

    while ($existingMetricCount >= 2) {
        $sqlDelOld = "DELETE FROM Metricas WHERE id_equipo = ? ORDER BY `Timestamp` ASC LIMIT 1";
        $stmtDelOld = $pdo->prepare($sqlDelOld);
        $stmtDelOld->execute([$registroId]);
        $existingMetricCount--;
    }

    // Insertar métrica
    $sqlMetric = "INSERT INTO Metricas 
        (id_equipo, `Timestamp`, CPU, RAM, DiskFree, DiskTime, Bytessend, BytesRec)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmtMetric = $pdo->prepare($sqlMetric);
    
    $executeResult = $stmtMetric->execute([
        $registroId,
        $timestamp,
        $cpu,
        $ram,
        $diskFreeGb,
        $diskTime,
        $bytesSent,
        $bytesRec
    ]);

    if (!$executeResult) {
        error_log("ERROR EN INSERT METRICS: " . print_r($stmtMetric->errorInfo(), true));
        throw new PDOException("Error al insertar métricas: " . implode(", ", $stmtMetric->errorInfo()));
    }

    $metricsInserted = $stmtMetric->rowCount();
    error_log("METRICS INSERT SUCCESS - Rows affected: $metricsInserted");

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "ports" => [
            "inserted" => $insertedCount,
            "deleted"  => $deletedCount,
            "updated"  => $updatedCount,
            "port_to_protocol_map" => $protocolIdsMap
        ],
        "metrics" => [
            "inserted" => $metricsInserted,
            "values" => [
                "CPU" => $cpu,
                "RAM" => $ram,
                "DiskFreeGb" => $diskFreeGb,
                "DiskTime" => $diskTime,
                "BytesSent" => $bytesSent,
                "BytesRec" => $bytesRec
            ]
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    $errorMsg = "DB ERROR: " . $e->getMessage();
    error_log($errorMsg);
    error_log("SQL State: " . $e->getCode());
    echo json_encode([
        "success" => false,
        "error" => "Error de transacción en BD.",
        "details" => $e->getMessage(),
        "sql_state" => $e->getCode()
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    $errorMsg = "PHP ERROR: " . $e->getMessage();
    error_log($errorMsg);
    echo json_encode([
        "success" => false,
        "error" => "Error inesperado.",
        "details" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>