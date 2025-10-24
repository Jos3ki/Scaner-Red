# 🔍 Network Scanner

> Herramienta robusta de escaneo de red diseñada para la **auditoría de activos** y el **monitoreo de infraestructura**. Automatiza la recopilación de datos críticos de dispositivos conectados y almacena la información de forma persistente.

## 🌟 Características

Este escáner en PowerShell automatiza la obtención de información clave de los dispositivos de una subred:

| Tipo de Dato | Detalle |
| :--- | :--- |
| **Identificación** | Dirección **IP**, **Hostname** del equipo activo y **Fabricante** del equipo. |
| **Hardware** | Dirección **MAC** del equipo. |
| **Servicios** | **Puertos** TCP abiertos y su protocolo asociado. |
| **Persistencia** | Almacenamiento de todos los resultados en una base de datos centralizada. |

## 🚀 Empezando

### Prerrequisitos

* **Lenguaje:** Necesitas tener instalado **PowerShell** (este proyecto actualmente solo funciona en Windows 11 y version de PS mayor a 5.1).
* **Base de Datos:** Acceso a un servidor **[MySQL / PostgreSQL]**.
* **ODBC:** El driver **[Mysql/Connector/ODBC 9.5.0]** debe estar instalado.

### Instalación y Configuración

1.  **Clonar el Repositorio:**
    ```bash
    git clone https://github.com/Jos3ki/Scaner-Red/
    cd Scaner-Red
    ```

2.  **Configuración de Credenciales (CRÍTICO):**
    Modifica los archivos api.php y el script.ps1 con los datos de conexion para la Base de datos, verifica que el nombre de la BD y de las tablas coincida (usa el archivo .sql para generar la BD lista para empezar a usarse)

## 💻 Uso

Ejecuta el script de PowerShell principal:

```powershell
.\Script6.ps1
