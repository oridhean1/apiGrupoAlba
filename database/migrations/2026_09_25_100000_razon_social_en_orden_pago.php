<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Razón social propia de la orden de pago.
 *
 * Hasta ahora la razón social de una OPA se DERIVABA de sus facturas (`id_locatorio`). Una orden
 * sin facturas —un ANTICIPO— no tenía de dónde sacarla, y `validarCuentaDeRazonSocial()` se
 * apagaba sola: un anticipo se podía pagar desde la cuenta bancaria de cualquiera de las razones
 * sociales del grupo. Verificado el 2026-09-25 contra las 3 razones de Alba.
 *
 * La columna es el lugar donde la orden guarda su razón cuando no puede derivarla. Se llena al
 * crear el anticipo; para las órdenes con facturas sigue mandando lo que dicen las facturas.
 *
 * NULL a propósito: las 4192 órdenes existentes de Alba y las 4643 de OSV derivan bien su razón de
 * las facturas y no hay que tocarlas.
 *
 * `int(11)` con signo, como el resto de las FK contra tablas viejas (ver CLAUDE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tb_tes_orden_pago', 'id_razon')) {
            return;
        }

        Schema::table('tb_tes_orden_pago', function (Blueprint $table) {
            $table->integer('id_razon')->nullable()->after('tipo_opa');
            $table->index('id_razon', 'idx_opa_razon');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('tb_tes_orden_pago', 'id_razon')) {
            return;
        }

        Schema::table('tb_tes_orden_pago', function (Blueprint $table) {
            $table->dropIndex('idx_opa_razon');
            $table->dropColumn('id_razon');
        });
    }
};
