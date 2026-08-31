# SmartRACK — ePDU Inteligente

Sistema de distribución de energía inteligente (ePDU) con monitoreo en tiempo real, control remoto por toma y comunicación segura vía MQTT sobre TLS. Desarrollado como proyecto interdisciplinario de septimo año del Instituto Leonardo Murialdo en colaboración con AucaTek.

---

## Descripción

SmartRACK permite controlar y monitorear racks de servidores de forma remota desde cualquier navegador. Cada toma de corriente puede encenderse o apagarse individualmente, y el sistema mide voltaje, corriente, potencia, frecuencia, consumo acumulado, temperatura y humedad en tiempo real.

El hardware está basado en un ESP32 con módulo Ethernet W5500 que se comunica con el backend mediante MQTT sobre TLS. El portal web corre en PHP con MySQL y está deployado en producción con HTTPS activo.

---

## Tecnologías

### Firmware
![Arduino](https://img.shields.io/badge/Arduino-ESP32-00979D?logo=arduino&logoColor=white)
![C++](https://img.shields.io/badge/C++-Firmware-00599C?logo=cplusplus&logoColor=white)

- Arduino IDE / C++
- PubSubClient (MQTT)
- SSLClient — OPEnS Lab (TLS sobre Ethernet)
- Ethernet.h (W5500)
- PZEM004Tv30, AHTxx, ArduinoJson, NTPClient

### Backend
![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-DB-4479A1?logo=mysql&logoColor=white)

- PHP 8.2 + MySQLi
- PHPMailer
- phpdotenv
- php-mqtt/client v2.3.2
- Composer

### Frontend
![Bootstrap](https://img.shields.io/badge/Bootstrap-5-7952B3?logo=bootstrap&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-Vanilla-F7DF1E?logo=javascript&logoColor=black)

- Tabler.io (Bootstrap 5)
- Font Awesome 5.15.4
- Chart.js
- Vanilla JS (sin jQuery)
- Montserrat (Google Fonts)

### Infraestructura
![MQTT](https://img.shields.io/badge/MQTT-HiveMQ_Cloud-660066)
![Hosting](https://img.shields.io/badge/Hosting-alwaysdata-23264f)

- HiveMQ Cloud (broker MQTT, TLS puerto 8883)
- alwaysdata (hosting PHP + MySQL)
- GitHub (control de versiones)

---

## Hardware

| Componente | Función |
|---|---|
| ESP32 NodeMCU 38 pines | Microcontrolador principal |
| W5500 (USR-ES1) | Ethernet 100Mbps, TCP/IP en hardware |
| PZEM-004T v3 | Medición eléctrica — V, A, W, PF, Hz, kWh |
| AHT10 | Temperatura y humedad interna del PDU |
| Módulo relé 4 canales | Control ON/OFF de 4 tomas de 220V AC |

---

## Funcionalidades

- Control ON/OFF individual de 4 tomas inteligentes + 1 toma fija
- Telemetría eléctrica en tiempo real (modo Premium)
- Monitoreo de temperatura y humedad
- Alertas automáticas por umbral con notificación por mail
- Sistema de usuarios con 3 roles: Admin, Operator, Viewer
- Exportación de datos históricos en CSV
- Buffer offline — los datos se guardan localmente si se pierde la conexión
- Interfaz web local accesible por IP sin necesidad de internet
- Ciberseguridad: rate limiting, CSRF, security logs, CSP, HSTS

---

## Equipo core

| Integrante | Rol |
|---|---|
| Ezequiel Morgado |Project Manager, Backend, base de datos, integración MQTT |
| Camilo Spinelli | Hardware, firmware|
| Sofía Hermosilla | Frontend, UX/UI Design |
| Violeta Martini | Diseño industrial |
| Martín Barral | Asesor técnico |
| Diego Martini | CEO AucaTek — cliente |

---

## Links

- **Portal web (producción):** https://aucateksmartrack.alwaysdata.net
- **Documentación del proyecto:** https://github.com/ezemorgado07/smartrack/blob/main/SmartRACK_Documentaci%C3%B3nT%C3%A9cnica.pdf
- **Manual de usuario:** [https://raw.githubusercontent.com/ezemorgado07/smartrack/main/SmartRACK_Manual_Usuario.pdf](https://github.com/ezemorgado07/smartrack/blob/main/SmartRACK_Manual_Usuario.pdf)

---

## Instituto Leonardo Murialdo · AucaTek · 2026
