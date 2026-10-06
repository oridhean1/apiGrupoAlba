<?php

namespace App\Http\Controllers\Tesoreria\Repository;

/**
 * Búsqueda por N° de OPA, con los dos formatos que conviven (2026-10-06):
 *
 *   viejo  OPA-0999, OPA-14358       (hasta el despliegue del circuito de pagos)
 *   nuevo  OPA-20261006-14493         (OPA-AAAAMMDD-consecutivo, pedido del cliente)
 *
 * Lo que tipea el usuario:
 *
 *   "14493", "opa 14493", "OPA-20261006-14493"  -> esa orden (por el consecutivo)
 *   "20261006"                                  -> todas las órdenes de ese día
 *   "1435", "OPA-1435", "OPA-01435"             -> la vieja OPA-1435
 *
 * El consecutivo es el ÚLTIMO grupo de dígitos y se compara NUMÉRICAMENTE: los viejos traen ceros
 * a la izquierda, y con LIKE buscar 1435 traía también OPA-14358.
 *
 * Un grupo de 8 dígitos que es una fecha válida se toma como FECHA: el consecutivo tiene como
 * mucho 7 dígitos (la secuencia llega a 9.999.999), así que no hay ambigüedad.
 */
class NumeroOpa
{
    /**
     * Aplica el filtro por N° de OPA a la query. No hace nada si el texto no trae dígitos.
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     */
    public static function filtrar($query, $texto, string $columna = 'num_orden_pago'): void
    {
        if (!preg_match_all('/\d+/', (string) $texto, $m)) {
            return;
        }

        $grupos = $m[0];

        // Solo la fecha: todas las órdenes de ese día.
        if (count($grupos) === 1 && self::esFecha($grupos[0])) {
            $query->where($columna, 'like', 'OPA-' . $grupos[0] . '-%');
            return;
        }

        $query->whereRaw("CAST(SUBSTRING_INDEX({$columna}, '-', -1) AS UNSIGNED) = ?", [(int) end($grupos)]);
    }

    /** "20261006" -> true. Exige 8 dígitos, una fecha real, y un año razonable. */
    private static function esFecha(string $d): bool
    {
        if (strlen($d) !== 8) {
            return false;
        }

        $anio = (int) substr($d, 0, 4);

        return $anio >= 2020 && $anio <= 2099
            && checkdate((int) substr($d, 4, 2), (int) substr($d, 6, 2), $anio);
    }
}
