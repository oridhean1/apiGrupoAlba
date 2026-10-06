<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Número de OPA con fecha: OPA-AAAAMMDD-consecutivo (pedido del cliente, 2026-10-06).
 *
 *   OPA-20261006-14493
 *
 * - El consecutivo es el mismo contador de siempre (`sec_correlativos`), completo y sin recortar:
 *   no se repite nunca (ver sql-fix-numeracion-opa.md).
 * - La fecha es la de `fecha_genera` de la orden, que la pone la aplicación en hora argentina. No
 *   se usa el reloj de la base: puede estar en UTC y fecharía mañana las órdenes de la noche.
 * - Las OPAs existentes CONSERVAN su número viejo. Si lo piden, se renumeran aparte.
 *
 * Entra en `num_orden_pago varchar(20)`: 'OPA-' + 8 + '-' + 7 dígitos = 20.
 * Los buscadores por N° de OPA toman el último grupo de dígitos (ver NumeroOpa), así que buscar
 * "14493" encuentra la nueva y "1435" sigue encontrando la vieja OPA-1435.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS tg_asignar_numero_opa');
        DB::unprepared("
            CREATE TRIGGER tg_asignar_numero_opa BEFORE INSERT ON tb_tes_orden_pago
            FOR EACH ROW
            BEGIN
                DECLARE LET_SECUENCIAL INT DEFAULT 0;
                SET LET_SECUENCIAL = NEXTVAL(sec_correlativos);
                SET NEW.num_orden_pago = CONCAT('OPA-',
                    DATE_FORMAT(COALESCE(NEW.fecha_genera, NOW()), '%Y%m%d'), '-', LET_SECUENCIAL);
            END
        ");
    }

    public function down(): void
    {
        // Vuelve al formato anterior (OPA-consecutivo, sin recortar).
        DB::unprepared('DROP TRIGGER IF EXISTS tg_asignar_numero_opa');
        DB::unprepared("
            CREATE TRIGGER tg_asignar_numero_opa BEFORE INSERT ON tb_tes_orden_pago
            FOR EACH ROW
            BEGIN
                DECLARE LET_SECUENCIAL INT DEFAULT 0;
                SET LET_SECUENCIAL = NEXTVAL(sec_correlativos);
                SET NEW.num_orden_pago = CONCAT('OPA-', IF(LET_SECUENCIAL < 10000, LPAD(LET_SECUENCIAL, 4, '0'), LET_SECUENCIAL));
            END
        ");
    }
};
