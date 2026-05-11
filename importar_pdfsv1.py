"""
importar_pdfs.py
================
Lee los PDFs de anexo por línea desde una carpeta local
y los vuelca a la base de datos MySQL de la app Movistar.

USO:
    python importar_pdfs.py

ESTRUCTURA DE CARPETA ESPERADA:
    C:/Movistar/
        05-2026/
            20260504_1167642458_Anexo.pdf
            20260504_2215898969_Anexo.pdf
            20260505_CuadroResumen.pdf   ← resumen general (opcional)
            ...
        04-2026/
            ...

INSTALACIÓN DE DEPENDENCIAS (ejecutar una sola vez):
    pip install pymupdf pymysql python-dotenv
"""

import os
import re
import sys
import glob
import pymysql
import fitz          # PyMuPDF
from datetime import datetime, date
from decimal import Decimal

# ─── CONFIGURACIÓN ────────────────────────────────────────────────────────────
# Carpeta raíz donde guardás los PDFs (modificá esta ruta)
CARPETA_BASE = r"C:\Users\Hernan\OneDrive\carpeta trabajo\Facturas 2026\Movistar"

# Conexión a la base de datos
DB_HOST     = "45.227.162.14"
DB_PORT     = 3306
DB_USER     = "claude_app"
DB_PASSWORD = "32hfF!LTc"          # ← completá con tu contraseña actual
DB_NAME     = "app_movistar"

# Tasa de IVA+percepciones aproximada (27% IVA + 3% perc IVA + 4% IIBB)
IVA_RATE = Decimal("0.3397")

# ─── CONEXIÓN ─────────────────────────────────────────────────────────────────
def conectar():
    return pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASSWORD,
        database=DB_NAME,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor
    )

# ─── EXTRACCIÓN DE TEXTO DEL PDF ──────────────────────────────────────────────
def extraer_texto(ruta_pdf):
    doc  = fitz.open(ruta_pdf)
    texto = ""
    for pagina in doc:
        texto += pagina.get_text()
    doc.close()
    return texto

# ─── PARSEO DEL ANEXO POR LÍNEA ───────────────────────────────────────────────
def parsear_anexo(texto, numero_linea):
    """
    Extrae del texto del PDF:
      - plan contratado
      - importe bruto
      - bonificación
      - neto sin impuestos
    """
    resultado = {
        "plan":          None,
        "importe_bruto": Decimal("0"),
        "bonificacion":  Decimal("0"),
        "neto_sin_imp":  Decimal("0"),
    }

    # Plan contratado
    m = re.search(r"(Control Empresas\s+\d+\s*GB\s*\w*)", texto, re.IGNORECASE)
    if m:
        resultado["plan"] = m.group(1).strip()

    # Importe bruto del plan (primer valor grande en la sección TELEFONIA MOVIL)
    m = re.search(r"Control Empresas.*?(\d{1,3}(?:\.\d{3})*,\d{2})\.\s*\1", texto, re.DOTALL)
    if m:
        resultado["importe_bruto"] = parse_monto(m.group(1))

    # Bonificación (busca "Bonificacion Plan Control" seguido de monto)
    m = re.search(r"Bonificacion Plan Control[^\d]*(\d{1,3}(?:\.\d{3})*,\d{2})\s*-", texto)
    if m:
        resultado["bonificacion"] = parse_monto(m.group(1))

    # Total TELEFONIA MOVIL = neto sin impuestos
    m = re.search(r"Total TELEFONIA MOVIL\s+(\d{1,3}(?:\.\d{3})*,\d{2})", texto)
    if m:
        resultado["neto_sin_imp"] = parse_monto(m.group(1))

    # Si no encontró neto, calcularlo
    if resultado["neto_sin_imp"] == 0 and resultado["importe_bruto"] > 0:
        resultado["neto_sin_imp"] = resultado["importe_bruto"] - resultado["bonificacion"]

    return resultado

# ─── PARSEO DEL CUADRO RESUMEN (total con impuestos por línea) ────────────────
def parsear_resumen(texto):
    """
    Del CuadroResumen extrae el total con impuestos por línea.
    Retorna dict: { numero_linea: total_con_imp }
    """
    totales = {}
    # Busca líneas como: 1167642458  14.311,20
    patron = re.compile(r"(\d{10})\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+([\d\.,]+)")
    for m in patron.finditer(texto):
        num   = m.group(1)
        total = parse_monto(m.group(2))
        totales[num] = total
    return totales

def parsear_factura_general(texto):
    """Extrae datos generales de la factura del comprobante principal."""
    datos = {
        "numero_factura":     None,
        "fecha_emision":      None,
        "fecha_vencimiento":  None,
        "total_factura":      Decimal("0"),
        "cuenta_cliente":     None,
    }

    m = re.search(r"Factura\s+([\d\-]+)", texto)
    if m: datos["numero_factura"] = m.group(1)

    m = re.search(r"Fecha de emisión:\s*(\d{2}/\d{2}/\d{4})", texto)
    if m: datos["fecha_emision"] = parse_fecha(m.group(1))

    m = re.search(r"Vencimiento\s+(\d{2}/\d{2}/\d{4})", texto)
    if m: datos["fecha_vencimiento"] = parse_fecha(m.group(1))

    m = re.search(r"Total a Pagar:\s*\$([\d\.,]+)", texto)
    if m: datos["total_factura"] = parse_monto(m.group(1))

    m = re.search(r"Cliente\s+N[°º]:\s*(\d+)", texto)
    if m: datos["cuenta_cliente"] = m.group(1)

    return datos

# ─── HELPERS ──────────────────────────────────────────────────────────────────
def parse_monto(s):
    """Convierte '14.311,20' → Decimal('14311.20')"""
    try:
        return Decimal(s.replace(".", "").replace(",", "."))
    except:
        return Decimal("0")

def parse_fecha(s):
    """Convierte 'dd/mm/yyyy' → date"""
    try:
        return datetime.strptime(s, "%d/%m/%Y").date()
    except:
        return None

def detectar_periodo(nombre_carpeta):
    """
    Acepta varios formatos:
      '05-2026' → '05-2026'
      '05'      → '05-2026'  (asume año 2026)
      '5'       → '05-2026'
    """
    if re.match(r"^\d{2}-\d{4}$", nombre_carpeta):
        return nombre_carpeta
    if re.match(r"^\d{1,2}$", nombre_carpeta):
        mes = nombre_carpeta.zfill(2)
        return f"{mes}-2026"
    return None

def nombre_a_linea(nombre_archivo):
    """'20260504_1167642458_Anexo.pdf' → '1167642458'"""
    m = re.search(r"_(\d{10})_", nombre_archivo)
    return m.group(1) if m else None

# ─── LÓGICA PRINCIPAL ─────────────────────────────────────────────────────────
def procesar_periodo(conn, carpeta_periodo, periodo):
    print(f"\n{'='*55}")
    print(f"  Procesando período: {periodo}")
    print(f"{'='*55}")

    cur = conn.cursor()

    # 1. Buscar comprobante general (el PDF grande, no el Anexo)
    comprobante = None
    resumen_totales = {}
    for f in os.listdir(carpeta_periodo):
        ruta = os.path.join(carpeta_periodo, f)
        if "Comprobante" in f and f.endswith(".pdf"):
            print(f"  📄 Comprobante: {f}")
            texto = extraer_texto(ruta)
            comprobante = parsear_factura_general(texto)
        elif "Resumen" in f and f.endswith(".pdf"):
            print(f"  📊 Cuadro resumen: {f}")
            texto = extraer_texto(ruta)
            resumen_totales = parsear_resumen(texto)

    if not comprobante:
        print("  ⚠️  No se encontró comprobante general. Se usarán valores estimados.")
        comprobante = {
            "numero_factura": None, "fecha_emision": None,
            "fecha_vencimiento": None, "total_factura": Decimal("0"),
            "cuenta_cliente": "91514949",
        }

    # 2. Insertar o actualizar factura
    cur.execute("""
        INSERT INTO facturas
            (periodo, fecha_emision, fecha_vencimiento, total_factura, numero_factura, cuenta_cliente)
        VALUES (%s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            fecha_emision     = VALUES(fecha_emision),
            fecha_vencimiento = VALUES(fecha_vencimiento),
            total_factura     = VALUES(total_factura),
            numero_factura    = VALUES(numero_factura)
    """, (
        periodo,
        comprobante["fecha_emision"],
        comprobante["fecha_vencimiento"],
        comprobante["total_factura"],
        comprobante["numero_factura"],
        comprobante["cuenta_cliente"],
    ))
    conn.commit()

    cur.execute("SELECT id FROM facturas WHERE periodo = %s", (periodo,))
    factura_id = cur.fetchone()["id"]
    print(f"  ✓ Factura ID: {factura_id}")

    # 3. Procesar cada PDF de anexo
    anexos = [f for f in os.listdir(carpeta_periodo)
              if "Anexo" in f and f.endswith(".pdf")]
    print(f"  📁 Anexos encontrados: {len(anexos)}")

    for nombre in sorted(anexos):
        numero_linea = nombre_a_linea(nombre)
        if not numero_linea:
            continue

        ruta = os.path.join(carpeta_periodo, nombre)
        texto = extraer_texto(ruta)
        datos = parsear_anexo(texto, numero_linea)

        # Total con impuestos: del resumen si está, sino calculado
        total_con_imp = resumen_totales.get(numero_linea, Decimal("0"))
        if total_con_imp == 0 and datos["neto_sin_imp"] > 0:
            total_con_imp = datos["neto_sin_imp"] * (1 + IVA_RATE)
            total_con_imp = total_con_imp.quantize(Decimal("0.01"))

        iva = total_con_imp - datos["neto_sin_imp"] if total_con_imp > 0 else Decimal("0")

        # Buscar linea_id
        cur.execute("SELECT id FROM lineas WHERE numero = %s", (numero_linea,))
        row = cur.fetchone()
        if not row:
            print(f"    ⚠️  Línea {numero_linea} no encontrada en BD, saltando...")
            continue
        linea_id = row["id"]

        cur.execute("""
            INSERT INTO detalle_lineas
                (factura_id, linea_id, plan, importe_bruto, bonificacion,
                 neto_sin_imp, iva_y_percepciones, total_con_imp)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                plan              = VALUES(plan),
                importe_bruto     = VALUES(importe_bruto),
                bonificacion      = VALUES(bonificacion),
                neto_sin_imp      = VALUES(neto_sin_imp),
                iva_y_percepciones= VALUES(iva_y_percepciones),
                total_con_imp     = VALUES(total_con_imp)
        """, (
            factura_id, linea_id,
            datos["plan"],
            datos["importe_bruto"],
            datos["bonificacion"],
            datos["neto_sin_imp"],
            iva,
            total_con_imp,
        ))

        print(f"    ✓ {numero_linea} — neto: ${datos['neto_sin_imp']:>10} | total: ${total_con_imp:>10}")

    conn.commit()

    # 4. Generar/actualizar registros de cobros por pagador
    print(f"\n  💰 Generando cobros por pagador...")
    cur.execute("""
        SELECT l.pagador, l.cobra_iva,
               SUM(d.neto_sin_imp)      AS neto,
               SUM(d.iva_y_percepciones) AS iva,
               SUM(d.total_con_imp)     AS total
        FROM detalle_lineas d
        JOIN lineas l ON l.id = d.linea_id
        WHERE d.factura_id = %s
        GROUP BY l.pagador, l.cobra_iva
    """, (factura_id,))
    pagadores = cur.fetchall()

    for p in pagadores:
        monto_total = p["total"] if p["cobra_iva"] else p["neto"]
        monto_iva   = p["iva"]   if p["cobra_iva"] else Decimal("0")

        cur.execute("""
            INSERT INTO cobros (factura_id, pagador, monto_neto, monto_iva, monto_total)
            VALUES (%s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                monto_neto  = VALUES(monto_neto),
                monto_iva   = VALUES(monto_iva),
                monto_total = VALUES(monto_total)
        """, (factura_id, p["pagador"], p["neto"], monto_iva, monto_total))

        print(f"    ✓ {p['pagador']:15} → ${monto_total:>10} {'(c/IVA)' if p['cobra_iva'] else '(s/IVA)'}")

    conn.commit()
    print(f"\n  ✅ Período {periodo} importado correctamente.")

# ─── ENTRY POINT ──────────────────────────────────────────────────────────────
def main():
    if not os.path.isdir(CARPETA_BASE):
        print(f"❌ No se encontró la carpeta: {CARPETA_BASE}")
        print("   Editá CARPETA_BASE en el script.")
        sys.exit(1)

    print(f"📂 Carpeta base: {CARPETA_BASE}")
    print(f"🗄️  Base de datos: {DB_NAME} en {DB_HOST}")

    try:
        conn = conectar()
        print("✅ Conexión a MySQL exitosa\n")
    except Exception as e:
        print(f"❌ Error de conexión: {e}")
        sys.exit(1)

    # Buscar subcarpetas con formato MM-YYYY
    periodos = []
    for entrada in sorted(os.listdir(CARPETA_BASE)):
        ruta = os.path.join(CARPETA_BASE, entrada)
        if os.path.isdir(ruta) and detectar_periodo(entrada):
            periodos.append((entrada, ruta))

    if not periodos:
        print("⚠️  No se encontraron carpetas con formato MM-YYYY en la carpeta base.")
        print("   Ejemplo esperado: C:\\Movistar\\05-2026\\")
        sys.exit(0)

    print(f"📅 Períodos encontrados: {[p[0] for p in periodos]}")

    for periodo, carpeta in periodos:
        try:
            procesar_periodo(conn, carpeta, periodo)
        except Exception as e:
            print(f"\n❌ Error procesando {periodo}: {e}")
            import traceback; traceback.print_exc()

    conn.close()
    print(f"\n{'='*55}")
    print("  🎉 Importación finalizada")
    print(f"{'='*55}\n")

if __name__ == "__main__":
    main()