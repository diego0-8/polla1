#!/usr/bin/env python3
"""Limpia bach1.1.csv: une fecha+hora y quita números en columnas nivel."""

import csv
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INPUT = ROOT / "bach1.1.csv"
OUTPUT = ROOT / "bach1.1.csv"
BACKUP = ROOT / "bach1.1.original.csv"

NIVEL_COLS = ("nivel1-tipo", "nivel2-clasificacion", "nivel3-detalle")


def normalizar_hora(hora: str) -> str:
  hora = hora.replace("\xa0", " ").strip()
  hora = re.sub(r"\s+", " ", hora)
  return hora


def solo_letras(valor: str) -> str:
  """Deja solo letras (incl. acentos) y espacios; sin dígitos ni puntuación numérica."""
  sin_numeros = re.sub(r"[\d.]+", " ", valor)
  solo = re.sub(r"[^a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s]", " ", sin_numeros)
  return re.sub(r"\s+", " ", solo).strip()


def procesar(filas: list[dict]) -> list[dict]:
  salida = []
  for fila in filas:
    fecha = fila["Fecha y Hora"].strip()
    hora = normalizar_hora(fila["hora"])
    nueva = {
      "Fecha y Hora": f"{fecha} {hora}".strip(),
      "asesor": fila["asesor"],
      "NIT CXC": fila["NIT CXC"],
      "nombre": fila["nombre"],
      "nivel1-tipo": solo_letras(fila["nivel1-tipo"]),
      "nivel2-clasificacion": solo_letras(fila["nivel2-clasificacion"]),
      "nivel3-detalle": solo_letras(fila["nivel3-detalle"]),
      "Observaciones": fila["Observaciones"],
      "celular": fila["celular"],
    }
    salida.append(nueva)
  return salida


def main() -> int:
  src = INPUT
  if not src.is_file():
    print(f"No existe: {src}", file=sys.stderr)
    return 1

  with src.open(encoding="latin-1", newline="") as f:
    filas = list(csv.DictReader(f))

  if not BACKUP.exists():
    BACKUP.write_bytes(src.read_bytes())
    print(f"Respaldo: {BACKUP.name}")

  limpias = procesar(filas)
  campos = [
    "Fecha y Hora",
    "asesor",
    "NIT CXC",
    "nombre",
    "nivel1-tipo",
    "nivel2-clasificacion",
    "nivel3-detalle",
    "Observaciones",
    "celular",
  ]

  with OUTPUT.open("w", encoding="utf-8-sig", newline="") as f:
    w = csv.DictWriter(f, fieldnames=campos)
    w.writeheader()
    w.writerows(limpias)

  print(f"Filas procesadas: {len(limpias)}")
  print(f"Columnas: {len(campos)} (se eliminó 'hora')")
  print("Ejemplo fila 1:")
  for k, v in limpias[0].items():
    print(f"  {k}: {v}")
  return 0


if __name__ == "__main__":
  raise SystemExit(main())
