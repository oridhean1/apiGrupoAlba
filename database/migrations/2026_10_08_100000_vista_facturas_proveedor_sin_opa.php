<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Facturas Proveedores: mostrar también las facturas SIN orden de pago (2026-10-08).
 *
 * La vista hacía INNER JOIN con tb_tes_orden_pago_detalle / tb_tes_orden_pago, así que solo listaba
 * facturas de proveedor que ya tenían OPA. Antes no se notaba porque la factura de proveedor generaba
 * su OPA sola al cargarse; con el circuito de pagos la OPA se arma en Pagos › Generar OPA, y las
 * facturas nuevas quedaban invisibles en Facturación › Facturas Proveedores (reclamo: factura 616 de
 * CONSTRUCCIONES HOCEAM, que sí se veía en Generar OPA).
 *
 * Solo cambian esos dos JOIN a LEFT JOIN: `cod_orden_pago` y `factura_unida` vienen NULL para una
 * factura sin OPA; el resto de las columnas sale de la factura.
 */
return new class extends Migration
{
    private function vista(string $joinOpa): string
    {
        return "CREATE OR REPLACE VIEW vw_matriz_facturas_proveedor AS
select `f`.`id_tipo_factura` AS `id_tipo_factura`,`f`.`fecha_registra` AS `fecha_registra`,`p`.`cuit` AS `cuit`,`p`.`razon_social` AS `razon_social`,`p`.`cod_proveedor` AS `cod_proveedor`,concat(`f`.`tipo_letra`,' ',`f`.`sucursal`,' - ',`f`.`numero`) AS `comprobante`,`s`.`nombre_sindicato` AS `delegacion`,`f`.`fecha_comprobante` AS `fecha_comprobante`,`f`.`fecha_vencimiento` AS `fecha_vencimiento`,concat(substr(`f`.`periodo`,6,7),'/',substr(`f`.`periodo`,1,4)) AS `periodo`,`f`.`subtotal` AS `subtotal`,`f`.`total_iva` AS `total_iva`,`f`.`total_neto` AS `total_neto`,`f`.`estado` AS `estado`,`f`.`id_factura` AS `id_factura`,`f`.`total_debitado_liquidacion` AS `total_debitado_liquidacion`,`f`.`tipo_carga_detalle` AS `tipo_detalle`,`f`.`total_aprobado_liquidacion` AS `total_aprobado`,`f`.`total_facturado_liquidacion` AS `total_facturado`,`f`.`cod_sindicato` AS `id_locatorio`,`tl`.`locatorio` AS `locatario`,`f`.`id_tipo_imputacion_sintetizada` AS `id_tipo_imputacion`,`f`.`estado_pago` AS `estado_pago`,`p`.`email` AS `email`,`f`.`id_tipo_comprobante` AS `id_tipo_comprobante`,`fc`.`descripcion` AS `tipo_comprobante`,`f`.`observaciones_resumen` AS `observaciones`,`rs`.`razon_social` AS `r_social`,`rs`.`id_razon` AS `id_razon`,`rs`.`cuit` AS `cuit_r_social`,`opad`.`factura_unida` AS `factura_unida`,`opa`.`id_orden_pago` AS `cod_orden_pago` from (((((((`tb_facturacion_datos` `f` join `tb_proveedor` `p` on(`f`.`id_proveedor` = `p`.`cod_proveedor`)) join `tb_sindicatos` `s` on(`f`.`cod_sindicato` = `s`.`cod_sindicato`)) join `tb_locatorio` `tl` on(`tl`.`id_locatorio` = `f`.`cod_sindicato`)) join `tb_facturacion_tipo_comprobantes` `fc` on(`f`.`id_tipo_comprobante` = `fc`.`id_tipo_comprobante`)) join `tb_razones_sociales` `rs` on(`rs`.`id_razon` = `f`.`id_locatorio`)) {$joinOpa} `tb_tes_orden_pago_detalle` `opad` on(`opad`.`id_factura` = `f`.`id_factura`)) {$joinOpa} `tb_tes_orden_pago` `opa` on(`opa`.`id_orden_pago` = `opad`.`id_orden_pago`))";
    }

    public function up(): void
    {
        DB::statement($this->vista('left join'));
    }

    public function down(): void
    {
        DB::statement($this->vista('join'));
    }
};
