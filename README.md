# 🔍 Network Scanner Pro

> Herramienta robusta de escaneo de red diseñada para la **auditoría de activos** y el **monitoreo de infraestructura**. Automatiza la recopilación de datos críticos de dispositivos conectados y almacena la información de forma persistente.

## 🌟 Características

Este escáner en PowerShell automatiza la obtención de información clave de los dispositivos de una subred:

| Tipo de Dato | Detalle |
| :--- | :--- |
| **Identificación** | Dirección **IP** y **Hostname** del equipo activo. |
| **Hardware** | Dirección **MAC** del equipo. |
| **Servicios** | **Puertos** TCP abiertos y su protocolo asociado. |
| **Persistencia** | Almacenamiento de todos los resultados en una base de datos centralizada. |

## 🚀 Empezando

### Prerrequisitos

* **Lenguaje:** Necesitas tener instalado **PowerShell** (funciona en Windows).
* **Base de Datos:** Acceso a un servidor **[MySQL / PostgreSQL]**.
* **ODBC:** El driver **[Nombre del Driver ODBC]** debe estar instalado.

### Instalación y Configuración

1.  **Clonar el Repositorio:**
    ```bash
    git clone [https://www.youtube.com/watch?v=k5dxsrEeJ8s](https://www.youtube.com/watch?v=k5dxsrEeJ8s)
    cd [nombre-del-repo]
    ```

2.  **Configuración de Credenciales (CRÍTICO):**
    Crea un archivo llamado **`.env`** o **`config.ini`** en la raíz (está ignorado por Git) e ingresa tus datos de conexión a la BD.

## 💻 Uso

Ejecuta el script de PowerShell principal:

```powershell
.\Scanner.ps1
