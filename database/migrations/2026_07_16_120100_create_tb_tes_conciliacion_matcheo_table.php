<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('tb_tes_conciliacion_matcheo', function (Blueprint $table) {
            $table->increments('id_matching');
            $table->integer('id_extracto_bancario');
            $table->integer('id_movimiento_interno')->nullable(); // candidato de tb_tes_movimiento_cuenta_bancaria; null = sin candidato
            $table->string('tipo_origen_interno', 50)->nullable(); // informativo: PAGO / TRANSFERENCIA / OPERACION_MANUAL
            $table->integer('score_obtenido')->default(0);
            $table->json('reglas_cumplidas')->nullable();
            $table->boolean('estado')->default(false); // 0 = cargado/sugerido, 1 = aprobado
            $table->integer('id_usuario_aprobador')->nullable();
            $table->dateTime('fecha_matching')->nullable();
            $table->string('observaciones', 255)->nullable();

            $table->foreign('id_extracto_bancario')->references('id_extracto')->on('tb_tes_extracto_bancarios');
            $table->foreign('id_movimiento_interno')->references('id_movimiento')->on('tb_tes_movimiento_cuenta_bancaria');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tb_tes_conciliacion_matcheo');
    }
};
