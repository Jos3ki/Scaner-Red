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
    $equipo_id = getParam('equipo_id');
    $clasificacion = getParam('clasificacion');
    $mas_usados = getParam('mas_usados');
    $limit = getParam('limit', 10);
    
    if ($equipo_id) {
        // Obtener protocolos usados por un equipo específico
        $query = "SELECT 
                    p.id_protocolo,
                    p.numero,
                    p.nombre,
                    p.detalle,
                    p.clasificacion_seguridad,
                    pu.fecha_de_uso,
                    e.Hostname,
                    e.IpAddress
                  FROM Protocolo_usado pu
                  JOIN Protocolos p ON pu.id_protocolo = p.id_protocolo
                  JOIN Equipos e ON pu.id_equipo = e.ID
                  WHERE pu.id_equipo = :equipo_id
                  ORDER BY pu.fecha_de_uso DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute(['equipo_id' => $equipo_id]);
        $protocolos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse([
            'success' => true,
            'total' => count($protocolos),
            'data' => $protocolos
        ]);
        
    } elseif ($clasificacion) {
        // Obtener protocolos por clasificación de seguridad
        $query = "SELECT * FROM Protocolos 
                  WHERE clasificacion_seguridad = :clasificacion
                  ORDER BY nombre ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute(['clasificacion' => $clasificacion]);
        $protocolos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse([
            'success' => true,
            'total' => count($protocolos),
            'data' => $protocolos
        ]);
        
    } elseif ($mas_usados) {
        // Obtener protocolos más usados
        $query = "SELECT 
                    p.*,
                    COUNT(pu.id_protocolo) as total_usos,
                    COUNT(DISTINCT pu.id_equipo) as equipos_distintos,
                    MAX(pu.fecha_de_uso) as ultimo_uso
                  FROM Protocolos p
                  LEFT JOIN Protocolo_usado pu ON p.id_protocolo = pu.id_protocolo
                  GROUP BY p.id_protocolo
                  ORDER BY total_usos DESC
                  LIMIT :limit";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        $protocolos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse([
            'success' => true,
            'total' => count($protocolos),
            'data' => $protocolos
        ]);
        
    } else {
        // Obtener todos los protocolos
        $query = "SELECT 
                    p.*,
                    COUNT(pu.id_protocolo) as veces_usado,
                    COUNT(DISTINCT pu.id_equipo) as equipos_que_lo_usan
                  FROM Protocolos p
                  LEFT JOIN Protocolo_usado pu ON p.id_protocolo = pu.id_protocolo
                  GROUP BY p.id_protocolo
                  ORDER BY p.nombre ASC";
        
        $stmt = $pdo->query($query);
        $protocolos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse([
            'success' => true,
            'total' => count($protocolos),
            'data' => $protocolos
        ]);
    }
    
} catch(PDOException $e) {
    sendError('Error de base de datos', 500, $e->getMessage());
} catch(Exception $e) {
    sendError('Error inesperado', 500, $e->getMessage());
}
?>