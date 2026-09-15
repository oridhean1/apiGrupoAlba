<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca si el número de eCheq es PROVISORIO (lo puso el sistema) o real (lo asignó el banco).
     *
     * Hasta ahora el eCheq se cargaba sin número y esperaba en una pantalla aparte —*Carga de
     * eCheq › Sin número*— a que el banco lo asignara. Esa pantalla se elimina: el número se carga
     * junto con el resto del pago, y si todavía no se lo tiene el sistema pone uno provisorio para
     * que la carga no se frene.
     *
     * Hace falta una columna y no alcanza con mirar el texto:
     *
     * - `numero_echeq` tiene un índice UNIQUE global (`uq_pp_numero_echeq`) — un eCheq, un número.
     *   Un provisorio lo ocupa igual que uno real, así que sin una marca no hay forma de saber
     *   cuáles siguen esperando el número de verdad. Eso es justamente lo que la pantalla que se
     *   elimina resolvía a la vista.
     * - Un provisorio **no puede salir impreso en un comprobante ni exportarse al banco** como si
     *   fuera el número real. Con la marca explícita se puede impedir; deduciéndolo del formato del
     *   string, no.
     *
     * El número provisorio se arma como `PROV-<id_pago_parcial>`:
     *
     * - El prefijo es un formato que un número de banco nunca puede tener, así que **es imposible
     *   que choque con uno real** cuando el banco lo asigne.
     * - Usar el id del abono lo hace único por construcción. Un aleatorio podría colisionar contra
     *   el UNIQUE y hacer fallar la carga del pago, que es exactamente lo que este cambio viene a
     *   evitar.
     */
    public function up()
    {
        if (Schema::hasColumn('tb_tes_pago_parcial', 'numero_provisorio')) {
            return;
        }

        Schema::table('tb_tes_pago_parcial', function (Blueprint $table) {
            // `tinyint(1)` con default 0: los abonos que ya existen tienen número real (o no
            // tienen), ninguno es provisorio.
            $table->boolean('numero_provisorio')->default(false)->after('numero_echeq');
        });
    }

    public function down()
    {
        if (Schema::hasColumn('tb_tes_pago_parcial', 'numero_provisorio')) {
            Schema::table('tb_tes_pago_parcial', function (Blueprint $table) {
                $table->dropColumn('numero_provisorio');
            });
        }
    }
};
