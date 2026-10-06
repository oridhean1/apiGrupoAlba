<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Videos tutoriales de Soporte › Tutoriales (2026-10-05).
 *
 * Una fila por video. El archivo vive en `storage/app/tutoriales` (fuera de la carpeta pública) y
 * lo sirve `TutorialesController` con el mismo login que el resto de la API: los videos muestran
 * datos reales de prestadores.
 *
 * Sumar un video es copiar el archivo a esa carpeta e insertar una fila acá: no hay que tocar el
 * front ni redeployar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tb_soporte_tutoriales')) {
            return;
        }

        DB::statement("
            CREATE TABLE tb_soporte_tutoriales (
              id_tutorial  int(11)      NOT NULL AUTO_INCREMENT,
              modulo       varchar(60)  NOT NULL,
              titulo       varchar(120) NOT NULL,
              descripcion  varchar(255) NULL,
              archivo      varchar(150) NOT NULL,
              orden        int(11)      NOT NULL DEFAULT 0,
              activo       tinyint(1)   NOT NULL DEFAULT 1,
              PRIMARY KEY (id_tutorial),
              UNIQUE KEY uq_tutorial_archivo (archivo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        DB::table('tb_soporte_tutoriales')->insert([
            ['modulo' => 'Pagos', 'titulo' => 'Generar OPA', 'orden' => 1, 'archivo' => '01-pagos-generar-opa.webm',
             'descripcion' => 'Armar la orden de pago a partir de las facturas verificadas, con su cronograma.'],
            ['modulo' => 'Pagos', 'titulo' => 'Confirmar pago', 'orden' => 2, 'archivo' => '02-pagos-confirmar-pago.webm',
             'descripcion' => 'Cargar los abonos de una orden: transferencia, eCheq, cuenta de origen.'],
            ['modulo' => 'Tesorería', 'titulo' => 'Carga de eCheq', 'orden' => 3, 'archivo' => '03-tesoreria-echeq.webm',
             'descripcion' => 'Cargar el número que da el banco y acreditar el eCheq.'],
            ['modulo' => 'Tesorería', 'titulo' => 'Anticipos', 'orden' => 4, 'archivo' => '04-tesoreria-anticipos.webm',
             'descripcion' => 'Crear un anticipo, pagarlo y aplicarlo a facturas.'],
            ['modulo' => 'Tesorería', 'titulo' => 'Cuenta corriente', 'orden' => 5, 'archivo' => '05-tesoreria-cuenta-corriente.webm',
             'descripcion' => 'Saldo económico y financiero, facturas canceladas por pago y exportación a Excel.'],
            ['modulo' => 'Pagos', 'titulo' => 'Pagos a emitir', 'orden' => 6, 'archivo' => '06-pagos-pagos-a-emitir.webm',
             'descripcion' => 'El listado de eCheq y transferencias que faltan emitir en el banco.'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_soporte_tutoriales');
    }
};
