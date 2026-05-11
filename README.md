# App Cobros Movistar — Instrucciones de instalación

## Archivos incluidos

| Archivo | Descripción |
|---|---|
| `01_estructura.sql` | Crea las tablas en MySQL |
| `02_importar_pdfs.py` | Script Python para leer PDFs e importar a MySQL |
| `03_config.php` | Configuración del servidor (DB, sesiones) |
| `04_api.php` | Backend PHP (login, facturas, cobros) |
| `05_index.html` | App web completa |

---

## PASO 1 — Crear la base de datos en ISPConfig

1. Entrá a ISPConfig → Bases de datos
2. Creá una base llamada `app_movistar`
3. Asegurate que el usuario `claude_app` tenga todos los permisos sobre ella

---

## PASO 2 — Importar la estructura SQL

En ISPConfig → phpMyAdmin (o similar):
1. Seleccioná la base `app_movistar`
2. Ir a **Importar**
3. Subí el archivo `01_estructura.sql` y ejecutalo

Esto crea las tablas y carga las 16 líneas de la flota.

**Contraseña inicial de la web:** `movistar2026`
(podés cambiarla en phpMyAdmin: tabla `usuarios`, campo `password`,
generá un nuevo hash en https://bcrypt-generator.com con costo 12)

---

## PASO 3 — Subir los archivos PHP al servidor

En ISPConfig creá el sitio `movistar.sitiospyme.com.ar` apuntando a
una carpeta, por ejemplo `/var/www/movistar/`.

Subí estos archivos a esa carpeta:
```
/var/www/movistar/
    config.php
    api.php
    index.html
```

**Editá `config.php`** y ponés tu contraseña actual de MySQL:
```php
define('DB_PASS', 'tu_contraseña_aqui');
```

---

## PASO 4 — Instalar Python en tu PC (si no lo tenés)

Descargá Python desde https://www.python.org/downloads/
(elegí la versión más reciente, marcá "Add to PATH" al instalar)

Luego abrí una terminal (cmd o PowerShell) y ejecutá:
```
pip install pymupdf pymysql
```

---

## PASO 5 — Configurar el script Python

Abrí `02_importar_pdfs.py` con el Bloc de notas y editá estas líneas:

```python
CARPETA_BASE = r"C:\Movistar"     # carpeta donde guardás los PDFs
DB_PASSWORD  = "tu_contraseña"    # contraseña MySQL actual
```

---

## PASO 6 — Organizar los PDFs en carpetas

La estructura de carpetas debe ser así:

```
C:\Movistar\
    05-2026\
        20260504_1167642458_Anexo.pdf
        20260504_2215898969_Anexo.pdf
        20260504_2346511132_Anexo.pdf
        20260504_2346597390_Anexo.pdf
        20260504_2346686005_Anexo.pdf
        20260505_Comprobante.pdf        ← opcional pero recomendado
        20260505_CuadroResumen.pdf      ← opcional pero recomendado
    04-2026\
        ...
```

---

## PASO 7 — Ejecutar la importación

Abrí una terminal en la carpeta donde está el script y ejecutá:
```
python importar_pdfs.py
```

Vas a ver algo así:
```
✅ Conexión a MySQL exitosa
📅 Períodos encontrados: ['05-2026']

=======================================================
  Procesando período: 05-2026
=======================================================
  📄 Comprobante: 20260505_Comprobante.pdf
  📁 Anexos encontrados: 16
    ✓ 1167642458 — neto:  $10.680,00 | total: $14.311,20
    ✓ 2215898969 — neto:  $10.680,00 | total: $14.311,20
    ...
  ✅ Período 05-2026 importado correctamente.

🎉 Importación finalizada
```

---

## PASO 8 — Usar la app

Abrí el navegador y entrá a:
**https://movistar.sitiospyme.com.ar**

Usuario: `admin`
Contraseña: `movistar2026`

---

## Flujo mensual (cada vez que llega la factura)

1. Descargá los PDFs de los anexos por línea desde tu portal Movistar
2. Copiálos a `C:\Movistar\MM-YYYY\` (ej: `C:\Movistar\06-2026\`)
3. Ejecutás `python importar_pdfs.py`
4. Abrís la web y ya aparece el mes nuevo

---

## Notas de seguridad

- La web requiere login, sin sesión activa no se ve nada
- La API rechaza requests no autenticados
- Cambiá la contraseña inicial `movistar2026` por una tuya
- El acceso MySQL remoto desde tu IP ya está configurado en ISPConfig
