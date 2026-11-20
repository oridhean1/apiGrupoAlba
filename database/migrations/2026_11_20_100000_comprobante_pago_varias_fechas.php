<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un comprobante de pago puede respaldar VARIAS fechas del cronograma (R-00000661, 2026-11-20).
 *
 * Caso real: OPA-20261106-14576, una sola foto de dos cheques de fechas distintas. Con una sola
 * fecha por comprobante había que elegir una incorrecta. Se descartó hacer la fecha opcional: el
 * comprobante ejecuta los pagos de sus fechas (débito y asiento), y "sin fecha = toda la boleta"
 * ejecutaría pagos que no salieron y perdería qué comprobante respalda cada pago.
 *
 * - Una fila por (comprobante, fecha). `estado = 0` cuando se anula el pago de esa fecha: queda
 *   como historia, con el pago anulado que lo motivó (`id_pago_parcial_baja`).
 * - Se puebla con los comprobantes que ya tenían `id_fecha_probable`. La columna queda en la
 *   cabecera por compatibilidad (se llena cuando es una sola fecha).
 * - Sin FK: los tipos de las tablas viejas son `int(11)` con signo; índices alcanzan.
 *
 * SQL equivalente: docs/circuito-pagos/sql-comprobante-varias-fechas.md
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tb_tes_comprobante_pago_fecha')) {
            return;
        }

        DB::statement("
            CREATE TABLE tb_tes_comprobante_pago_fecha (
                id_comprobante_fecha INT NOT NULL AUTO_INCREMENT,
                id_comprobante INT NOT NULL,
                id_fecha_probable INT NOT NULL,
                estado TINYINT NOT NULL DEFAULT 1,
                fecha_registra DATETIME NULL,
                cod_usuario_registra INT NULL,
                fecha_baja DATETIME NULL,
                cod_usuario_baja INT NULL,
                id_pago_parcial_baja INT NULL,
                motivo_baja VARCHAR(255) NULL,
                PRIMARY KEY (id_comprobante_fecha),
                UNIQUE KEY uq_comprobante_fecha (id_comprobante, id_fecha_probable),
                KEY idx_cpf_fecha (id_fecha_probable)
            )
        ");

        DB::statement("
            INSERT INTO tb_tes_comprobante_pago_fecha
                (id_comprobante, id_fecha_probable, estado, fecha_registra, cod_usuario_registra)
            SELECT id_comprobante, id_fecha_probable, 1, fecha_registra, cod_usuario_registra
            FROM tb_test_pago_detalle_comprobantes
            WHERE id_fecha_probable IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_tes_comprobante_pago_fecha');
    }
};
