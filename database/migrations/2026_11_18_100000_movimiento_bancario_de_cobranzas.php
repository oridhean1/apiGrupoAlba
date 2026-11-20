<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-00000689 E2: los cobros dejan movimiento en la cuenta bancaria (para conciliarlos).
 * El movimiento apunta al medio de cobro (transferencia/depósito al confirmar la orden,
 * cheque/eCheq al depositarlo). Sin FK, igual que id_pago / id_operacion de la misma tabla.
 *
 * El backfill de lo ya cobrado NO va acá: está en docs/conciliacion/sql-e2-movimientos-cobranzas.md
 * (en prod sólo se corre SQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tb_tes_movimiento_cuenta_bancaria', 'id_orden_medio')) {
            DB::statement('ALTER TABLE tb_tes_movimiento_cuenta_bancaria
                ADD COLUMN id_orden_medio INT NULL DEFAULT NULL AFTER id_operacion,
                ADD INDEX idx_mov_orden_medio (id_orden_medio)');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tb_tes_movimiento_cuenta_bancaria', 'id_orden_medio')) {
            DB::statement('ALTER TABLE tb_tes_movimiento_cuenta_bancaria
                DROP INDEX idx_mov_orden_medio, DROP COLUMN id_orden_medio');
        }
    }
};
