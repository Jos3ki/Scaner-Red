<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db_config.php'; // Ajusta la ruta según tu estructura
require_once 'utils.php';

setHeaders();
validateGetMethod();

try {
    $pdo = getPDO();
    
    // Obtener parámetros
    $id = getParam('id');
    $equipo_id = getParam('equipo_id');
    $protocolo_id = getParam('protocolo_id');
    $fecha_inicio = getParam('fecha_inicio');
    $fecha_fin = getParam('fecha_fin');
    $limit = getParam('limit', 100);
    $offset = getParam('offset', 0);
    $tipo = getParam('tipo'); // 'resumen', 'timeline'
    
    if ($id) {
        // Obtener un registro específico de protocolo usado por ID
        $query = "SELECT 
                    pu.ID,
                    pu.id_equipo,
                    pu.id_protocolo,
                    pu.fecha_de_uso,
                    e.Hostname,
                    e.IpAddress,
                    e.MacAddress,
                    e.SO,
                    p.nombre as protocolo_nombre,
                    p.numero as protocolo_numero,
                    p.detalle as protocolo_detalle,
                    p.clasificacion_seguridad
                  FROM Protocolo_usado pu
                  JOIN Equipos e ON pu.id_equipo = e.ID
                  JOIN Protocolos p ON pu.id_protocolo = p.id_protocolo
                  WHERE pu.ID = :id";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute(['id' => $id]);
        $registro = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$registro) {
            sendError('Registro no encontrado', 404);
        }
        
        sendResponse([
            'success' => true,
            'data' => $registro
        ]);
        
    } elseif ($equipo_id && $tipo === 'resumen') {
        // Resumen de protocolos usados por un equipo
        $query = "SELECT 
                    p.id_protocolo,
                    p.nombre,
                    p.numero,
                    p.clasificacion_seguridad,
                    COUNT(*) as total_usos,
                    MIN(pu.fecha_de_uso) as primer_uso,
                    MAX(pu.fecha_de_uso) as ultimo_uso,
                    e.Hostname,
                    e.IpAddress
                  FROM Protocolo_usado pu
                  JOIN Protocolos p ON pu.id_protocolo = p.id_protocolo
                  JOIN Equipos e ON pu.id_equipo = e.ID
                  WHERE pu.id_equipo = :equipo_id
                  GROUP BY p.id_protocolo
                  ORDER BY total_usos DESC, ultimo_uso DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute(['equipo_id' => $equipo_id]);
        $resumen = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener información del equipo
        $queryEquipo = "SELECT * FROM Equipos WHERE ID = :id";
        $stmtEquipo = $pdo->prepare($queryEquipo);
        $stmtEquipo->execute(['id' => $equipo_id]);
        $equipo = $stmtEquipo->fetch(PDO::FETCH_ASSOC);
        
        sendResponse([
            'success' => true,
            'equipo' => $equipo,
            'total_protocolos_distintos' => count($resumen),
            'data' => $resumen
        ]);
        
    } elseif ($equipo_id && $tipo === 'timeline') {
        // Timeline de uso de protocolos por equipo
        $query = "SELECT 
                    pu.ID,
                    pu.fecha_de_uso,
                    p.nombre as protocolo,
                    p.numero,
                    p.clasificacion_seguridad,
                    e.Hostname
                  FROM Protocolo_usado pu
                  JOIN Protocolos p ON pu.id_protocolo = p.id_protocolo
                  JOIN Equipos e ON pu.id_equipo = e.ID
                  WHERE pu.id_equipo = :equipo_id
                  ORDER BY pu.fecha_de_uso DESC
                  LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':equipo_id', $equipo_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Contar total
        $queryCount = "SELECT COUNT(*) as total FROM Protocolo_usado WHERE id_equipo = :equipo_id";
        $stmtCount = $pdo->prepare($queryCount);
        $stmtCount->execute(['equipo_id' => $equipo_id]);
        $total = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
        
        sendResponse([
            'success' => true,
            'total' => (int)$total,
            'limit' => (int)$limit,
            'offset' => (int)$offset,
            'data' => $timeline
        ]);
        
    } elseif ($protocolo_id && $tipo === 'equipos') {
        // Equipos que han usado un protocolo específico
        $query = "SELECT 
                    e.ID,
                    e.Hostname,
                    e.IpAddress,
                    e.SO,
                    COUNT(*) as veces_usado,
                    MIN(pu.fecha_de_uso) as primer_uso,
                    MAX(pu.fecha_de_uso) as ultimo_uso,
                    p.nombre as protocolo_nombre,
                    p.clasificacion_seguridad
                  FROM Protocolo_usado pu
                  JOIN Equipos e ON pu.id_equipo = e.ID
                  JOIN Protocolos p ON pu.id_protocolo = p.id_protocolo
                  WHERE pu.id_protocolo = :protocolo_id
                  GROUP BY e.ID
                  ORDER BY veces_usado DESC, ultimo_uso DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute(['protocolo_id' => $protocolo_id]);
        $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener información del protocolo
        $queryProtocolo = "SELECT * FROM Protocolos WHERE id_protocolo = :id";
        $stmtProtocolo = $pdo->prepare($queryProtocolo);
        $stmtProtocolo->execute(['id' => $protocolo_id]);
        $protocolo = $stmtProtocolo->fetch(PDO::FETCH_ASSOC);
        
        sendResponse([
            'success' => true,
            'protocolo' => $protocolo,
            'total_equipos' => count($equipos),
            'data' => $equipos
        ]);
        
    } elseif ($equipo_id && $protocolo_id) {
        // Historial de uso de un protocolo específico en un equipo específico
        $query = "SELECT 
                    pu.ID,
                    pu.fecha_de_uso,
                    e.Hostname,
                    e.IpAddress,
                    p.nombre as protocolo,
                    p.numero,
                    p.clasificacion_seguridad
                  FROM Protocolo_usado pu
                  JOIN Equipos e ON pu.id_equipo = e.ID
                  JOIN Protocolos p ON pu.id_protocolo = p.id_protocolo
                  WHERE pu.id_equipo = :equipo_id
                  AND pu.id_protocolo = :protocolo_id
                  ORDER BY pu.fecha_de_uso DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            'equipo_id' => $equipo_id,
            'protocolo_id' => $protocolo_id
        ]);
        $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse([
            'success' => true,
            'total' => count($historial),
            'data' => $historial
        ]);
        
    } elseif ($equipo_id) {
        // Todos los protocolos usados por un equipo (con detalles)
        $query = "SELECT 
                    pu.ID,
                    pu.fecha_de_uso,
                    p.id_protocolo,
                    p.nombre as protocolo,
                    p.numero,
                    p.detalle,
                    p.clasificacion_seguridad,
                    e.Hostname,
                    e.IpAddress
                  FROM Protocolo_usado pu
                  JOIN Protocolos p ON pu.id_protocolo = p.id_protocolo
                  JOIN Equipos e ON pu.id_equipo = e.ID
                  WHERE pu.id_equipo = :equipo_id
                  ORDER BY pu.fecha_de_uso DESC
                  LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':equipo_id', $equipo_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $protocolos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Contar total
        $queryCount = "SELECT COUNT(*) as total FROM Protocolo_usado WHERE id_equipo = :equipo_id";
        $stmtCount = $pdo->prepare($queryCount);
        $stmtCount->execute(['equipo_id' => $equipo_id]);
        $total = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
        
        sendResponse([
            'success' => true,
            'total' => (int)$total,
            'limit' => (int)$limit,
            'offset' => (int)$offset,
            'data' => $protocolos
        ]);
        
    } elseif ($protocolo_id) {
        // Todos los equipos que han usado un protocolo
        $query = "SELECT 
                    pu.ID,
                    pu.fecha_de_uso,
                    e.ID as equipo_id,
                    e.Hostname,
                    e.IpAddress,
                    e.MacAddress,
                    e.SO,
                    p.nombre as protocolo,
                    p.clasificacion_seguridad
                  FROM Protocolo_usado pu
                  JOIN Equipos e ON pu.id_equipo = e.ID
                  JOIN Protocolos p ON pu.id_protocolo = p.id_protocolo
                  WHERE pu.id_protocolo = :protocolo_id
                  ORDER BY pu.fecha_de_uso DESC
                  LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':protocolo_id', $protocolo_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $usos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Contar total
        $queryCount = "SELECT COUNT(*) as total FROM Protocolo_usado WHERE id_protocolo = :protocolo_id";
        $stmtCount = $pdo->prepare($queryCount);
        $stmtCount->execute(['protocolo_id' => $protocolo_id]);
        $total = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
        
        sendResponse([
            'success' => true,
            'total' => (int)$total,
            'limit' => (int)$limit,
            'offset' => (int)$offset,
            'data' => $usos
        ]);
        
    } elseif ($fecha_inicio && $fecha_fin) {
        // Protocolos usados en un rango de fechas
        $query = "SELECT 
                    pu.ID,
                    pu.fecha_de_uso,
                    e.Hostname,
                    e.IpAddress,
                    p.nombre as protocolo,
                    p.numero,
                    p.clasificacion_seguridad
                  FROM Protocolo_usado pu
                  JOIN Equipos e ON pu.id_equipo = e.ID
                  JOIN Protocolos p ON pu.id_protocolo = p.id_protocolo
                  WHERE pu.fecha_de_uso BETWEEN :fecha_inicio AND :fecha_fin
                  ORDER BY pu.fecha_de_uso DESC
                  LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':fecha_inicio', $fecha_inicio);
        $stmt->bindValue(':fecha_fin', $fecha_fin);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Contar total
        $queryCount = "SELECT COUNT(*) as total FROM Protocolo_usado 
                       WHERE fecha_de_uso BETWEEN :fecha_inicio AND :fecha_fin";
        $stmtCount = $pdo->prepare($queryCount);
        $stmtCount->execute([
            'fecha_inicio' => $fecha_inicio,
            'fecha_fin' => $fecha_fin
        ]);
        $total = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
        
        sendResponse([
            'success' => true,
            'total' => (int)$total,
            'limit' => (int)$limit,
            'offset' => (int)$offset,
            'periodo' => [
                'inicio' => $fecha_inicio,
                'fin' => $fecha_fin
            ],
            'data' => $registros
        ]);
        
    } else {
        // Obtener todos los registros de protocolo_usado (con relaciones)
        $query = "SELECT 
                    pu.ID,
                    pu.id_equipo,
                    pu.id_protocolo,
                    pu.fecha_de_uso,
                    e.Hostname,
                    e.IpAddress,
                    e.SO,
                    p.nombre as protocolo,
                    p.numero,
                    p.clasificacion_seguridad
                  FROM Protocolo_usado pu
                  JOIN Equipos e ON pu.id_equipo = e.ID
                  JOIN Protocolos p ON pu.id_protocolo = p.id_protocolo
                  ORDER BY pu.fecha_de_uso DESC
                  LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Contar total de registros
        $queryCount = "SELECT COUNT(*) as total FROM Protocolo_usado";
        $total = $pdo->query($queryCount)->fetch(PDO::FETCH_ASSOC)['total'];
        
        sendResponse([
            'success' => true,
            'total' => (int)$total,
            'limit' => (int)$limit,
            'offset' => (int)$offset,
            'data' => $registros
        ]);
    }
    
} catch(PDOException $e) {
    sendError('Error de base de datos', 500, $e->getMessage());
} catch(Exception $e) {
    sendError('Error inesperado', 500, $e->getMessage());
}
?>