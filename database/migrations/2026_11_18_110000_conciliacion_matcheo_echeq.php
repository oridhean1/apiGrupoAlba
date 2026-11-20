<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-00000689 E4: la sugerencia de conciliación puede apuntar a un eCheq emitido todavía sin
 * acreditar (no tiene movimiento bancario). Al confirmarla se acredita y se completa
 * id_movimiento_interno con el débito que deja. INT con signo como tb_tes_pago_parcial.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tb_tes_conciliacion_matcheo', 'id_pago_parcial')) {
            DB::statement('ALTER TABLE tb_tes_conciliacion_matcheo
                ADD COLUMN id_pago_parcial INT NULL DEFAULT NULL AFTER id_movimiento_interno,
                ADD INDEX idx_matcheo_pago_parcial (id_pago_parcial)');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tb_tes_conciliacion_matcheo', 'id_pago_parcial')) {
            DB::statement('ALTER TABLE tb_tes_conciliacion_matcheo
                DROP INDEX idx_matcheo_pago_parcial, DROP COLUMN id_pago_parcial');
        }
    }
};
