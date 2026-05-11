"""
importar_pdfs.py v11
=====================
- Neto: leído del anexo individual (Total TELEFONIA MOVIL + Plan Movistar + Adicionales)
- Total con IVA: para líneas cobra_iva=1, tomado directo de la tabla del Comprobante
  (que ya tiene el total exacto con impuestos por línea).
  Para el resto: neto × 1.27 (solo IVA 27%)
- El Comprobante se busca en la carpeta padre Movistar/ con fecha YYYYMM

INSTALACIÓN: pip install pymysql pdfplumber
"""

import os, re, sys, pymysql, pdfplumber
from datetime import datetime
from decimal import Decimal, ROUND_HALF_UP

CARPETA_BASE = r"C:\Users\Hernan\OneDrive\carpeta trabajo"
DB_HOST      = "45.227.162.14"
DB_PORT      = 3306
DB_USER      = "claude_app"
DB_PASSWORD  = ""   # ← tu contraseña
DB_NAME      = "app_movistar"
IVA_FACTOR   = Decimal("1.27")  # solo IVA 27%

def conectar():
    return pymysql.connect(host=DB_HOST, port=DB_PORT, user=DB_USER,
        password=DB_PASSWORD, database=DB_NAME,
        charset="utf8mb4", cursorclass=pymysql.cursors.DictCursor)

def buscar_periodos(base):
    periodos = []
    for entrada in os.listdir(base):
        ruta_anio = os.path.join(base, entrada)
        if not os.path.isdir(ruta_anio): continue
        m = re.match(r"[Ff]acturas\s+(\d{4})", entrada)
        if not m: continue
        anio = m.group(1)
        ruta_mov = os.path.join(ruta_anio, "Movistar")
        if not os.path.isdir(ruta_mov): continue
        for mes_str in os.listdir(ruta_mov):
            ruta_mes = os.path.join(ruta_mov, mes_str)
            if os.path.isdir(ruta_mes) and re.match(r"^\d{1,2}$", mes_str):
                periodos.append((f"{mes_str.zfill(2)}-{anio}", ruta_mes, ruta_mov))
    periodos.sort(key=lambda x: (x[0][3:], x[0][:2]))
    return periodos

def parse_monto(s):
    try: return Decimal(s.strip().replace(".", "").replace(",", "."))
    except: return Decimal("0")

def parse_fecha(s):
    try: return datetime.strptime(s.strip(), "%d/%m/%Y").date()
    except: return None

def numero_linea_de_nombre(nombre):
    m = re.search(r"(\d{10})", nombre)
    return m.group(1) if m else None

def es_anexo(nombre):
    return nombre.lower().endswith(".pdf") and "anexo" in nombre.lower()

def es_comprobante(nombre):
    n = nombre.lower()
    return n.endswith(".pdf") and "comprobante" in n and "anexo" not in n

def parsear_anexo(ruta):
    """Lee el neto sin impuestos del anexo individual."""
    resultado = {"plan": "Control Empresas 2 GB F",
                 "neto_sin_imp": Decimal("0"),
                 "tel_movil": Decimal("0"),
                 "plan_movil": Decimal("0"),
                 "adicionales": Decimal("0")}
    try:
        with pdfplumber.open(ruta) as pdf:
            texto = "\n".join(p.extract_text() or "" for p in pdf.pages)
    except Exception as e:
        print(f"    ⚠️  Error leyendo {os.path.basename(ruta)}: {e}")
        return resultado
    m = re.search(r"((?:Control|Comunidad)\s+Empresas\s+[\w\s]+GB\s*\w*)", texto, re.IGNORECASE)
    if m: resultado["plan"] = re.sub(r"\s+", " ", m.group(1)).strip()
    m = re.search(r"Total TELEFONIA MOVIL\s+([\d\.]+,\d{2})", texto)
    tel = parse_monto(m.group(1)) if m else Decimal("0")
    resultado["tel_movil"] = tel
    m = re.search(r"Total Plan Movistar\s+([-\d\.]+,\d{2})", texto)
    plan = parse_monto(m.group(1)) if m else Decimal("0")
    resultado["plan_movil"] = plan
    m = re.search(r"Total Adicionales Movistar TV\s+([\d\.]+,\d{2})", texto)
    adic = parse_monto(m.group(1)) if m else Decimal("0")
    resultado["adicionales"] = adic
    resultado["neto_sin_imp"] = tel + plan + adic
    return resultado

def leer_comprobante(ruta_mov, periodo):
    """
    Busca el Comprobante en la carpeta Movistar/ que corresponda al período.
    Retorna dict { numero_linea: total_con_impuestos } o {} si no se encuentra.
    El comprobante tiene nombre tipo 20251204_Comprobante.pdf
    y contiene una tabla con totales por línea incluyendo IVA.
    """
    mm, yy = periodo.split("-")
    totales = {}

    # Buscar archivo comprobante que coincida con el año y mes del período
    for nombre in sorted(os.listdir(ruta_mov)):
        if not es_comprobante(nombre): continue
        # El nombre tiene YYYYMMDD — verificar que corresponde al mes
        m = re.match(r"(\d{4})(\d{2})\d{2}", nombre)
        if not m: continue
        if m.group(1) == yy and m.group(2) == mm:
            ruta = os.path.join(ruta_mov, nombre)
            try:
                with pdfplumber.open(ruta) as pdf:
                    for page in pdf.pages:
                        texto = page.extract_text() or ""
                        if "Numero de L" in texto and "Totales c/" in texto:
                            for linea in texto.split("\n"):
                                mx = re.match(r"(\d{10})\s+.+?\s+([\d\.]+,\d{2})\s*$", linea.strip())
                                if mx:
                                    num = mx.group(1)
                                    tot = parse_monto(mx.group(2))
                                    if tot > 0:
                                        totales[num] = tot
                            break
                print(f"  📋 Comprobante: {nombre} ({len(totales)} líneas con totales)")
            except Exception as e:
                print(f"  ⚠️  Error leyendo comprobante {nombre}: {e}")
            break

    if not totales:
        print(f"  ⚠️  Sin comprobante para {periodo} — se usará neto × {IVA_FACTOR} para c/IVA")
    return totales

def leer_datos_generales(ruta_mov, periodo):
    """Lee fecha y total de factura del comprobante."""
    mm, yy = periodo.split("-")
    datos = {"numero_factura": None, "fecha_emision": None,
             "fecha_vencimiento": None, "total_factura": Decimal("0")}
    for nombre in sorted(os.listdir(ruta_mov)):
        if not es_comprobante(nombre): continue
        m = re.match(r"(\d{4})(\d{2})\d{2}", nombre)
        if not m or m.group(1) != yy or m.group(2) != mm: continue
        try:
            with pdfplumber.open(os.path.join(ruta_mov, nombre)) as pdf:
                texto = "\n".join(p.extract_text() or "" for p in pdf.pages)
            m2 = re.search(r"Factura\s+([\w\-]+)", texto)
            if m2: datos["numero_factura"] = m2.group(1)
            m2 = re.search(r"Fecha de emisi[oó]n[:\s]*(\d{2}/\d{2}/\d{4})", texto)
            if m2: datos["fecha_emision"] = parse_fecha(m2.group(1))
            m2 = re.search(r"Vencimiento[:\s]*(\d{2}/\d{2}/\d{4})", texto)
            if m2: datos["fecha_vencimiento"] = parse_fecha(m2.group(1))
            m2 = re.search(r"Total a Pagar[:\s]*\$\s*([\d\.,]+)", texto)
            if m2: datos["total_factura"] = parse_monto(m2.group(1))
        except: pass
        break
    return datos

def procesar_periodo(conn, carpeta, periodo, ruta_mov):
    print(f"\n{'='*60}")
    print(f"  Período: {periodo}")
    print(f"{'='*60}")
    cur = conn.cursor()

    cur.execute("""
        INSERT INTO facturas (periodo, cuenta_cliente)
        VALUES (%s, '91514949')
        ON DUPLICATE KEY UPDATE periodo = periodo
    """, (periodo,))
    conn.commit()
    cur.execute("SELECT id FROM facturas WHERE periodo = %s", (periodo,))
    factura_id = cur.fetchone()["id"]

    # Leer datos generales del comprobante (carpeta padre)
    datos_gral = leer_datos_generales(ruta_mov, periodo)
    if datos_gral["total_factura"] > 0:
        cur.execute("""
            UPDATE facturas SET total_factura=%s, fecha_emision=%s,
                fecha_vencimiento=%s, numero_factura=%s
            WHERE id=%s AND (total_factura=0 OR total_factura IS NULL)
        """, (datos_gral["total_factura"], datos_gral["fecha_emision"],
              datos_gral["fecha_vencimiento"], datos_gral["numero_factura"], factura_id))
        conn.commit()

    # Leer tabla de totales con IVA del comprobante
    totales_comprobante = leer_comprobante(ruta_mov, periodo)

    # Procesar anexos
    anexos = sorted([f for f in os.listdir(carpeta) if es_anexo(f)])
    print(f"  📁 Anexos: {len(anexos)}")
    if not anexos:
        print("  ⚠️  No hay archivos con 'Anexo' en el nombre.")
        return

    ok = 0
    for nombre in anexos:
        num = numero_linea_de_nombre(nombre)
        if not num: continue
        cur.execute("SELECT id FROM lineas WHERE numero = %s", (num,))
        row = cur.fetchone()
        if not row:
            print(f"    ⚠️  Línea {num} no en BD")
            continue

        # Obtener usuario y si cobra IVA
        cur.execute("""
            SELECT ul.usuario_id, ul.cobra, ul.cobra_iva
            FROM usuario_lineas ul WHERE ul.linea_id = %s
        """, (row["id"],))
        ul = cur.fetchone()
        usuario_id = ul["usuario_id"] if ul else None
        cobra_iva  = ul["cobra_iva"] if ul else 0

        datos = parsear_anexo(os.path.join(carpeta, nombre))
        neto  = datos["neto_sin_imp"]
        if neto <= 0:
            print(f"    ⚠️  {num}: neto=$0")
            continue

        # Total con IVA:
        # - Si cobra_iva=1 y está en el comprobante: usar ese valor exacto
        # - Si cobra_iva=1 pero no está en comprobante: neto × 1.27
        # - Si cobra_iva=0: neto (sin IVA)
        if cobra_iva and num in totales_comprobante:
            total_con_imp = totales_comprobante[num]
        elif cobra_iva:
            total_con_imp = (neto * IVA_FACTOR).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
        else:
            total_con_imp = neto

        iva = total_con_imp - neto

        cur.execute("""
            INSERT INTO detalle_lineas
                (factura_id, linea_id, usuario_id, plan, importe_bruto, bonificacion,
                 neto_sin_imp, iva_y_percepciones, total_con_imp)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)
            ON DUPLICATE KEY UPDATE
                plan=VALUES(plan), importe_bruto=VALUES(importe_bruto),
                bonificacion=VALUES(bonificacion), neto_sin_imp=VALUES(neto_sin_imp),
                iva_y_percepciones=VALUES(iva_y_percepciones),
                total_con_imp=VALUES(total_con_imp), usuario_id=VALUES(usuario_id)
        """, (factura_id, row["id"], usuario_id, datos["plan"],
              datos["tel_movil"], abs(datos["plan_movil"]),
              neto, iva, total_con_imp))

        src = "comprobante" if (cobra_iva and num in totales_comprobante) else ("×1.27" if cobra_iva else "neto")
        extras = f" [+adic ${datos['adicionales']:,.2f}]" if datos["adicionales"] > 0 else ""
        print(f"    ✓ {num}  neto: ${neto:>10,.2f}  total: ${total_con_imp:>10,.2f}  [{src}]{extras}")
        ok += 1

    conn.commit()
    print(f"  ✓ {ok}/{len(anexos)} líneas procesadas")

    # Recalcular cobros por usuario
    print(f"  💰 Recalculando cobros por usuario...")
    cur.execute("""
        SELECT ul.usuario_id, ul.cobra_iva,
               u.nombre,
               SUM(CASE WHEN ul.cobra=1 THEN d.neto_sin_imp ELSE 0 END)       AS neto,
               SUM(CASE WHEN ul.cobra=1 THEN d.iva_y_percepciones ELSE 0 END) AS iva,
               SUM(CASE WHEN ul.cobra=1 THEN d.total_con_imp ELSE 0 END)      AS total
        FROM detalle_lineas d
        JOIN lineas l ON l.id = d.linea_id
        JOIN usuario_lineas ul ON ul.linea_id = l.id
        JOIN usuarios u ON u.id = ul.usuario_id
        WHERE d.factura_id = %s
        GROUP BY ul.usuario_id, ul.cobra_iva, u.nombre
    """, (factura_id,))

    for p in cur.fetchall():
        monto_total = p["total"] if p["cobra_iva"] else p["neto"]
        monto_iva   = p["iva"]   if p["cobra_iva"] else Decimal("0")
        cur.execute("""
            INSERT INTO cobros (factura_id, usuario_id, pagador, monto_neto, monto_iva, monto_total)
            VALUES (%s,%s,%s,%s,%s,%s)
            ON DUPLICATE KEY UPDATE
                monto_neto=VALUES(monto_neto), monto_iva=VALUES(monto_iva),
                monto_total=VALUES(monto_total)
        """, (factura_id, p["usuario_id"], p["nombre"],
              p["neto"], monto_iva, monto_total))
        print(f"    ✓ {p['nombre']:20} → ${monto_total:>10,.2f} {'(c/IVA)' if p['cobra_iva'] else '(s/IVA)'}")

    conn.commit()
    print(f"  ✅ Período {periodo} listo.")

def main():
    if not os.path.isdir(CARPETA_BASE):
        print(f"❌ No se encontró: {CARPETA_BASE}"); sys.exit(1)
    print(f"📂 Carpeta base : {CARPETA_BASE}")
    print(f"🗄️  Base de datos: {DB_NAME} en {DB_HOST}")
    try:
        conn = conectar()
        print("✅ Conexión MySQL exitosa\n")
    except Exception as e:
        print(f"❌ Error de conexión: {e}"); sys.exit(1)
    periodos = buscar_periodos(CARPETA_BASE)
    if not periodos:
        print("⚠️  No se encontraron períodos."); sys.exit(0)
    print(f"📅 Períodos: {[p[0] for p in periodos]}\n")
    for periodo, carpeta, ruta_mov in periodos:
        try:
            procesar_periodo(conn, carpeta, periodo, ruta_mov)
        except Exception as e:
            print(f"\n❌ Error en {periodo}: {e}")
            import traceback; traceback.print_exc()
    conn.close()
    print(f"\n{'='*60}\n  🎉 Importación finalizada\n{'='*60}\n")

if __name__ == "__main__":
    main()
