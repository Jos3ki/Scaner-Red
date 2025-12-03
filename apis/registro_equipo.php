<?php
require 'db_config.php'; // Conexion ala BD 

//Verificar el método y la recepción de datos
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Método no permitido."]);
    exit();
}

$json_data = file_get_contents("php://input");
$data = json_decode($json_data, true);

// Validar que los campos necesarios están presentes
if (!isset($data['FechaHoraArranque'], $data['Hostname'], $data['IpAddress'], $data['MacAddress'], $data['SO'])) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan parámetros: FechaHoraArranque, Hostname, IpAddress, MacAddress, SO."]);
    exit();
}

try {
    
    $sql = "INSERT INTO Equipos (FechaHoraArranque, Hostname, IpAddress, MacAddress, SO) VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $data['FechaHoraArranque'], 
        $data['Hostname'], 
        $data['IpAddress'], 
        $data['MacAddress'],
        $data['SO']
    ]);
    
    $registroId = $pdo->lastInsertId();

    
    echo json_encode([
        "success" => true,
        "registroId" => (int)$registroId,
        "message" => "Equipo registrado correctamente."
    ]);

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error al insertar registro de equipo.", "details" => $e->getMessage()]);
}
?>