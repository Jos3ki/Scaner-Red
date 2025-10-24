<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');


$servername = "xxxxxxx";
$username = "xxxxxx";
$password = "xxxxx";
$dbname = "xxxxxx";


$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(["error" => "Conexión fallida: " . $conn->connect_error]));
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'DELETE') {
    die(json_encode(["error" => "La eliminación directa de registros está deshabilitada en el nuevo modelo relacional."]));
}



$is_history_request = isset($_GET['history_mac']);
$is_search_request = isset($_GET['search']);


$subquery_most_recent_id = "
    SELECT MAX(id_equipo) as id_equipo
    FROM equipo
    GROUP BY macaddress
";


if ($is_history_request) {
   
   
    $target_mac = $_GET['history_mac'];
    
    $sql = "
        SELECT 
            e.id_equipo,
            e.ip,
            e.hostname,
            e.macaddress,
            IFNULL(fm.fabricante, 'Fabricante Desconocido') AS fabricante_nombre,
            MAX(pu.fecha_protocolo) AS fecha_ultima_actividad,
            e.fecha_registro,
            GROUP_CONCAT(p.numero ORDER BY p.numero ASC) AS puertos_abiertos_raw,
            GROUP_CONCAT(p.nombre ORDER BY p.numero ASC) AS nombre_servicio_raw
        FROM equipo e
        LEFT JOIN fabricantes_mac fm ON SUBSTRING(e.macaddress, 1, 8) = fm.oui
        LEFT JOIN protocolo_usado pu ON e.id_equipo = pu.id_equipo
        LEFT JOIN protocolo p ON pu.id_protocolo = p.id_protocolo
        WHERE e.macaddress = ?
        
        GROUP BY e.id_equipo, e.ip, e.hostname, e.macaddress, fm.fabricante, e.fecha_registro
        ORDER BY e.fecha_registro DESC
    ";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        http_response_code(500);
        die(json_encode(["error" => "Error al preparar la consulta de historial: " . $conn->error]));
    }
    
    $stmt->bind_param('s', $target_mac);
    
} else {
    
    
    $sql = "
        SELECT 
            e.id_equipo,
            e.ip,
            e.hostname,
            e.macaddress,
            IFNULL(fm.fabricante, 'Fabricante Desconocido') AS fabricante_nombre, 
            
            -- CLAVE: Obtener el conteo de registros para el botón condicional
            (SELECT COUNT(*) FROM equipo WHERE macaddress = e.macaddress) AS total_registros_mac,
            
            MAX(pu.fecha_protocolo) AS fecha_ultima_actividad,
            e.fecha_registro,
            GROUP_CONCAT(p.numero ORDER BY p.numero ASC) AS puertos_abiertos_raw,
            GROUP_CONCAT(p.nombre ORDER BY p.numero ASC) AS nombre_servicio_raw
        FROM equipo e
        
        -- SOLO SE UNEN LAS FILAS CUYO ID ES EL MÁS ALTO (MÁS RECIENTE) PARA ESA MAC
        INNER JOIN ($subquery_most_recent_id) AS recent_id_table 
            ON e.id_equipo = recent_id_table.id_equipo
            
        LEFT JOIN fabricantes_mac fm ON SUBSTRING(e.macaddress, 1, 8) = fm.oui
        LEFT JOIN protocolo_usado pu ON e.id_equipo = pu.id_equipo
        LEFT JOIN protocolo p ON pu.id_protocolo = p.id_protocolo
    ";

    $where_clauses = [];
    $query_params = [];
    $types = '';

    if (isset($_GET['search']) && $_GET['search'] !== '') {
        $searchTerm = '%' . $_GET['search'] . '%';
        
        $searchColumns = ['e.hostname', 'e.ip', 'e.macaddress', 'p.nombre', 'p.numero', 'fm.fabricante'];
        $orClauses = [];
        foreach ($searchColumns as $column) {
            $orClauses[] = "$column LIKE ?";
            $query_params[] = $searchTerm;
        }
        $where_clauses[] = "(" . implode(" OR ", $orClauses) . ")";
        $types = str_repeat('s', count($query_params));
    }

    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }

    
    $sql .= " GROUP BY e.id_equipo, e.ip, e.hostname, e.macaddress, fm.fabricante, e.fecha_registro"; 
    $sql .= " ORDER BY fecha_ultima_actividad DESC, e.fecha_registro DESC";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        http_response_code(500);
        die(json_encode([
            "error" => "Error de sintaxis SQL en la API Principal.",
            "sql_error" => $conn->error,
            "consulta_parcial" => $sql
        ]));
    }

    if (!empty($query_params)) {
        $stmt->bind_param($types, ...$query_params);
    }
}


$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    
   
    $puertos_raw = $row['puertos_abiertos_raw'] ?? '';
    $servicios_raw = $row['nombre_servicio_raw'] ?? '';

    $puertos = explode(',', $puertos_raw);
    $servicios = explode(',', $servicios_raw);
    $combined_ports = [];

    for ($i = 0; $i < count($puertos); $i++) {
        $port = trim($puertos[$i]);
        $service = isset($servicios[$i]) ? trim($servicios[$i]) : 'N/A';
        
        if (!empty($port) && $port != '-') {
            $combined_ports[] = "{$port}-{$service}";
        }
    }
    
    
    $fecha_relevante = $row['fecha_ultima_actividad'] ?: $row['fecha_registro'];

    
    $data[] = [
        'id_equipo' => $row['id_equipo'],
        'ip_address' => $row['ip'],
        'hostname' => $row['hostname'],
        'mac_address' => $row['macaddress'],
        'fabricante_nombre' => $row['fabricante_nombre'] ?? 'N/A', 
        'puertos_servicios_unidos' => implode(' ', $combined_ports),
        'fecha_escaneo' => $fecha_relevante,
        
       
        'total_registros_mac' => $row['total_registros_mac'] ?? 0,
        'puertos_abiertos_raw' => $puertos_raw 
    ];
}



if ($is_history_request) {
    $final_history = [];
    $previous_ip = null;
    $previous_ports_string = null; 

    foreach ($data as $index => $item) {
        
        $event_type = '';
        
        if ($previous_ip !== null) {
           
            if ($item['ip_address'] !== $previous_ip) {
                $event_type = 'MOVIMIENTO DE IP';
            }
            
           
            if ($item['puertos_abiertos_raw'] !== $previous_ports_string && !$event_type) {
                $event_type = 'NUEVOS SERVICIOS (CAMBIO DE PUERTOS)';
            }
        }
        
        $item['event_type'] = $event_type;
        $final_history[] = $item;

        $previous_ip = $item['ip_address'];
        $previous_ports_string = $item['puertos_abiertos_raw'];
    }

    
    echo json_encode($final_history);

} else {
    
    echo json_encode($data);
}

$conn->close();
?>