<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Circuito de ingresos: cheque / eCheq vía Valores a depositar (README 13.2-7 y 13.4).
 * SQL crudo para correr a mano en docs/circuito-ingresos/sql-cheques.md (incluye los mapeos de cuentas).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE tb_tes_ing_orden_medio
            ADD COLUMN banco_emisor        VARCHAR(100) NULL AFTER referencia,
            ADD COLUMN fecha_pago_cheque   DATE NULL AFTER banco_emisor,
            ADD COLUMN id_cuenta_deposito  INT NULL AFTER fecha_pago_cheque,
            ADD COLUMN fecha_deposito      DATE NULL AFTER id_cuenta_deposito,
            ADD COLUMN id_asiento_deposito INT NULL AFTER fecha_deposito,
            ADD COLUMN cod_usuario_deposita INT NULL AFTER id_asiento_deposito,
            ADD COLUMN fecha_registra_deposito DATETIME NULL AFTER cod_usuario_deposita,
            ADD KEY idx_ing_medio_deposito (id_cuenta_deposito),
            ADD CONSTRAINT fk_ing_medio_cuenta_deposito FOREIGN KEY (id_cuenta_deposito) REFERENCES tb_tes_cuentas_bancarias (id_cuenta_bancaria)");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tb_tes_ing_orden_medio
            DROP FOREIGN KEY fk_ing_medio_cuenta_deposito,
            DROP KEY idx_ing_medio_deposito,
            DROP COLUMN banco_emisor, DROP COLUMN fecha_pago_cheque, DROP COLUMN id_cuenta_deposito,
            DROP COLUMN fecha_deposito, DROP COLUMN id_asiento_deposito, DROP COLUMN cod_usuario_deposita,
            DROP COLUMN fecha_registra_deposito");
    }
};
