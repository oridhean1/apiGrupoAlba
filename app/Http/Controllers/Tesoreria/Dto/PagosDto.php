<?php

namespace App\Http\Controllers\Tesoreria\Dto;

class PagosDto
{
    public  $id_orden_pago;
    public  $id_cuenta_bancaria;
    public  $fecha_probable_pago;
    public  $anticipo;
    public  $comprobante;
    public  $id_forma_pago;
    public  $monto_pago;
    public  $observaciones;
    public  $id_estado_orden_pago;
    public  $monto_opa;
    public $recursor;
    public $fecha_confirma_pago;
    public $tipo_factura;
    public $pago_emergencia;

    public function __construct($id_orden_pago,  $id_cuenta_bancaria,  $fecha_probable_pago,  $anticipo,  $comprobante,  $id_forma_pago,  $monto_pago,  $observaciones,  $id_estado_orden_pago,  $monto_opa,  $recursor,  $fecha_confirma_pago, $tipo_factura = 'PROVEEDOR')
    {
        $this->id_orden_pago = $id_orden_pago;
        $this->id_cuenta_bancaria = $id_cuenta_bancaria;
        $this->fecha_probable_pago = $fecha_probable_pago;
        $this->anticipo = $anticipo;
        $this->comprobante = $comprobante;
        $this->id_forma_pago = $id_forma_pago;
        $this->monto_pago = $monto_pago;
        $this->observaciones = $observaciones;
        $this->id_estado_orden_pago = $id_estado_orden_pago;
        $this->monto_opa = $monto_opa;
        $this->recursor = $recursor;
        $this->fecha_confirma_pago = $fecha_confirma_pago;
        $this->tipo_factura = $tipo_factura;
        $this->pago_emergencia = 0;
    }
}
