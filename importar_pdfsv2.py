"""
importar_pdfs.py
================
Lee los PDFs de anexo por línea desde una carpeta local
y los vuelca a la base de datos MySQL de la app Movistar.

USO:
    python importar_pdfs.py

ESTRUCTURA DE CARPETA SOPORTADA:
    Opción A — carpeta raíz con subcarpetas por año y mes:
        C:/Users/Hernan/OneDrive/carpeta trabajo/
            Facturas 2026/
                Movistar/
                    01/   ← meses
                    02/
                    05/
            Facturas 2025/
                Movistar/
                    10/
                    11/

    Opción B — directamente MM-YYYY:
        C:/Movistar/
            05-2026/
            04-2026/

INSTALACIÓN DE DEPENDENCIAS (ejecutar una sola vez):
    pip install pymupdf pymysql
"""

import os, re, sys, pymysql, fitz
from datetime import datetime
from decimal import Decimal

# ─── CONFIGURACIÓN ────────────────────────────────────────────
# Raíz donde están las carpetas "Facturas YYYY"
# El script busca automáticamente todas las subcarpetas con años
CARPETA_BASE = r"C:\Users\Hernan\OneDrive\carpeta trabajo"

# Conexión a la base de datos
DB_HOST     = "45.227.162.14"
DB_PORT     = 3306
DB_USER     = "claude_app"
DB_PASSWORD = "32hfF!LTc"   # ← completá con tu contraseña actual
DB_NAME     = "app_movistar"

IVA_RATE = Decimal("0.3397")

# ─── CONEXIÓN ─────────────────────────────────────────────────
def conectar():
    return pymysql.connect(
        host=DB_HOST, port=DB_PORT, user=DB_USER,
        password=DB_PASSWORD, database=DB_NAME,
        charset="utf8mb4", cursorclass=pymysql.cursors.DictCursor
    )

# ─── BUSCAR PERÍODOS ──────────────────────────────────────────
def buscar_periodos(carpeta_base):
    """
    Recorre la carpeta base buscando períodos en dos formatos:
      - Facturas YYYY / Movistar / MM  →  periodo = MM-YYYY
      - MM-YYYY (directamente)         →  periodo = MM-YYYY
      - MM (directamente)              →  periodo = MM-AÑO_ACTUAL
    Retorna lista de (periodo, ruta_completa) ordenada.
    """
    periodos = []

    for entrada in os.listdir(carpeta_base):
        ruta_entrada = os.path.join(carpeta_base, entrada)
        if not os.path.isdir(ruta_entrada):
            continue

        # ── Formato "Facturas YYYY" ──────────────────────────
        m = re.match(r"[Ff]acturas\s+(\d{4})", entrada)
        if m:
            anio = m.group(1)
            # Buscar subcarpeta Movistar
            ruta_movistar = os.path.join(ruta_entrada, "Movistar")
            if not os.path.isdir(ruta_movistar):
                continue
            for mes_str in sorted(os.listdir(ruta_movistar)):
                ruta_mes = os.path.join(ruta_movistar, mes_str)
                if not os.path.isdir(ruta_mes):
                    continue
                if re.match(r"^\d{1,2}$", mes_str):
                    mes = mes_str.zfill(2)
                    periodos.append((f"{mes}-{anio}", ruta_mes))
            continue

        # ── Formato MM-YYYY directamente ─────────────────────
        if re.match(r"^\d{2}-\d{4}$", entrada):
            periodos.append((entrada, ruta_entrada))
            continue

        # ── Formato solo MM directamente ─────────────────────
        if re.match(r"^\d{1,2}$", entrada):
            mes  = entrada.zfill(2)
            anio = str(datetime.now().year)
            periodos.append((f"{mes}-{anio}", ruta_entrada))

    periodos.sort(key=lambda x: (x[0][3:], x[0][:2]))  # ordenar por año luego mes
    return periodos

# ─── EXTRACCIÓN DE TEXTO ──────────────────────────────────────
def extraer_texto(ruta_pdf):
    doc   = fitz.open(ruta_pdf)
    texto = "".join(p.get_text() for p in doc)
    doc.close()
    return texto

# ─── PARSEOS ──────────────────────────────────────────────────
def parsear_anexo(texto):
    r = {"plan": None, "importe_bruto": Decimal("0"),
         "bonificacion": Decimal("0"), "neto_sin_imp": Decimal("0")}

    m = re.search(r"(Control Empresas\s+\d+\s*GB\s*\w*)", texto, re.IGNORECASE)
    if m: r["plan"] = m.group(1).strip()

    m = re.search(r"Total TELEFONIA MOVIL\s+([\d\.]+,\d{2})", texto)
    if m: r["neto_sin_imp"] = parse_monto(m.group(1))

    m = re.search(r"Bonificacion Plan Control[^\d]*([\d\.]+,\d{2})\s*-", texto)
    if m: r["bonificacion"] = parse_monto(m.group(1))

    m = re.search(r"Control Empresas.*?([\d\.]+,\d{2})\.\s*\1", texto, re.DOTALL)
    if m: r["importe_bruto"] = parse_monto(m.group(1))

    if r["neto_sin_imp"] == 0 and r["importe_bruto"] > 0:
        r["neto_sin_imp"] = r["importe_bruto"] - r["bonificacion"]

    return r

def parsear_comprobante(texto):
    d = {"numero_factura": None, "fecha_emision": None,
         "fecha_vencimiento": None, "total_factura": Decimal("0"),
         "cuenta_cliente": None}
    m = re.search(r"Factura\s+([\d\-]+)", texto)
    if m: d["numero_factura"] = m.group(1)
    m = re.search(r"Fecha de emisión:\s*(\d{2}/\d{2}/\d{4})", texto)
    if m: d["fecha_emision"] = parse_fecha(m.group(1))
    m = re.search(r"Vencimiento\s+(\d{2}/\d{2}/\d{4})", texto)
    if m: d["fecha_vencimiento"] = parse_fecha(m.group(1))
    m = re.search(r"Total a Pagar:\s*\$([\d\.,]+)", texto)
    if m: d["total_factura"] = parse_monto(m.group(1))
    m = re.search(r"Cliente\s+N[°º]:\s*(\d+)", texto)
    if m: d["cuenta_cliente"] = m.group(1)
    return d

def parsear_resumen(texto):
    totales = {}
    patron = re.compile(
        r"(\d{10})\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+"
        r"[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+"
        r"[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+[\d\.,]+\s+([\d\.,]+)"
    )
    for m in patron.finditer(texto):
        totales[m.group(1)] = parse_monto(m.group(2))
    return totales

def parse_monto(s):
    try: return Decimal(s.replace(".", "").replace(",", "."))
    except: return Decimal("0")

def parse_fecha(s):
    try: return datetime.strptime(s, "%d/%m/%Y").date()
    except: return None

def nombre_a_linea(nombre):
    m = re.search(r"_(\d{10})_", nombre)
    return m.group(1) if m else None

# ─── PROCESAR UN PERÍODO ──────────────────────────────────────
def procesar_periodo(conn, carpeta, periodo):
    print(f"\n{'='*55}")
    print(f"  Procesando período: {periodo}")
    print(f"{'='*55}")
    cur = conn.cursor()

    comprobante     = None
    resumen_totales = {}

    for f in os.listdir(carpeta):
        ruta = os.path.join(carpeta, f)
        if not f.endswith(".pdf"):
            continue
        texto = extraer_texto(ruta)
        if "Comprobante" in f:
            print(f"  📄 Comprobante: {f}")
            comprobante = parsear_comprobante(texto)
        elif "Resumen" in f or "CuadroResumen" in f:
            print(f"  📊 Cuadro resumen: {f}")
            resumen_totales = parsear_resumen(texto)

    if not comprobante:
        print("  ⚠️  Sin comprobante general — se usarán valores estimados.")
        comprobante = {"numero_factura": None, "fecha_emision": None,
                       "fecha_vencimiento": None, "total_factura": Decimal("0"),
                       "cuenta_cliente": "91514949"}

    # Insertar factura
    cur.execute("""
        INSERT INTO facturas
            (periodo, fecha_emision, fecha_vencimiento, total_factura,
             numero_factura, cuenta_cliente)
        VALUES (%s,%s,%s,%s,%s,%s)
        ON DUPLICATE KEY UPDATE
            fecha_emision     = VALUES(fecha_emision),
            fecha_vencimiento = VALUES(fecha_vencimiento),
            total_factura     = VALUES(total_factura),
            numero_factura    = VALUES(numero_factura)
    """, (periodo, comprobante["fecha_emision"], comprobante["fecha_vencimiento"],
          comprobante["total_factura"], comprobante["numero_factura"],
          comprobante["cuenta_cliente"]))
    conn.commit()

    cur.execute("SELECT id FROM facturas WHERE periodo = %s", (periodo,))
    factura_id = cur.fetchone()["id"]
    print(f"  ✓ Factura ID: {factura_id}")

    # Procesar anexos
    anexos = [f for f in os.listdir(carpeta) if "Anexo" in f and f.endswith(".pdf")]
    print(f"  📁 Anexos encontrados: {len(anexos)}")

    for nombre in sorted(anexos):
        numero = nombre_a_linea(nombre)
        if not numero:
            continue
        texto = extraer_texto(os.path.join(carpeta, nombre))
        datos = parsear_anexo(texto)

        total_con_imp = resumen_totales.get(numero, Decimal("0"))
        if total_con_imp == 0 and datos["neto_sin_imp"] > 0:
            total_con_imp = (datos["neto_sin_imp"] * (1 + IVA_RATE)).quantize(Decimal("0.01"))
        iva = total_con_imp - datos["neto_sin_imp"] if total_con_imp > 0 else Decimal("0")

        cur.execute("SELECT id FROM lineas WHERE numero = %s", (numero,))
        row = cur.fetchone()
        if not row:
            print(f"    ⚠️  Línea {numero} no en BD, saltando...")
            continue

        cur.execute("""
            INSERT INTO detalle_lineas
                (factura_id, linea_id, plan, importe_bruto, bonificacion,
                 neto_sin_imp, iva_y_percepciones, total_con_imp)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s)
            ON DUPLICATE KEY UPDATE
                plan=VALUES(plan), importe_bruto=VALUES(importe_bruto),
                bonificacion=VALUES(bonificacion), neto_sin_imp=VALUES(neto_sin_imp),
                iva_y_percepciones=VALUES(iva_y_percepciones),
                total_con_imp=VALUES(total_con_imp)
        """, (factura_id, row["id"], datos["plan"], datos["importe_bruto"],
              datos["bonificacion"], datos["neto_sin_imp"], iva, total_con_imp))

        print(f"    ✓ {numero} — neto: ${datos['neto_sin_imp']:>10} | total: ${total_con_imp:>10}")

    conn.commit()

    # Generar cobros por pagador
    print(f"\n  💰 Generando cobros por pagador...")
    cur.execute("""
        SELECT l.pagador, l.cobra_iva,
               SUM(d.neto_sin_imp)       AS neto,
               SUM(d.iva_y_percepciones) AS iva,
               SUM(d.total_con_imp)      AS total
        FROM detalle_lineas d
        JOIN lineas l ON l.id = d.linea_id
        WHERE d.factura_id = %s
        GROUP BY l.pagador, l.cobra_iva
    """, (factura_id,))

    for p in cur.fetchall():
        monto_total = p["total"] if p["cobra_iva"] else p["neto"]
        monto_iva   = p["iva"]   if p["cobra_iva"] else Decimal("0")
        cur.execute("""
            INSERT INTO cobros (factura_id, pagador, monto_neto, monto_iva, monto_total)
            VALUES (%s,%s,%s,%s,%s)
            ON DUPLICATE KEY UPDATE
                monto_neto=VALUES(monto_neto),
                monto_iva=VALUES(monto_iva),
                monto_total=VALUES(monto_total)
        """, (factura_id, p["pagador"], p["neto"], monto_iva, monto_total))
        print(f"    ✓ {p['pagador']:15} → ${monto_total:>10} {'(c/IVA)' if p['cobra_iva'] else '(s/IVA)'}")

    conn.commit()
    print(f"\n  ✅ Período {periodo} importado correctamente.")

# ─── MAIN ─────────────────────────────────────────────────────
def main():
    if not os.path.isdir(CARPETA_BASE):
        print(f"❌ No se encontró: {CARPETA_BASE}")
        sys.exit(1)

    print(f"📂 Carpeta base: {CARPETA_BASE}")
    print(f"🗄️  Base de datos: {DB_NAME} en {DB_HOST}")

    try:
        conn = conectar()
        print("✅ Conexión a MySQL exitosa\n")
    except Exception as e:
        print(f"❌ Error de conexión: {e}")
        sys.exit(1)

    periodos = buscar_periodos(CARPETA_BASE)

    if not periodos:
        print("⚠️  No se encontraron períodos.")
        print("   Estructura esperada: 'Facturas 2026/Movistar/01/'")
        sys.exit(0)

    print(f"📅 Períodos encontrados: {[p[0] for p in periodos]}")

    for periodo, carpeta in periodos:
        try:
            procesar_periodo(conn, carpeta, periodo)
        except Exception as e:
            print(f"\n❌ Error en {periodo}: {e}")
            import traceback; traceback.print_exc()

    conn.close()
    print(f"\n{'='*55}")
    print("  🎉 Importación finalizada")
    print(f"{'='*55}\n")

if __name__ == "__main__":
    main()