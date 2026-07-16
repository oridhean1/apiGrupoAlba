<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('tb_tes_extracto_bancarios', function (Blueprint $table) {
            // Eliminar columnas del esquema viejo (no usadas por el modelo unificado Cygnus)
            $table->dropColumn([
                'id_entidad_bancaria',
                'fecha_operacion',
                'fecha_valor',
                'codigo',
                'num_cheque',
                'oficina',
                'monto_credito',
                'monto_debito',
                'monto_saldo_parcial',
                'monto_saldo_disponible',
                'num_documento',
                'causal'
            ]);

            // Cuenta bancaria propia (reemplaza a id_entidad_bancaria). Se resuelve por fila
            // cruzando la columna "banco" del Excel contra las cuentas de la razón social elegida;
            // nullable porque un mismo extracto puede traer movimientos de bancos que no matcheamos.
            $table->integer('id_cuenta_bancaria')->nullable()->after('id_extracto');
            // Razón social desnormalizada desde la cuenta bancaria, para filtrar sin JOIN.
            // Nullable en un primer paso para no romper filas históricas; se completa abajo.
            $table->integer('id_razon')->nullable()->after('id_cuenta_bancaria');

            // Columnas del Excel (modelo unificado)
            $table->date('fecha')->nullable()->after('id_razon');
            $table->string('banco', 100)->nullable()->after('fecha'); // texto informativo tal cual viene en la columna B del Excel
            $table->decimal('saldo', 18, 2)->nullable()->after('importe');
            $table->string('referencia', 100)->nullable()->after('saldo');

            // Detalle parseado (formato "NOMBRE | CUIT | INFO") para las reglas de score
            $table->string('detalle_nombre', 150)->nullable()->after('detalle');
            $table->string('detalle_cuit', 20)->nullable()->after('detalle_nombre');

            // Conciliación / matching
            $table->string('estado_conciliacion', 50)->default('PENDIENTE')->after('detalle_cuit');
            $table->integer('score_matching')->nullable()->after('estado_conciliacion');
            $table->integer('id_movimiento_match')->nullable()->after('score_matching');

            // Auditoría de confirmación manual
            $table->integer('id_usuario_confirma')->nullable()->after('fecha_registra');
            $table->dateTime('fecha_confirma')->nullable()->after('id_usuario_confirma');

            $table->foreign('id_cuenta_bancaria')->references('id_cuenta_bancaria')->on('tb_tes_cuentas_bancarias');
            $table->foreign('id_movimiento_match')->references('id_movimiento')->on('tb_tes_movimiento_cuenta_bancaria');
        });

        // Backfill de filas históricas (si las hay) antes de exigir id_razon NOT NULL.
        DB::table('tb_tes_extracto_bancarios')->whereNull('id_razon')->update(['id_razon' => 1]);

        Schema::table('tb_tes_extracto_bancarios', function (Blueprint $table) {
            $table->integer('id_razon')->nullable(false)->change();
            $table->foreign('id_razon')->references('id_razon')->on('tb_razones_sociales');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('tb_tes_extracto_bancarios', function (Blueprint $table) {
            $table->dropForeign(['id_cuenta_bancaria']);
            $table->dropForeign(['id_razon']);
            $table->dropForeign(['id_movimiento_match']);

            $table->dropColumn([
                'id_cuenta_bancaria',
                'id_razon',
                'fecha',
                'banco',
                'saldo',
                'referencia',
                'detalle_nombre',
                'detalle_cuit',
                'estado_conciliacion',
                'score_matching',
                'id_movimiento_match',
                'id_usuario_confirma',
                'fecha_confirma'
            ]);

            // Restaurar viejas (tipos genéricos para rollback)
            $table->integer('id_entidad_bancaria')->nullable();
            $table->date('fecha_operacion')->nullable();
            $table->date('fecha_valor')->nullable();
            $table->string('codigo')->nullable();
            $table->string('num_cheque')->nullable();
            $table->string('oficina')->nullable();
            $table->decimal('monto_credito', 18, 2)->nullable();
            $table->decimal('monto_debito', 18, 2)->nullable();
            $table->decimal('monto_saldo_parcial', 18, 2)->nullable();
            $table->decimal('monto_saldo_disponible', 18, 2)->nullable();
            $table->string('num_documento')->nullable();
            $table->string('causal')->nullable();
        });
    }
};
