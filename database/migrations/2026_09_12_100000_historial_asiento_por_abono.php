<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El historial de asientos de pago pasa a poder referirse a UN ABONO, no solo a la boleta.
     *
     * `tb_cont_asientos_pago_historial` ya guarda `id_asiento_contable_detalle` (la línea puntual
     * del asiento), pero se indexa por `id_pago` — la boleta. Con el circuito de eCheq eso no
     * alcanza: una boleta puede tener varios instrumentos, cada uno con su propio asiento de
     * emisión y su propio asiento de débito, y al rechazar uno hay que poder contraasentar **su**
     * línea sin tocar las de los demás. Con la clave en la boleta no hay forma de saber cuál es.
     *
     * Es el mismo error que ya corregimos tres veces en este circuito: la boleta parada donde iba
     * el abono (la razón social, la fecha de confirmación, el permiso para acreditar).
     *
     * ⚠️ Además alinea `tipo_evento`, que **difiere entre las dos bases**:
     *
     *     alba3 : varchar(20)
     *     osv2  : enum('ALTA','MODIFICACION','ANULACION')
     *
     * Los eventos nuevos son EMISION, DEBITO y CONTRAASIENTO. En OSV el ENUM los rechaza, y en
     * modo no estricto es peor: entran como cadena vacía y el historial queda mudo justo para lo
     * que se quiere auditar. Se lleva a varchar(20) en las dos.
     *
     * Sin FK a propósito: es auditoría, tiene que sobrevivir al borrado de lo que referencia.
     * Mismo criterio que el resto de los historiales del proyecto.
     */
    public function up()
    {
        $tabla = 'tb_cont_asientos_pago_historial';

        if (!Schema::hasTable($tabla)) {
            return;
        }

        if (!Schema::hasColumn($tabla, 'id_pago_parcial')) {
            Schema::table($tabla, function (Blueprint $table) {
                // int(11) CON SIGNO: las tablas viejas usan signed, y `unsigned` rompe cualquier
                // FK futura contra ellas con error 1005.
                $table->integer('id_pago_parcial')->nullable()->after('id_pago');
                $table->index('id_pago_parcial', 'idx_asipago_hist_parcial');
            });
        }

        // Idempotente: si ya es varchar no hace nada. Se usa SQL crudo porque Doctrine DBAL no
        // maneja ENUM y `$table->string()->change()` explota en esta tabla.
        $col = collect(DB::select("SHOW COLUMNS FROM {$tabla} LIKE 'tipo_evento'"))->first();

        if ($col && stripos($col->Type, 'enum') === 0) {
            DB::statement("ALTER TABLE {$tabla} MODIFY COLUMN tipo_evento varchar(20) NOT NULL");
        }
    }

    public function down()
    {
        $tabla = 'tb_cont_asientos_pago_historial';

        if (Schema::hasColumn($tabla, 'id_pago_parcial')) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropIndex('idx_asipago_hist_parcial');
                $table->dropColumn('id_pago_parcial');
            });
        }

        // `tipo_evento` NO se revierte al ENUM: para entonces puede haber filas con EMISION /
        // DEBITO / CONTRAASIENTO, y volver al ENUM las vaciaría en silencio.
    }
};
