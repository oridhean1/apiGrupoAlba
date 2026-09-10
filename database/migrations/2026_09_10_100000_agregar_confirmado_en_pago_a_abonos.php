<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `tb_tes_pago_parcial.fecha_confirmado_en_pago`: cuándo este abono entró en un pago confirmado.
 *
 * ═══ Por qué hace falta una columna ═══
 *
 * Acreditar un eCheq exige que el pago esté confirmado — ahí se genera el asiento contable y se
 * descuenta el saldo de la cuenta. Esa guarda se puso el 2026-09-10 mirando
 * `tb_tes_pago.fecha_confirma_pago`, o sea a nivel BOLETA. Y eso tiene un agujero:
 *
 *   1. Se paga una transferencia y se confirma el pago  -> la boleta queda confirmada.
 *   2. Después se emite un eCheq NUEVO sobre esa misma boleta.
 *   3. Ese eCheq hereda el permiso de la boleta y se puede acreditar **sin haber pasado nunca por
 *      Confirmar Pago**, salteándose la validación de que los montos cubran la orden.
 *
 * Reportado sobre la OPA-1120: se anuló un eCheq de $1.000.000 y se emitió uno de $500 que no
 * cubre nada, y el sistema iba a dejar acreditarlo igual.
 *
 * No alcanza con comparar fechas: `fecha_registra` del abono y `fecha_confirma_pago` de la boleta
 * son `date` (día), y en el caso real las dos cosas pasaron el mismo día. No hay forma de saber si
 * el abono existía cuando se confirmó. Por eso se marca explícitamente.
 *
 * ═══ Backfill ═══
 *
 * Se marcan los abonos que evidentemente ya pasaron por un pago: los que tienen
 * `fecha_confirma_pago` propia o están ACREDITADOS. Relevado el 2026-09-10: 299 de 314 en Alba y
 * 98 de 98 en OSV.
 *
 * Los que quedan sin marcar y están EMITIDOS esperando acreditación tienen que volver a pasar por
 * Confirmar Pago: son 4 en Alba (abonos 759 y 761 de la OPA-1409, 832 de la OPA-1334 y 2204 de la
 * OPA-1120) y 0 en OSV. Es deliberado: son justamente los que nunca se cargaron en un pago.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tb_tes_pago_parcial', 'fecha_confirmado_en_pago')) {
            Schema::table('tb_tes_pago_parcial', function ($table) {
                $table->dateTime('fecha_confirmado_en_pago')->nullable()->after('fecha_confirma_pago');
            });
        }

        DB::statement("
            UPDATE tb_tes_pago_parcial pp
            JOIN tb_tes_pago p ON p.id_pago = pp.id_pago
            SET pp.fecha_confirmado_en_pago = COALESCE(
                    pp.fecha_confirma_pago,
                    p.fecha_confirma_pago,
                    pp.fecha_registra
                )
            WHERE pp.fecha_confirmado_en_pago IS NULL
              AND (pp.fecha_confirma_pago IS NOT NULL OR pp.id_estado_instrumento = 4)
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('tb_tes_pago_parcial', 'fecha_confirmado_en_pago')) {
            Schema::table('tb_tes_pago_parcial', function ($table) {
                $table->dropColumn('fecha_confirmado_en_pago');
            });
        }
    }
};
