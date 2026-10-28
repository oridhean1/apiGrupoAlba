<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comprobante de pago POR FECHA del cronograma.
 *
 * El comprobante se guardaba por boleta (`id_pago`). Desde el 2026-10-23 "Pagos a emitir" lista
 * cada pago hasta que se le adjunta el comprobante, y Pagos adjunta uno por cada fecha: con el
 * comprobante por boleta, adjuntar el de la cuota 1 sacaba del Excel también las cuotas 2 y 3.
 *
 * NULL a propósito: los comprobantes existentes no saben de qué fecha son y siguen cubriendo
 * toda la boleta, que es lo que significaban cuando se subieron.
 *
 * `int(11)` con signo, como `tb_tes_fecha_probable_pago.id_fecha_probable` (ver CLAUDE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tb_test_pago_detalle_comprobantes', 'id_fecha_probable')) {
            return;
        }

        Schema::table('tb_test_pago_detalle_comprobantes', function (Blueprint $table) {
            $table->integer('id_fecha_probable')->nullable()->after('id_pago');
            $table->index('id_fecha_probable', 'idx_comprobante_fecha');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('tb_test_pago_detalle_comprobantes', 'id_fecha_probable')) {
            return;
        }

        Schema::table('tb_test_pago_detalle_comprobantes', function (Blueprint $table) {
            $table->dropIndex('idx_comprobante_fecha');
            $table->dropColumn('id_fecha_probable');
        });
    }
};
