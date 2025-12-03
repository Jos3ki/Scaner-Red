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



## 🚀 Metodología del Proyecto: eXtreme Programming (XP)

Este proyecto de **Servicio de Auditoría de Sistema en C++** se desarrolló utilizando la metodología **eXtreme Programming (XP)**. Elegimos este enfoque Ágil por su adaptabilidad, su énfasis en la **calidad del código** y su eficiencia para un equipo pequeño de tres personas.

### 🎯 Principales Prácticas y Justificación

| Criterio Clave | Práctica de XP Aplicada | Beneficio para el Proyecto |
| :--- | :--- | :--- |
| **Calidad y Rendimiento (C++)** | **Pair Programming (Programación en Parejas)** y **Refactorización Constante** | Asegura una revisión de código continua, minimiza errores y optimiza el rendimiento del ejecutable en C++ que corre como servicio. |
| **Equipo de 3 Personas** | **Rotación de Roles (Driver, Tester/Navigator, Customer Proxy)** | Maximiza el conocimiento colectivo (Propiedad Colectiva) y asegura que siempre haya una persona enfocada en la planificación y la revisión de calidad (QA). |
| **Integración Constante** | **Integración Continua (CI)** y **Pruebas Unitarias** | El código se integra al `main` varias veces al día para mantener la estabilidad. Cada componente (ej., `getLocalIP`) tiene una prueba para garantizar su fiabilidad. |

***

### ⚙️ Procesos Fundamentales Aplicados

1.  **Integración Continua (CI):** El código se fusionó al repositorio principal varias veces al día. Esto fue crucial para detectar y resolver conflictos entre las librerías de red (`Winsock`) y el conector de la base de datos (`MySQL Connector/C++`) de manera temprana.
2.  **Refactorización Constante:** Mejoramos continuamente el diseño del código para mantenerlo limpio y legible, sin alterar su funcionalidad externa, lo cual facilita el mantenimiento a largo plazo del servicio.
3.  **Pruebas Unitarias:** Se implementaron pruebas para las funciones clave del sistema, garantizando que el servicio de auditoría siempre devuelva datos correctos y válidos.

***

### 🔄 Fases de Desarrollo

El proyecto siguió un ciclo iterativo (Sprints de 1 semana) con las siguientes fases:

| Fase | Enfoque Principal | Hito de Finalización |
| :--- | :--- | :--- |
| **Exploración** | Configuración inicial y enlace de librerías. | El ejecutable compila y se conecta a MariaDB con éxito. |
| **Iteraciones** | Desarrollo de las **Historias de Usuario (HU)** y pruebas bajo el usuario **SYSTEM** (verificado con **PsExec**). |
| **Puesta en Producción** | Despliegue e instalación final. | El servicio se instala con **NSSM** (Non-Sucking Service Manager) y arranca automáticamente al iniciar Windows. |
