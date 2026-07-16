<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('tb_tes_cuentas_bancarias', function (Blueprint $table) {
            $table->integer('id_razon')->nullable()->after('id_cuenta_bancaria');
        });

        // Backfill: todas las cuentas existentes quedan en Grupo Alba Salud (id_razon = 1) hasta que se revisen a mano.
        DB::table('tb_tes_cuentas_bancarias')->whereNull('id_razon')->update(['id_razon' => 1]);

        Schema::table('tb_tes_cuentas_bancarias', function (Blueprint $table) {
            $table->integer('id_razon')->nullable(false)->change();
            $table->foreign('id_razon')->references('id_razon')->on('tb_razones_sociales');
        });
    }

    public function down()
    {
        Schema::table('tb_tes_cuentas_bancarias', function (Blueprint $table) {
            $table->dropForeign(['id_razon']);
            $table->dropColumn('id_razon');
        });
    }
};
