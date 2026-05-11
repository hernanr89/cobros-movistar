# Cobros Movistar · Flota Rodríguez Hernán

Sistema web para gestionar los cobros de la flota de celulares Movistar, cuenta cliente 91514949.

## Stack

| Componente | Tecnología |
|---|---|
| Servidor | ISPConfig · PHP 7.3.3 · MySQL |
| IP servidor | 45.227.162.14 |
| URL app | https://sitiospyme.com.ar/movistar/ |
| Base de datos | app_movistar |
| Usuario DB | claude_app |
| Importación PDFs | Python 3.14 (Windows local) |

---

## Estructura del proyecto

```
App Movistar/
├── files/
│   └── importar_pdfs.py        ← script de importación (correr en PC local)
├── server/
│   ├── config.php              ← configuración DB y sesión
│   ├── api.php                 ← backend REST
│   └── index.html              ← app web
└── sql/
    ├── 01_estructura.sql       ← tablas base
    ├── 01b_fix_cobros.sql      ← fix TIMESTAMP MySQL antiguo
    ├── 01c_usuarios.sql        ← tabla usuarios + admin inicial
    ├── 03_migracion.sql        ← usuario_lineas + usuario_id en cobros
    ├── 04_migracion_estados.sql← estado de cobros + tabla comprobantes
    └── 05_migracion_seguridad.sql ← intentos_fallidos + bloqueado + patron
```

---

## Base de datos

### Tablas

| Tabla | Descripción |
|---|---|
| `lineas` | 16 líneas de la flota con titular y número |
| `usuario_lineas` | Asignación de líneas a usuarios (cobra, cobra_iva) |
| `usuarios` | Usuarios del sistema con roles |
| `facturas` | Una por mes/período |
| `detalle_lineas` | Neto e impuestos por línea por factura |
| `cobros` | Un registro por usuario por mes |
| `comprobantes` | Archivos subidos por usuarios al avisar pagos |

### Estados de cobro (`cobros.estado`)

| Estado | Descripción |
|---|---|
| `pendiente` | No cobrado |
| `esperando_aprobacion` | Usuario avisó que pagó, admin pendiente de confirmar |
| `cobrado` | Admin aprobó / registró directamente |
| `rechazado` | Admin rechazó el pago del usuario |

### Roles de usuario

| Rol | Permisos |
|---|---|
| `admin` | Acceso total: ver todas las líneas, registrar cobros, gestionar usuarios |
| `readonly` | Solo ve su cuenta, puede marcar "Pagué" con comprobante |

---

## Instalación desde cero

### 1. Base de datos

Ejecutar en orden en phpMyAdmin:

```
sql/01_estructura.sql
sql/01b_fix_cobros.sql
sql/01c_usuarios.sql
sql/03_migracion.sql
sql/04_migracion_estados.sql
sql/05_migracion_seguridad.sql
```

### 2. Servidor

Subir por FTP a `/var/www/sitiospyme.com.ar/web/movistar/`:

```
config.php    ← completar DB_PASS con contraseña actual
api.php
index.html
```

Crear carpeta con permisos de escritura:
```
/movistar/comprobantes/
```

Usuario inicial: `admin` / `movistar2026`
(cambiar contraseña al primer login)

### 3. Script Python (PC local)

```bash
pip install pymysql pdfplumber
```

Editar `importar_pdfs.py`:
```python
CARPETA_BASE = r"C:\Users\Hernan\OneDrive\carpeta trabajo"
DB_PASSWORD  = "contraseña_actual"
```

---

## Estructura de carpetas de PDFs

```
C:/Users/Hernan/OneDrive/carpeta trabajo/
    Facturas 2025/
        Movistar/
            01/   ← PDFs de enero
            02/
            ...
    Facturas 2026/
        Movistar/
            01/
            ...
            05/
                20260504_1167642458_Anexo.pdf   ← anexo por línea
                20260505__CuadroResumen.pdf      ← resumen general
```

### Nombres de archivos

- Anexos: `YYYYMMDD_XXXXXXXXXX_Anexo.pdf` (10 dígitos = número de línea)
- Resumen: cualquier archivo con "CuadroResumen" o "Comprobante" en el nombre

---

## Flujo mensual

1. Descargar PDFs de Movistar (anexos por línea + comprobante) en `Facturas YYYY/Movistar/MM/`
2. Ejecutar `python importar_pdfs.py`
3. La web muestra el mes nuevo automáticamente

---

## Cálculo de cobros

### Fórmula del neto por línea

```
neto = Total TELEFONIA MOVIL + Total Plan Movistar + Total Adicionales
```

- **Meses anteriores**: el descuento ya está incluido en `Total TELEFONIA MOVIL`
- **Mayo 2026+**: `Total Plan Movistar` es negativo (bonificación separada)

### IVA y percepciones

```
total_con_imp = neto × 1.3397
```

Factor: IVA 27% + Percepción IVA 3% + IIBB 4%

### Cobro por usuario

- Cada usuario tiene líneas asignadas en `usuario_lineas`
- `cobra = 0` → la línea no genera deuda para ese usuario
- `cobra_iva = 1` → se cobra el total con impuestos (Matias)
- `cobra_iva = 0` → se cobra solo el neto (resto)

---

## API REST

Base URL: `https://sitiospyme.com.ar/movistar/api.php?action=`

| Acción | Método | Rol | Descripción |
|---|---|---|---|
| `login` | POST | — | Autenticación |
| `logout` | POST | — | Cerrar sesión |
| `me` | GET | any | Usuario actual |
| `cambiar_pass` | POST | any | Cambiar contraseña |
| `facturas` | GET | any | Lista de facturas |
| `factura` | GET | any | Detalle de un período |
| `cobro` | POST | admin | Registrar/desmarcar cobro |
| `cobro_multiple` | POST | admin | Cobro múltiple |
| `marcar_pagado` | POST | any | Usuario avisa pago (multipart) |
| `aprobar_cobro` | POST | admin | Aprobar pago de usuario |
| `rechazar_cobro` | POST | admin | Rechazar pago de usuario |
| `pendientes_aprobacion` | GET | admin | Lista pagos a confirmar |
| `mi_cuenta` | GET | any | Historial propio |
| `cuenta_usuario` | GET | admin | Historial de un usuario |
| `lineas` | GET | admin | Panel de líneas |
| `usuarios` | GET | admin | Lista de usuarios |
| `crear_usuario` | POST | admin | Crear usuario con líneas |
| `editar_usuario` | POST | admin | Editar usuario y líneas |
| `toggle_usuario` | POST | admin | Activar/desactivar |
| `desbloquear_usuario` | POST | admin | Desbloquear tras intentos fallidos |
| `asignar_lineas` | POST | admin | Reasignar líneas |

---

## Seguridad

- Sesiones PHP con timeout de 8 horas
- Bloqueo automático tras 2 intentos fallidos de login
- Admin puede desbloquear desde tab Usuarios
- Contraseñas hasheadas con bcrypt (cost 10)
- Comprobantes de pago guardados en `/comprobantes/` con nombre único

---

## Usuarios actuales

| Usuario | Rol | Líneas |
|---|---|---|
| admin | admin | S24, Hernán (2), Hernán (3) |
| carmen | readonly | Carmen, Gustavo |
| flavia | readonly | Fernando (cobra), Flavia (no cobra), Nacho (no cobra) |
| matias | readonly | Matias (c/IVA) |
| teresa | readonly | Teresa |
| federica | readonly | Federica (no cobra), Lorenzo (no cobra) |

---

## Líneas de la flota

| Número | Titular | Usuario |
|---|---|---|
| 1128355450 | Nacho | Flavia |
| 1138964519 | Sin nombre | Sin asignar |
| 1150558417 | Sin nombre | Sin asignar |
| 1167642458 | Matias | Matias |
| 2215898969 | Fernando | Flavia |
| 2346330100 | S24 | Rodríguez Hernán |
| 2346482582 | Hernán | Rodríguez Hernán |
| 2346502797 | Lorenzo | Federica |
| 2346511132 | Gustavo | Carmen |
| 2346563677 | Federica | Federica |
| 2346568019 | Flavia | Flavia |
| 2346571429 | Hernán | Rodríguez Hernán |
| 2346571436 | Local | Sin asignar |
| 2346597390 | Carmen | Carmen |
| 2346652570 | Sin nombre | Sin asignar |
| 2346686005 | Teresa | Teresa |

---

## Versiones / Changelog

### v1.0 — Mayo 2026
- Sistema base: importación de PDFs, cobros por pagador (nombre)
- Login con roles admin/readonly
- App web con tabs: Facturas, Mi cuenta, Usuarios

### v2.0 — Mayo 2026
- Migración a cobros por usuario_id
- Asignación de líneas a usuarios con cobra/cobra_iva por línea
- Tab "Cobros por usuario" para admin
- Tab "Líneas" panel general
- Pago múltiple para usuarios readonly

### v2.1 — Mayo 2026
- Estados de cobro: pendiente / esperando_aprobacion / cobrado / rechazado
- Usuarios pueden marcar "Pagué" y subir comprobante
- Tab "Aprobaciones" para admin con badge numérico
- Meses cobrados colapsados en desplegable
- Fecha manual al registrar cobros
- Bloqueo por intentos fallidos de login
