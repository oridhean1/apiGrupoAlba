#!/bin/bash
# Suite del circuito de pagos. Uso, desde cualquier lado:
#   bash tests/circuito-pagos/correr-suite.sh alba3 osv2
#
# Cada test_*.php es un script de tinker que trabaja contra datos REALES de la base que se le pasa,
# dentro de una transacción con rollback: no deja nada escrito.
#
# Cuenta los MARCADORES (">>> OK" / ">>> FALLA") ademas de los resumenes "N OK / M FALLAS",
# porque los scripts no usan todos el mismo formato. Marca en rojo cualquier EXCEPCION, un SKIP
# silencioso o un test que no produjo ningun resultado. (2026-09-16; movido al repo 2026-10-06)
S="$(cd "$(dirname "$0")" && pwd -W)"
cd "$S/../.." || exit 1

for D in "$@"; do
  echo "########## $D ##########"
  TOT_OK=0; TOT_F=0; ROJOS=""
  for f in "$S"/test_*.php; do
    n=$(basename "$f")
    out=$(php artisan tinker --execute="config(['database.connections.mysql.database' => '$D']); DB::purge('mysql'); require '$f';" 2>&1)
    o=$(echo "$out" | grep -cE "^\s*>>>.*( OK|OK *$)")
    x=$(echo "$out" | grep -cE ">>>.*FALLA")
    # Los que no usan marcadores: se cae al resumen final.
    if [ "$o" = "0" ] && [ "$x" = "0" ]; then
      o=$(echo "$out" | grep -oE "[0-9]+ OK" | tail -1 | grep -oE "^[0-9]+"); o=${o:-0}
      x=$(echo "$out" | grep -oE "[0-9]+ FALLAS?" | tail -1 | grep -oE "^[0-9]+"); x=${x:-0}
    fi
    TOT_OK=$((TOT_OK+o)); TOT_F=$((TOT_F+x))
    motivo=""
    [ "$x" != "0" ] && motivo="$x fallas"
    echo "$out" | grep -q "EXCEPCION" && motivo="$motivo excepcion"
    echo "$out" | grep -qi "SE SALTEA\|SKIP" && motivo="$motivo salteado"
    [ "$o" = "0" ] && [ "$x" = "0" ] && [ -z "$motivo" ] && motivo="sin resultados"
    [ -n "$motivo" ] && ROJOS="$ROJOS\n  $(printf '%-46s' "$n") OK=$o -> $motivo"
  done
  echo "TOTAL: $TOT_OK OK / $TOT_F FALLAS"
  [ -n "$ROJOS" ] && echo -e "A REVISAR:$ROJOS" || echo "todo verde"
done
