$PortRange = 80, 443, 22, 3389, 3306, 22, 20, 23, 25, 21, 53, 139, 143

$DriverName = "MySQL ODBC 9.4 Unicode Driver"
$ServerName = "xxxxxx"
$DBName = "xxxxxx"
$UserID = "xxxxx"
$Password = "xxxxx"
$TableNameEquipo = "xxxxxx"
$TableNameProtocoloUsado = "xxxxxx"

$ConnectionString = "Driver={$DriverName};Server=$ServerName;Database=$DBName;Uid=$UserID;Pwd=$Password;"


$Subnet = Read-Host "Ingresa la subred a escanear (ej. 192.168.1.)"
[int]$StartIP = Read-Host "Ingresa la IP inicial del rango (ej. 1)"
[int]$EndIP = Read-Host "Ingresa la IP final del rango (ej. 254)"


Write-Host "Iniciando escaneo de la red $Subnet$($StartIP)-$($EndIP)..."
Write-Host "------------------------------------------------------------------"

$LocalIPs = [System.Net.Dns]::GetHostEntry($env:COMPUTERNAME).AddressList
$LocalIP = $LocalIPs | Where-Object { 
    $_.AddressFamily -eq 'InterNetwork' -and $_.ToString().StartsWith($Subnet) 
} | Select-Object -First 1

if ($LocalIP -eq $null) {
    Write-Host "Advertencia: No se pudo determinar la IP local en esta subred ($Subnet). El escaneo continuará sin exclusión." -ForegroundColor Yellow
    $LocalIPString = ""
}
else {
    $LocalIPString = $LocalIP.ToString()
    
}



Write-Host "Iniciando escaneo de la red $Subnet$($StartIP)-$($EndIP)..."


$IPsToScan = $StartIP..$EndIP | ForEach-Object { "$Subnet$_" }

if (-not [string]::IsNullOrEmpty($LocalIPString)) {
    $IPsToScan = $IPsToScan | Where-Object { $_ -ne $LocalIPString }
}


$ProtocolIDs = @{}
try {
    $Conn = New-Object System.Data.Odbc.OdbcConnection($ConnectionString)
    $Conn.Open()
    $Cmd = New-Object System.Data.Odbc.OdbcCommand("SELECT numero, id_protocolo FROM Carlos_protocolo", $Conn)
    $Reader = $Cmd.ExecuteReader()
    while ($Reader.Read()) {
        $ProtocolIDs.Add($Reader.GetInt32(0), $Reader.GetInt32(1))
    }
    $Reader.Close()
    $Conn.Close()
}
catch {
    Write-Host "ERROR CRÍTICO: No se pudieron obtener los IDs de protocolo. Verifique la tabla 'protocolo'." -ForegroundColor Red
    exit
}



Write-Host "Buscando hosts activos..."
$PingJobs = @()
foreach ($IP in $IPsToScan) {
    $PingJobs += Start-Job -ScriptBlock {
        param($IP)
        if (Test-Connection -ComputerName $IP -Count 1 -Quiet -TimeoutSeconds 3 -ErrorAction SilentlyContinue) {
            Write-Host "ACTIVO encontrado: $IP" -ForegroundColor Green
            return $IP
        }
    } -ArgumentList $IP
}

$ActiveIPs = $PingJobs | Wait-Job -Timeout 180 | Receive-Job
$PingJobs | Stop-Job -ErrorAction SilentlyContinue 
$PingJobs | Remove-Job -Force -ErrorAction SilentlyContinue

Write-Host "Hosts activos detectados: $($ActiveIPs.Count)" -ForegroundColor Yellow

if ($ActiveIPs.Count -eq 0) {
    Write-Host "No se encontró ningún equipo activo. Proceso terminado."
    exit
}


foreach ($IP in $ActiveIPs) {
    ping -n 1 -w 100 $IP | Out-Null
}
Start-Sleep -Seconds 2 



Write-Host "FASE 2: Recolectando detalles (MAC, host, Puertos)..."

$ActiveResults = @()

foreach ($IP in $ActiveIPs) {
    
    $HostName = $null
    try { $HostName = [System.Net.Dns]::GetHostByAddress($IP).HostName } catch { $HostName = "N/A" }
    
    $MacAddress = $null
    $arp = arp -a | Select-String -Pattern "$IP"
    if ($arp) { $MacAddress = ($arp -split "\s+")[2] } else { 
        $MacAddress = "00:00:00:00:00:00"
    } 

    $OpenPorts = @()
    
    
    foreach ($Port in $PortRange) {
        $Socket = New-Object System.Net.Sockets.TcpClient
        try {
           
            $iar = $Socket.BeginConnect($IP, $Port, $null, $null)
            if ($iar.AsyncWaitHandle.WaitOne(200, $false)) {
                try {
                    $Socket.EndConnect($iar)
                    $OpenPorts += $Port
                }
                catch {}
            }
        }
        catch {}
        finally {
            if ($Socket -ne $null) { $Socket.Close() }
        }
    }
    
    
    $ActiveResults += [PSCustomObject]@{
        IP         = $IP
        HostName   = $HostName
        MacAddress = ($MacAddress -replace '-', ':') -replace ' ', ':' # Formato de MAC corregido
        Ports      = $OpenPorts
    }
}



if ($ActiveResults.Count -eq 0) {
    Write-Host "Advertencia: No se pudo obtener la información detallada de los hosts activos."
    exit
}

try {
    $Conn = New-Object System.Data.Odbc.OdbcConnection($ConnectionString)
    $Conn.Open()
    Write-Host "Conexión a la BD exitosa." -ForegroundColor Green
    
    foreach ($R in $ActiveResults) {
        
        
        $IP_Escaped = $R.IP -replace "'", "''"
        $HostName_Escaped = $R.HostName -replace "'", "''"
        $Mac_Escaped = $R.MacAddress -replace "'", "''"

       
        $SQL_Check_Stable = "SELECT id_equipo FROM $TableNameEquipo WHERE ip = '$IP_Escaped' AND macaddress = '$Mac_Escaped' AND hostname = '$HostName_Escaped' ORDER BY fecha_registro DESC LIMIT 1;"
        $Cmd = New-Object System.Data.Odbc.OdbcCommand($SQL_Check_Stable, $Conn)
        $ID_Equipo_Existente = $Cmd.ExecuteScalar()
        
        $Action = "INSERT" 
        $ID_Equipo = $null

        if ($ID_Equipo_Existente -ne $null) {
            $Action = "UPDATE"
            $ID_Equipo = $ID_Equipo_Existente
        }
        
       
        if ($Action -eq "UPDATE") {
            $SQL_Equipo_Final = @"
                UPDATE $TableNameEquipo
                SET fecha_registro = NOW(),
                hostname = '$HostName_Escaped'
                WHERE id_equipo = $ID_Equipo;
"@
            Write-Host "($($R.IP)) Actualizado. (Revisión de Estado)." -ForegroundColor Green
        }
        else {
            $SQL_Equipo_Final = @"
                INSERT INTO $TableNameEquipo (ip, hostname, macaddress, fecha_registro)
                VALUES ('$IP_Escaped', '$HostName_Escaped', '$Mac_Escaped', NOW());
"@
            Write-Host "ALERTA: Nuevo registro creado para IP $($R.IP). (Cambio detectado)." -ForegroundColor Magenta
        }
        
        $Cmd = New-Object System.Data.Odbc.OdbcCommand($SQL_Equipo_Final, $Conn)
        $Cmd.ExecuteNonQuery() | Out-Null
        
       
        if ($Action -eq "INSERT") {
            $SQL_GetID = "SELECT id_equipo FROM $TableNameEquipo WHERE ip = '$IP_Escaped' AND macaddress = '$Mac_Escaped' ORDER BY fecha_registro DESC LIMIT 1;"
            $Cmd = New-Object System.Data.Odbc.OdbcCommand($SQL_GetID, $Conn)
            $ID_Equipo = $Cmd.ExecuteScalar()
        }

        
        foreach ($Port in $R.Ports) {
            if ($ProtocolIDs.ContainsKey($Port)) {
                $ID_Protocolo = $ProtocolIDs[$Port]
                
                
                $SQL_ProtoUsado_Upsert = @"
                    INSERT INTO $TableNameProtocoloUsado (id_equipo, id_protocolo, fecha_protocolo)
                    VALUES ($ID_Equipo, $ID_Protocolo, NOW())
                    ON DUPLICATE KEY UPDATE
                    fecha_protocolo = NOW();
"@
                $Cmd = New-Object System.Data.Odbc.OdbcCommand($SQL_ProtoUsado_Upsert, $Conn)
                $Cmd.ExecuteNonQuery() | Out-Null
            }
        }
        
        if ($R.Ports.Count -gt 0) {
            Write-Host "Protocolos registrados/actualizados para IP: $($R.IP)." -ForegroundColor Cyan
        }
        else {
            Write-Host "Equipo registrado. (Sin puertos activos)." -ForegroundColor Yellow
        }
        
    } 

    $Conn.Close()

}
catch {
    Write-Host "ERROR CRÍTICO AL GUARDAR EN BD: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host "============================================="
Write-Host "Proceso de escaneo y almacenamiento completado."