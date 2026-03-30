#!/usr/bin/env python3
"""
convert_dataset_to_11.py — Converte tutti i file .sraw in www/data
da formato SRAW-1 (legacy) a SRAW-1.1 (costanti incorporate nell'header).

I valori interi restano identici. Viene solo aggiunto l'header con le
costanti di risoluzione legacy (10 µV) per retrocompatibilità perfetta.

Uso:
    python convert_dataset_to_11.py [--dry-run]
"""

import sys
import os
from pathlib import Path

DATA_DIR = Path(__file__).parent / "www" / "data"

# Costanti legacy SRAW-1 da incorporare
LEGACY_HEADER_LINES = [
    "time_res,0.0000125",
    "freq_res,0.01",
    "amp_time_res,0.00001",
    "amp_freq_res,0.00001",
]


def convert_file(filepath: Path, dry_run=False):
    """Converte un singolo file da SRAW-1 a SRAW-1.1."""
    with open(filepath, "r", encoding="utf-8") as f:
        content = f.read()

    lines = content.split("\n")

    # Controlla che sia SRAW-1
    if not lines or lines[0].strip() != "SRAW-1":
        return "SKIP (not SRAW-1)"

    # Controlla se già convertito (ha già time_res)
    for line in lines[1:20]:  # guarda solo le prime righe
        if line.strip().startswith("time_res,"):
            return "SKIP (already 1.1)"

    # Trova la posizione di "data" per inserire le costanti prima
    new_lines = [lines[0]]  # "SRAW-1"
    data_found = False
    inserted = False

    for line in lines[1:]:
        stripped = line.strip()

        # Inserisci le costanti subito prima di "data"
        if stripped == "data" and not inserted:
            for h in LEGACY_HEADER_LINES:
                new_lines.append(h)
            inserted = True

        new_lines.append(line)

        if stripped == "data":
            data_found = True

    if not data_found:
        return "SKIP (no data section)"

    new_content = "\n".join(new_lines)

    if dry_run:
        return "WOULD CONVERT"

    with open(filepath, "w", encoding="utf-8") as f:
        f.write(new_content)

    return "CONVERTED"


def main():
    dry_run = "--dry-run" in sys.argv

    if dry_run:
        print("=== DRY RUN (nessun file modificato) ===\n")

    sraw_files = sorted(DATA_DIR.rglob("*.sraw"))
    print(f"Trovati {len(sraw_files)} file .sraw in {DATA_DIR}\n")

    stats = {"CONVERTED": 0, "SKIP (already 1.1)": 0,
             "SKIP (not SRAW-1)": 0, "SKIP (no data section)": 0,
             "WOULD CONVERT": 0}

    for fp in sraw_files:
        rel = fp.relative_to(DATA_DIR)
        result = convert_file(fp, dry_run=dry_run)
        print(f"  {result:24s}  {rel}")
        stats[result] = stats.get(result, 0) + 1

    print(f"\n--- Riepilogo ---")
    for k, v in stats.items():
        if v > 0:
            print(f"  {k}: {v}")

    if not dry_run:
        print(f"\nConversione completata!")


if __name__ == "__main__":
    main()
