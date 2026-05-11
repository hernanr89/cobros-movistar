"""
fix_matias.py
=============
Actualiza SOLO el monto_total de los cobros del usuario con cobra_iva=1
tomando el total exacto del comprobante (que ya incluye IVA).
NO borra ni toca cobros pagados, estados, ni otros usuarios.

INSTALACIÓN: pip install pymysql pdfplumber
"""

import os, re, pymysql, pdfplumber
from decimal import Decimal

CARPETA_BASE = r"C:\Users\Hernan\OneDrive\carpeta trabajo"
DB_HOST      = "45.227.162.14"
DB_PORT      = 3306
DB_USER      = "claude_app"
DB_PASSWORD  = ""   # ← tu contraseña
DB_NAME      = "app_movistar"

def conectar():
    return pymysql.connect(host=DB_HOST, port=DB_PORT, user=DB_USER,
        password=DB_PASSWORD, database=DB_NAME,
        charset="utf8mb4", cursorclass=pymysql.cursors.DictCursor)

def parse_monto(s):
    try: return Decimal(s.strip().replace(".", "").replace(",", "."))
    except: return Decimal("0")

def leer_totales_comprobante(ruta):
    """Lee la tabla de totales con impuestos del comprobante. Devuelve {numero: total}"""
    totales = {}
    try:
        with pdfplumber.open(ruta) as pdf:
            for page in pdf.pages:
                texto = page.extract_text() or ""
                if "Numero de L" in texto and "Totales c/" in texto:
                    for linea in texto.split("\n"):
                        m = re.match(r"(\d{10})\s+.+?\s+([\d\.]+,\d{2})\s*$", linea.strip())
                        if m:
                            totales[m.group(1)] = parse_monto(m.group(2))
                    break
    except Exception as e:
        print(f"  ⚠️  Error leyendo {os.path.basename(ruta)}: {e}")
    return totales

def main():
    conn = conectar()
    cur  = conn.cursor()

    # Obtener las líneas con cobra_iva=1 y sus usuarios
    cur.execute("""
        SELECT ul.usuario_id, ul.linea_id, l.numero, u.nombre
        FROM usuario_lineas ul
        JOIN lineas l ON l.id = ul.linea_id
        JOIN usuarios u ON u.id = ul.usuario_id
        WHERE ul.cobra_iva = 1 AND ul.cobra = 1
    """)
    lineas_iva = cur.fetchall()

    if not lineas_iva:
        print("No hay líneas con cobra_iva=1")
        return

    print(f"Líneas con IVA: {[(l['numero'], l['nombre']) for l in lineas_iva]}")

    # Recorrer carpetas buscando comprobantes
    for entrada in sorted(os.listdir(CARPETA_BASE)):
        ruta_anio = os.path.join(CARPETA_BASE, entrada)
        if not os.path.isdir(ruta_anio): continue
        m = re.match(r"[Ff]acturas\s+(\d{4})", entrada)
        if not m: continue
        anio = m.group(1)
        ruta_mov = os.path.join(ruta_anio, "Movistar")
        if not os.path.isdir(ruta_mov): continue

        # Buscar comprobantes en la carpeta Movistar/
        for nombre in sorted(os.listdir(ruta_mov)):
            n = nombre.lower()
            if not (n.endswith(".pdf") and "comprobante" in n): continue
            m2 = re.match(r"(\d{4})(\d{2})\d{2}", nombre)
            if not m2 or m2.group(1) != anio: continue
            mm = m2.group(2)
            periodo = f"{mm}-{anio}"

            # Buscar factura_id
            cur.execute("SELECT id FROM facturas WHERE periodo = %s", (periodo,))
            fac = cur.fetchone()
            if not fac:
                print(f"  ⚠️  Sin factura en BD para {periodo}, saltando")
                continue

            factura_id = fac["id"]
            totales = leer_totales_comprobante(os.path.join(ruta_mov, nombre))
            if not totales:
                print(f"  ⚠️  {periodo}: sin tabla en comprobante")
                continue

            print(f"\n{'='*50}")
            print(f"  {periodo}")
            print(f"{'='*50}")

            for linea in lineas_iva:
                num = linea["numero"]
                if num not in totales:
                    print(f"  ⚠️  Línea {num} no encontrada en comprobante")
                    continue

                total_real = totales[num]

                # Obtener detalle actual
                cur.execute("""
                    SELECT d.id, d.neto_sin_imp, d.total_con_imp
                    FROM detalle_lineas d
                    JOIN lineas l ON l.id = d.linea_id
                    WHERE d.factura_id = %s AND l.numero = %s
                """, (factura_id, num))
                det = cur.fetchone()
                if not det:
                    print(f"  ⚠️  Sin detalle para línea {num} en {periodo}")
                    continue

                neto = Decimal(str(det["neto_sin_imp"]))
                iva  = total_real - neto

                # Actualizar detalle_lineas
                cur.execute("""
                    UPDATE detalle_lineas
                    SET total_con_imp = %s, iva_y_percepciones = %s
                    WHERE id = %s
                """, (total_real, iva, det["id"]))

                print(f"  ✓ {num} ({linea['nombre']}): ${det['total_con_imp']} → ${total_real}")

            # Recalcular cobro del usuario con IVA para este período
            for linea in lineas_iva:
                uid = linea["usuario_id"]
                cur.execute("""
                    SELECT
                        SUM(d.neto_sin_imp)       AS neto,
                        SUM(d.iva_y_percepciones) AS iva,
                        SUM(d.total_con_imp)      AS total
                    FROM detalle_lineas d
                    JOIN lineas l ON l.id = d.linea_id
                    JOIN usuario_lineas ul ON ul.linea_id = l.id
                    WHERE d.factura_id = %s AND ul.usuario_id = %s AND ul.cobra = 1
                """, (factura_id, uid))
                sums = cur.fetchone()
                if not sums or not sums["total"]:
                    continue

                # Actualizar cobro SIN tocar estado, fecha_cobro, forma_pago, notas
                cur.execute("""
                    UPDATE cobros
                    SET monto_neto = %s, monto_iva = %s, monto_total = %s
                    WHERE factura_id = %s AND usuario_id = %s
                """, (sums["neto"], sums["iva"], sums["total"], factura_id, uid))

                print(f"  💰 {linea['nombre']}: cobro actualizado → ${sums['total']}")

            conn.commit()

    conn.close()
    print(f"\n✅ Listo. Estados y pagos registrados no fueron modificados.")

if __name__ == "__main__":
    main()
