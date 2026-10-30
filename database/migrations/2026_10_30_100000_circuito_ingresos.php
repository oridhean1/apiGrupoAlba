<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Circuito de Ingresos (R-00000630): clientes, comprobantes de venta y órdenes de cobranza.
 *
 * Espejo de la OPA del lado de ingresos. Ver docs/circuito-ingresos/README.md.
 *
 * SQL crudo y no Blueprint: hacen falta CHECKs (importes > 0, saldo dentro del total, una sola
 * punta de origen en la imputación), y así este archivo y docs/circuito-ingresos/sql-tablas.md
 * son el mismo texto.
 *
 * Las FK contra tablas viejas (`tb_razones_sociales`, `tb_tes_cuentas_bancarias`,
 * `tb_tes_formas_pago`, `tb_cont_asientos_contables`) son `int(11)` con signo (ver CLAUDE.md).
 *
 * El saldo de la factura (`tb_tes_ing_comprobante.saldo`) y el del anticipo
 * (`tb_tes_ing_orden_cobranza.saldo_anticipo`) se guardan para poder bloquear la fila al imputar;
 * se mueven sólo dentro de la transacción que crea o anula la imputación.
 */
return new class extends Migration
{
    private const TABLAS = [
        'tb_tes_ing_anticipo_aplicacion',
        'tb_tes_ing_imputacion',
        'tb_tes_ing_retencion',
        'tb_tes_ing_orden_medio',
        'tb_tes_ing_orden_cobranza',
        'tb_tes_ing_estado_orden_cobranza',
        'tb_tes_ing_comprobante',
        'tb_tes_ing_importacion',
        'tb_tes_ing_tipo_comprobante',
        'tb_tes_ing_cliente',
    ];

    public function up(): void
    {
        if (Schema::hasTable('tb_tes_ing_cliente')) {
            return;
        }

        DB::statement("
            CREATE TABLE tb_tes_ing_cliente (
                id_cliente           INT NOT NULL AUTO_INCREMENT,
                cuit                 VARCHAR(11) NOT NULL,
                razon_social         VARCHAR(200) NOT NULL,
                sigla                VARCHAR(30) NULL,
                condicion_iva        VARCHAR(50) NULL,
                id_razon_grupo       INT NULL COMMENT 'Si el cliente es una empresa del grupo',
                id_obra              BIGINT UNSIGNED NULL COMMENT 'Vinculo opcional al padron tb_obras_sociales',
                activo               TINYINT(1) NOT NULL DEFAULT 1,
                cod_usuario          INT NULL,
                fecha_registra       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                cod_usuario_modifica INT NULL,
                fecha_modifica       DATETIME NULL,
                PRIMARY KEY (id_cliente),
                UNIQUE KEY uq_ing_cliente_cuit (cuit),
                CONSTRAINT fk_ing_cliente_razon FOREIGN KEY (id_razon_grupo) REFERENCES tb_razones_sociales (id_razon),
                CONSTRAINT fk_ing_cliente_obra FOREIGN KEY (id_obra) REFERENCES tb_obras_sociales (id_obra)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // PK = código de comprobante de ARCA, así la importación no traduce nada.
        DB::statement("
            CREATE TABLE tb_tes_ing_tipo_comprobante (
                id_tipo_comprobante INT NOT NULL,
                descripcion         VARCHAR(50) NOT NULL,
                clase               ENUM('FC','ND','NC') NOT NULL,
                letra               CHAR(1) NOT NULL,
                vigente             TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (id_tipo_comprobante)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            INSERT INTO tb_tes_ing_tipo_comprobante (id_tipo_comprobante, descripcion, clase, letra) VALUES
                (1, 'FACTURA A', 'FC', 'A'),
                (2, 'NOTA DE DEBITO A', 'ND', 'A'),
                (3, 'NOTA DE CREDITO A', 'NC', 'A'),
                (6, 'FACTURA B', 'FC', 'B'),
                (7, 'NOTA DE DEBITO B', 'ND', 'B'),
                (8, 'NOTA DE CREDITO B', 'NC', 'B')
        ");

        DB::statement("
            CREATE TABLE tb_tes_ing_importacion (
                id_importacion   INT NOT NULL AUTO_INCREMENT,
                id_razon         INT NOT NULL,
                nombre_archivo   VARCHAR(255) NOT NULL,
                hash_archivo     CHAR(64) NULL,
                filas_total      INT NOT NULL DEFAULT 0,
                filas_importadas INT NOT NULL DEFAULT 0,
                filas_observadas INT NOT NULL DEFAULT 0,
                cod_usuario      INT NULL,
                fecha_registra   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id_importacion),
                KEY idx_ing_importacion_razon (id_razon),
                CONSTRAINT fk_ing_importacion_razon FOREIGN KEY (id_razon) REFERENCES tb_razones_sociales (id_razon)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // FC, ND y NC de venta. `saldo`: lo que falta cobrar (FC/ND) o aplicar (NC).
        // Una NC/ND apunta a su factura por `id_comprobante_asociado`.
        DB::statement("
            CREATE TABLE tb_tes_ing_comprobante (
                id_comprobante          INT NOT NULL AUTO_INCREMENT,
                id_razon                INT NOT NULL,
                id_cliente              INT NOT NULL,
                id_tipo_comprobante     INT NOT NULL,
                punto_venta             INT NOT NULL,
                numero                  BIGINT NOT NULL,
                fecha_emision           DATE NOT NULL,
                periodo_desde           DATE NULL,
                periodo_hasta           DATE NULL,
                importe_neto_gravado    DECIMAL(18,2) NOT NULL DEFAULT 0,
                importe_no_gravado      DECIMAL(18,2) NOT NULL DEFAULT 0,
                importe_exento          DECIMAL(18,2) NOT NULL DEFAULT 0,
                importe_iva             DECIMAL(18,2) NOT NULL DEFAULT 0,
                importe_otros_tributos  DECIMAL(18,2) NOT NULL DEFAULT 0,
                importe_total           DECIMAL(18,2) NOT NULL,
                saldo                   DECIMAL(18,2) NOT NULL,
                cae                     VARCHAR(20) NULL,
                id_comprobante_asociado INT NULL,
                id_importacion          INT NULL,
                id_asiento_contable     INT NULL,
                anulado                 TINYINT(1) NOT NULL DEFAULT 0,
                cod_usuario             INT NULL,
                fecha_registra          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                cod_usuario_modifica    INT NULL,
                fecha_modifica          DATETIME NULL,
                PRIMARY KEY (id_comprobante),
                UNIQUE KEY uq_ing_comprobante (id_razon, id_tipo_comprobante, punto_venta, numero),
                KEY idx_ing_comprobante_cliente (id_razon, id_cliente, saldo),
                KEY idx_ing_comprobante_asociado (id_comprobante_asociado),
                CONSTRAINT chk_ing_comprobante_total CHECK (importe_total > 0),
                CONSTRAINT chk_ing_comprobante_saldo CHECK (saldo >= 0 AND saldo <= importe_total),
                CONSTRAINT fk_ing_comprobante_razon FOREIGN KEY (id_razon) REFERENCES tb_razones_sociales (id_razon),
                CONSTRAINT fk_ing_comprobante_cliente FOREIGN KEY (id_cliente) REFERENCES tb_tes_ing_cliente (id_cliente),
                CONSTRAINT fk_ing_comprobante_tipo FOREIGN KEY (id_tipo_comprobante) REFERENCES tb_tes_ing_tipo_comprobante (id_tipo_comprobante),
                CONSTRAINT fk_ing_comprobante_asociado FOREIGN KEY (id_comprobante_asociado) REFERENCES tb_tes_ing_comprobante (id_comprobante),
                CONSTRAINT fk_ing_comprobante_importacion FOREIGN KEY (id_importacion) REFERENCES tb_tes_ing_importacion (id_importacion),
                CONSTRAINT fk_ing_comprobante_asiento FOREIGN KEY (id_asiento_contable) REFERENCES tb_cont_asientos_contables (id_asiento_contable)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE tb_tes_ing_estado_orden_cobranza (
                id_estado   INT NOT NULL,
                descripcion VARCHAR(50) NOT NULL,
                name_class  VARCHAR(50) NOT NULL,
                vigente     TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (id_estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            INSERT INTO tb_tes_ing_estado_orden_cobranza (id_estado, descripcion, name_class) VALUES
                (1, 'BORRADOR', 'badge bg-secondary'),
                (2, 'APLICADA', 'badge bg-success'),
                (3, 'APLICACION PARCIAL', 'badge bg-warning'),
                (4, 'ANULADA', 'badge bg-danger')
        ");

        // `numero` se asigna al confirmar, correlativo por razón social (OC-000001).
        // `saldo_anticipo`: excedente todavía no aplicado; una orden sin facturas es todo anticipo.
        DB::statement("
            CREATE TABLE tb_tes_ing_orden_cobranza (
                id_orden_cobranza     INT NOT NULL AUTO_INCREMENT,
                numero                INT NULL,
                id_razon              INT NOT NULL,
                id_cliente            INT NOT NULL,
                fecha_cobro           DATE NOT NULL,
                id_estado             INT NOT NULL DEFAULT 1,
                saldo_anticipo        DECIMAL(18,2) NOT NULL DEFAULT 0,
                observaciones         VARCHAR(500) NULL,
                id_asiento_contable   INT NULL,
                id_asiento_anulacion  INT NULL,
                fecha_confirma        DATETIME NULL,
                cod_usuario_confirma  INT NULL,
                fecha_anula           DATETIME NULL,
                cod_usuario_anula     INT NULL,
                motivo_anulacion      VARCHAR(500) NULL,
                cod_usuario           INT NULL,
                fecha_registra        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                cod_usuario_modifica  INT NULL,
                fecha_modifica        DATETIME NULL,
                PRIMARY KEY (id_orden_cobranza),
                UNIQUE KEY uq_ing_orden_numero (id_razon, numero),
                KEY idx_ing_orden_cliente (id_razon, id_cliente, id_estado),
                CONSTRAINT chk_ing_orden_saldo_anticipo CHECK (saldo_anticipo >= 0),
                CONSTRAINT fk_ing_orden_razon FOREIGN KEY (id_razon) REFERENCES tb_razones_sociales (id_razon),
                CONSTRAINT fk_ing_orden_cliente FOREIGN KEY (id_cliente) REFERENCES tb_tes_ing_cliente (id_cliente),
                CONSTRAINT fk_ing_orden_estado FOREIGN KEY (id_estado) REFERENCES tb_tes_ing_estado_orden_cobranza (id_estado),
                CONSTRAINT fk_ing_orden_asiento FOREIGN KEY (id_asiento_contable) REFERENCES tb_cont_asientos_contables (id_asiento_contable),
                CONSTRAINT fk_ing_orden_asiento_anul FOREIGN KEY (id_asiento_anulacion) REFERENCES tb_cont_asientos_contables (id_asiento_contable)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Renglones de medio de cobro: una orden admite varios medios y varias cuentas.
        DB::statement("
            CREATE TABLE tb_tes_ing_orden_medio (
                id_orden_medio     INT NOT NULL AUTO_INCREMENT,
                id_orden_cobranza  INT NOT NULL,
                id_forma_pago      INT NOT NULL,
                id_cuenta_bancaria INT NULL,
                referencia         VARCHAR(100) NULL,
                importe            DECIMAL(18,2) NOT NULL,
                PRIMARY KEY (id_orden_medio),
                KEY idx_ing_medio_orden (id_orden_cobranza),
                CONSTRAINT chk_ing_medio_importe CHECK (importe > 0),
                CONSTRAINT fk_ing_medio_orden FOREIGN KEY (id_orden_cobranza) REFERENCES tb_tes_ing_orden_cobranza (id_orden_cobranza) ON DELETE CASCADE,
                CONSTRAINT fk_ing_medio_forma FOREIGN KEY (id_forma_pago) REFERENCES tb_tes_formas_pago (id_forma_pago),
                CONSTRAINT fk_ing_medio_cuenta FOREIGN KEY (id_cuenta_bancaria) REFERENCES tb_tes_cuentas_bancarias (id_cuenta_bancaria)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE tb_tes_ing_retencion (
                id_retencion       INT NOT NULL AUTO_INCREMENT,
                id_orden_cobranza  INT NOT NULL,
                tipo_retencion     VARCHAR(20) NOT NULL DEFAULT 'GANANCIAS',
                numero_certificado VARCHAR(50) NULL,
                fecha_retencion    DATE NULL,
                importe            DECIMAL(18,2) NOT NULL,
                PRIMARY KEY (id_retencion),
                KEY idx_ing_retencion_orden (id_orden_cobranza),
                CONSTRAINT chk_ing_retencion_importe CHECK (importe > 0),
                CONSTRAINT fk_ing_retencion_orden FOREIGN KEY (id_orden_cobranza) REFERENCES tb_tes_ing_orden_cobranza (id_orden_cobranza) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Qué cancela la deuda de una FC/ND: una orden de cobranza o una NC, nunca las dos.
        DB::statement("
            CREATE TABLE tb_tes_ing_imputacion (
                id_imputacion     INT NOT NULL AUTO_INCREMENT,
                id_comprobante    INT NOT NULL,
                id_orden_cobranza INT NULL,
                id_comprobante_nc INT NULL,
                importe           DECIMAL(18,2) NOT NULL,
                anulado           TINYINT(1) NOT NULL DEFAULT 0,
                cod_usuario       INT NULL,
                fecha_registra    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id_imputacion),
                KEY idx_ing_imputacion_comprobante (id_comprobante),
                KEY idx_ing_imputacion_orden (id_orden_cobranza),
                KEY idx_ing_imputacion_nc (id_comprobante_nc),
                CONSTRAINT chk_ing_imputacion_importe CHECK (importe > 0),
                CONSTRAINT chk_ing_imputacion_origen CHECK ((id_orden_cobranza IS NULL) <> (id_comprobante_nc IS NULL)),
                CONSTRAINT fk_ing_imputacion_comprobante FOREIGN KEY (id_comprobante) REFERENCES tb_tes_ing_comprobante (id_comprobante),
                CONSTRAINT fk_ing_imputacion_orden FOREIGN KEY (id_orden_cobranza) REFERENCES tb_tes_ing_orden_cobranza (id_orden_cobranza) ON DELETE CASCADE,
                CONSTRAINT fk_ing_imputacion_nc FOREIGN KEY (id_comprobante_nc) REFERENCES tb_tes_ing_comprobante (id_comprobante)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Anticipo (orden con saldo_anticipo) usado como concepto de cobro en otra orden.
        DB::statement("
            CREATE TABLE tb_tes_ing_anticipo_aplicacion (
                id_anticipo_aplicacion INT NOT NULL AUTO_INCREMENT,
                id_orden_cobranza      INT NOT NULL,
                id_orden_anticipo      INT NOT NULL,
                importe                DECIMAL(18,2) NOT NULL,
                anulado                TINYINT(1) NOT NULL DEFAULT 0,
                cod_usuario            INT NULL,
                fecha_registra         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id_anticipo_aplicacion),
                KEY idx_ing_anticipo_orden (id_orden_cobranza),
                KEY idx_ing_anticipo_origen (id_orden_anticipo),
                CONSTRAINT chk_ing_anticipo_importe CHECK (importe > 0),
                CONSTRAINT chk_ing_anticipo_distinta CHECK (id_orden_cobranza <> id_orden_anticipo),
                CONSTRAINT fk_ing_anticipo_orden FOREIGN KEY (id_orden_cobranza) REFERENCES tb_tes_ing_orden_cobranza (id_orden_cobranza) ON DELETE CASCADE,
                CONSTRAINT fk_ing_anticipo_origen FOREIGN KEY (id_orden_anticipo) REFERENCES tb_tes_ing_orden_cobranza (id_orden_cobranza)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            DB::statement("DROP TABLE IF EXISTS {$tabla}");
        }
    }
};
