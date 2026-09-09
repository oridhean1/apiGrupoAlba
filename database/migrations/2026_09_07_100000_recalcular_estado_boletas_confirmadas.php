<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recalcula `tb_tes_pago.id_estado_orden_pago` para boletas marcadas PAGADO(5) que en realidad
 * quedaron parciales.
 *
 * ═══ El bug ═══
 *
 * `TesPagosRepository::findByConfirmarPago()` calculaba `$estado` (6 = PARCIAL, 5 = PAGADO)
 * comparando lo pagado contra lo pagable, pero **nunca lo usaba**: dos líneas más abajo
 * `$pago->id_estado_orden_pago = 5` quedaba hardcodeado, sin importar cuánto se hubiera pagado
 * realmente.
 *
 * Por eso el modal de Confirmar Pago exigía cargar EXACTAMENTE un abono por cada fecha
 * planificada antes de dejar confirmar — no era una regla de negocio, era la única forma de que
 * ese 5 hardcodeado no mintiera. Reportado el 2026-09-07 al preguntar por qué no se podían hacer
 * pagos parciales.
 *
 * **Es visible para el usuario**: la grilla de Pagos de Prestador/Proveedor muestra
 * `tb_tes_pago.id_estado_orden_pago` directamente como el badge de Estado de cada fila.
 *
 * ═══ Qué hace esta migración ═══
 *
 * Recalcula, para cada boleta en estado 5, cuánto se pagó realmente (abonos vivos, sin contar
 * rechazados ni anulados) contra cuánto era pagable (imputado menos débito de liquidación, mismo
 * criterio que `montoPagableOpa()`). Si lo pagado no alcanza, la pasa a 6 (PARCIAL).
 *
 * Requiere MariaDB 10.2+ / MySQL 8+ por la window function (verificado sobre MariaDB 11.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tb_tes_pago_estado_bkp_20260907')) {
            DB::statement('
                CREATE TABLE tb_tes_pago_estado_bkp_20260907 AS
                SELECT id_pago, id_orden_pago, id_estado_orden_pago AS estado_anterior, monto_opa
                FROM tb_tes_pago
                WHERE id_estado_orden_pago = 5
            ');
        }

        DB::statement("
            UPDATE tb_tes_pago p
            JOIN (
                SELECT
                    p2.id_pago,
                    COALESCE(pag.pagable, p2.monto_opa) AS pagable,
                    COALESCE(ab.pagado, 0) AS pagado
                FROM tb_tes_pago p2
                LEFT JOIN (
                    SELECT
                        pf.id_orden_pago,
                        SUM(LEAST(
                            pf.monto_aplicado,
                            GREATEST(0, f.total_neto - COALESCE(f.total_debitado_liquidacion, 0))
                        )) AS pagable
                    FROM tb_tes_opa_factura pf
                    JOIN tb_facturacion_datos f ON f.id_factura = pf.id_factura
                    GROUP BY pf.id_orden_pago
                ) pag ON pag.id_orden_pago = p2.id_orden_pago
                LEFT JOIN (
                    SELECT pp.id_pago, SUM(pp.monto_pago) AS pagado
                    FROM tb_tes_pago_parcial pp
                    WHERE pp.id_estado_instrumento IS NULL
                       OR pp.id_estado_instrumento NOT IN (5, 6)
                    GROUP BY pp.id_pago
                ) ab ON ab.id_pago = p2.id_pago
                WHERE p2.id_estado_orden_pago = 5
            ) calc ON calc.id_pago = p.id_pago
            SET p.id_estado_orden_pago = 6
            WHERE p.id_estado_orden_pago = 5
              AND calc.pagable > 0
              AND calc.pagado < calc.pagable - 0.5
              AND calc.pagado > 0
        ");
    }

    public function down(): void
    {
        // No se revierte con una formula: la tabla de respaldo tiene el estado anterior de cada
        // boleta tocada. Si hiciera falta, restaurar a mano desde tb_tes_pago_estado_bkp_20260907.
    }
};
