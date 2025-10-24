# =================================================================
# SCRIPT FINAL: COMPATIBILIDAD MÁXIMA (WINDOWS POWERSHELL 5.1)
# Utiliza System.Net.Ping y cmd.exe para estabilidad en concurrencia.
# =================================================================

$PortRange = 80, 443, 22, 3389, 3306, 22, 20, 23, 25, 21, 53, 139, 143


$DriverName = "MySQL ODBC 9.4 Unicode Driver" 
$ServerName = "xxxx"
$DBName = "xxxx"
$UserID = "xxxxx"
$Password = "xxxxxx"
$TableNameEquipo = "xxxxx"
$TableNameProtocoloUsado = "xxxxx"

$ConnectionString = "Driver={$DriverName};Server=$ServerName;Database=$DBName;Uid=$UserID;Pwd=$Password;"



$Subnet = Read-Host "Ingresa la subred a escanear (ej. 192.168.1.)"
[int]$StartIP = Read-Host "Ingresa la IP inicial del rango (ej. 1)"
[int]$EndIP = Read-Host "Ingresa la IP final del rango (ej. 254)"


Write-Host "Iniciando escaneo de la red $Subnet$($StartIP)-$($EndIP)..."
Write-Host "------------------------------------------------------------------"

$LocalIPs = [System.Net.Dns]::GetHostEntry($env:COMPUTERNAME).AddressList
$LocalIP = $LocalIPs | Where-Object { $_.AddressFamily -eq 'InterNetwork' -and $_.ToString().StartsWith($Subnet) } | Select-Object -First 1

if ($LocalIP -ne $null) {
    $LocalIPString = $LocalIP.ToString()
}
else {
    Write-Host "Advertencia: No se pudo determinar la IP local. Escaneando todo el rango." -ForegroundColor Yellow
    $LocalIPString = ""
}

$IPsToScan = $StartIP..$EndIP | ForEach-Object { "$Subnet$_" }

if (-not [string]::IsNullOrEmpty($LocalIPString)) {
    $IPsToScan = $IPsToScan | Where-Object { $_ -ne $LocalIPString }
}

$ProtocolIDs = @{}
try {
    $Conn = New-Object System.Data.Odbc.OdbcConnection($ConnectionString)
    $Conn.Open()
    $Cmd = New-Object System.Data.Odbc.OdbcCommand("SELECT numero, id_protocolo FROM protocolo", $Conn)
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



Write-Host "FASE 1: Buscando hosts activos con Ping (3s timeout)..."
$PingJobs = @()
foreach ($IP in $IPsToScan) {
    $PingJobs += Start-Job -ScriptBlock {
        param($IP)
        

        $Ping = New-Object System.Net.NetworkInformation.Ping
        $PingTimeout = 3000
        
        try {
            $Reply = $Ping.Send($IP, $PingTimeout)
            if ($Reply.Status -eq "Success") {
                Write-Host "ACTIVO encontrado: $IP" -ForegroundColor Green
                return $IP
            }
        }
        catch {}
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


Write-Host "FASE 1.5: Llenando caché ARP. Esto garantiza la lectura de la MAC..."
# Se ejecuta en el hilo principal para estabilidad
foreach ($IP in $ActiveIPs) {
    ping -n 1 -w 100 $IP | Out-Null
}
Start-Sleep -Seconds 2 



Write-Host "FASE 2: Recolectando detalles (MAC, DNS, Puertos) de forma segura..."

$ActiveResults = @()

foreach ($IP in $ActiveIPs) {
    
    
    $HostName = $null
    try { $HostName = [System.Net.Dns]::GetHostByAddress($IP).HostName } catch { $HostName = "N/A" }
    
   
    $MacAddress = $null
    $arp = cmd /c "arp -a $IP" | Select-String -Pattern "([0-9a-fA-F]{2}[:-]){5}([0-9a-fA-F]{2})" 
    if ($arp) { 
    
        $MacAddress = $arp.Matches[0].Groups[1].Value.Trim() 
    }
    else { 
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
        MacAddress = ($MacAddress -replace '-', ':') -replace ' ', ':'
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
    Write-Host "Conexión a la BD exitosa para la inserción de datos." -ForegroundColor Green
    
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
            
            
            $SQL_Get_Prev_Ports = "SELECT GROUP_CONCAT(p.numero ORDER BY p.numero ASC) FROM $TableNameProtocoloUsado pu JOIN protocolo p ON pu.id_protocolo = p.id_protocolo WHERE pu.id_equipo = $ID_Equipo_Existente;"
            $Cmd = New-Object System.Data.Odbc.OdbcCommand($SQL_Get_Prev_Ports, $Conn)
            $PrevPortsString = $Cmd.ExecuteScalar()
            
           
            $CurrentPortsString = ($R.Ports | Sort-Object) -join ',' 
            $PrevPortsString = ($PrevPortsString -split ',') | ForEach-Object { [string]$_ } | Sort-Object | Out-String -NoNewline
            $PrevPortsString = $PrevPortsString.Trim()

            if ($CurrentPortsString -eq $PrevPortsString) {
                
                $Action = "UPDATE"
                $ID_Equipo = $ID_Equipo_Existente
            }
            else {
                
                $Action = "INSERT" 
                Write-Host "ALERTA: Se detectó un CAMBIO DE SERVICIOS en $($R.IP). Forzando nuevo evento." -ForegroundColor DarkYellow
            }
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
            $SQL_GetID = "SELECT id_equipo FROM $TableNameEquipo WHERE ip = '$IP_Escaped' AND macaddress = '$Mac_Escaped' AND hostname = '$HostName_Escaped' ORDER BY fecha_registro DESC LIMIT 1;"
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