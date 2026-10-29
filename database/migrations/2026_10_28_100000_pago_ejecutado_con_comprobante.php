<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El pago se EJECUTA al subir el comprobante (R-00000439, 2026-10-28).
 *
 * Hasta acá el débito y el asiento se generaban al confirmar el pago, que en la práctica es cuando
 * Pagos lo PLANIFICA: todavía puede cambiar de forma o de cuenta. Desde ahora cargar un pago no
 * mueve plata ni contabilidad; lo hace subir su comprobante, con la fecha real del pago.
 *
 * - `tb_tes_pago_parcial.fecha_ejecucion`: fecha real en que salió el pago. NULL = cargado pero
 *   no ejecutado (IMPAGO). Es lo que cuenta como pagado para el estado de la orden.
 * - Estado 8 IMPAGO: pagos cargados sin comprobante.
 * - Backfill: los pagos ya asentados con el circuito anterior que SÍ tienen comprobante quedan
 *   ejecutados. Los que no lo tienen los revierte el comando de la fase 3.
 *
 * Ver docs/circuito-pagos/plan-pago-ejecutado-con-comprobante.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tb_tes_pago_parcial', 'fecha_ejecucion')) {
            Schema::table('tb_tes_pago_parcial', function (Blueprint $table) {
                $table->date('fecha_ejecucion')->nullable()->after('fecha_confirmado_en_pago');
            });
        }

        DB::statement("
            INSERT INTO tb_tes_estado_orden_pago (id_estado_orden_pago, descripcion_estado, name_class, vigente)
            SELECT 8, 'IMPAGO', 'badge bg-warning', 1
            FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM tb_tes_estado_orden_pago WHERE id_estado_orden_pago = 8)
        ");

        // Un comprobante sin fecha (los anteriores al 2026-10-23) es de toda la boleta.
        DB::statement("
            UPDATE tb_tes_pago_parcial pp
            JOIN (
                SELECT pp2.id_pago_parcial, DATE(MIN(c.fecha_registra)) AS fecha_comprobante
                FROM tb_tes_pago_parcial pp2
                JOIN tb_test_pago_detalle_comprobantes c
                  ON c.id_pago = pp2.id_pago
                 AND c.estado = '1'
                 AND (c.id_fecha_probable IS NULL OR c.id_fecha_probable = pp2.id_fecha_probable)
                GROUP BY pp2.id_pago_parcial
            ) x ON x.id_pago_parcial = pp.id_pago_parcial
            SET pp.fecha_ejecucion = COALESCE(pp.fecha_confirma_pago, x.fecha_comprobante)
            WHERE pp.fecha_ejecucion IS NULL
              AND (pp.fecha_confirmado_en_pago IS NOT NULL OR pp.fecha_confirma_pago IS NOT NULL)
              AND (pp.id_estado_instrumento IS NULL OR pp.id_estado_instrumento NOT IN (5, 6))
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('tb_tes_pago_parcial', 'fecha_ejecucion')) {
            Schema::table('tb_tes_pago_parcial', function (Blueprint $table) {
                $table->dropColumn('fecha_ejecucion');
            });
        }
    }
};
