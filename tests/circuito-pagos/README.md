# Suite del circuito de pagos

Scripts de `tinker` que prueban el circuito contra **datos reales** de una base, cada uno dentro de
una transacción con rollback: no dejan nada escrito. No son PHPUnit.

```bash
bash tests/circuito-pagos/correr-suite.sh alba3 osv2
```

Al final de cada base imprime `TOTAL: N OK / M FALLAS` y la lista de lo que hay que revisar.

- `fixture_aplicables.php`: busca un prestador con facturas aplicables de una sola razón social.
- `borrar_opas_prueba.php`: borra OPAs de prueba con todo lo que cuelga (no es un test; se usa a
  mano, con `$opas = [...]`).

Se movieron acá el 2026-10-06 desde una carpeta temporal que Windows limpió. Los tests se
reconstruyeron desde el historial de trabajo; los que quedaron obsoletos se señalan al correr.

## Estado (2026-10-06)

48 tests activos: **alba3 341 OK / 0 fallas, osv2 300 OK / 0 fallas**. Los que no pudieron
recuperarse están en `obsoletos/` con el motivo.

En osv2 hay saltos esperables por los datos de esa base (no son fallas):

- `asiento_instrumento_diferido`, `reasiento_al_editar`: OSV no tiene cuentas de diferidos
  cargadas todavía.
- `filtro_echeq` y parte de `razones_multiples_opa`: OSV tiene una sola razón social.
- `generar_opa`, `editar_cronograma`: el test toma datos que no sirven en OSV (facturas de dos
  prestadores, una orden ya pagada) y la guarda los rechaza. Pendiente: que el test elija mejor.

Regla del proyecto: **todo test de un camino de plata incluye el caso "otra razón social → corta"**.
