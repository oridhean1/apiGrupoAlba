<?php

namespace App\Http\Controllers\facturacion\repository;

use App\Models\facturacion\FacturacionDatosEntity;
use App\Models\facturacion\FacturacionDetalleComprobantesEntity;
use App\Models\facturacion\FacturacionDetalleDescuentoEntity;
use App\Models\facturacion\FacturacionDetalleEntity;
use App\Models\facturacion\FacturacionDetalleImpuestoEntity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FacturaRepository
{
    private $user;
    private $fechaActual;
    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }

    public function findBySaveDatosFactura($params, $cod_usuario, $nombre_archivo)
    {
        return FacturacionDatosEntity::create([
            'id_tipo_factura' => $params->id_tipo_factura,
            'cod_sindicato' => $params->cod_sindicato,
            'id_tipo_comprobante' => $params->id_tipo_comprobante,
            'fecha_comprobante' => $params->fecha_comprobante,
            'id_proveedor' => empty($params->id_proveedor) ? null : $params->id_proveedor,
            'id_prestador' => empty($params->id_prestador) ? null : $params->id_prestador,
            'periodo' => $params->periodo,
            'tipo_letra' => $params->tipo_letra,
            'fecha_vencimiento' => $params->fecha_vencimiento,
            'sucursal' => $params->sucursal,
            'numero' => $params->numero,
            'fecha_registra' => $params->fecha_registra,
            'fecha_actualiza' => null,
            'cod_usuario' => $cod_usuario,
            'subtotal' => $params->subtotal,
            'total_iva' => $params->total_iva,
            'total_neto' => $params->total_neto,
            'num_liquidacion' => 0,
            'cae_cai' => $params->cae_cai,
            'id_tipo_imputacion_sintetizada' => $params->id_tipo_imputacion_sintetizada,
            'archivo' => $nombre_archivo,
            'refacturacion' => $params->refacturacion,
            'id_locatorio' => $params->id_locatorio,
            'estado' => ($params->id_tipo_factura == 16 || $params->id_tipo_factura == 20 ? '1' : ($params->id_tipo_factura == 17 ? '0' : '0')),
            'observaciones_resumen' => $params->observaciones_resumen,
            'comprobante_relacionado' => $params->comprobante_relacionado,
            'tipo_hospital' => $params->tipo_hospital ?? null,
            'cod_hospital' => $params->cod_hospital ?? null
        ]);
    }

    public function findByExistsFactura($params)
    {
        return FacturacionDatosEntity::where('numero', $params->numero)
            ->where('sucursal', $params->sucursal)
            ->where('id_proveedor', $params->id_proveedor)
            ->where('id_tipo_factura', $params->id_tipo_factura)
            ->where('periodo', $params->periodo)
            ->exists();
    }

    public function findBySaveDetalleFactura($detalle, $id_factura)
    {
        foreach ($detalle as $key) {
            FacturacionDetalleEntity::create([
                'id_articulo' => $key->id_articulo,
                'cantidad' => $key->cantidad,
                'precio_neto' => $key->precio_neto,
                'iva' => $key->iva,
                'subtotal' => $key->subtotal,
                'monto_iva' => $key->monto_iva,
                'total_importe' => $key->total_importe,
                'id_factura' => $id_factura,
                'id_tipo_iva' => $key->id_tipo_iva,
                'observaciones' => $key->observaciones
            ]);
        }
    }

    public function findBySaveDetalleImpuestoFactura($detalle, $id_factura)
    {
        foreach ($detalle as $key) {
            /*  if (empty($key->id_detalle_impuesto)) { */
            FacturacionDetalleImpuestoEntity::create([
                'impuesto' => $key->impuesto,
                'porcentaje' => $key->porcentaje,
                'importe' => $key->importe,
                'id_factura' => $id_factura,
                'id_tipo_imputacion' => $key->id_tipo_imputacion,
                'is_grupo' => $key->is_grupo
            ]);
            /* } else {
                $item =  FacturacionDetalleImpuestoEntity::find($key->id_detalle_impuesto);
                $item->impuesto = $key->impuesto;
                $item->porcentaje = $key->porcentaje;
                $item->importe = $key->importe;
                $item->id_factura = $id_factura;
                $item->id_tipo_imputacion = $key->id_tipo_imputacion;
                $item->is_grupo = $key->is_grupo;
                $item->update();
            } */
        }
    }

    public function findBySaveDetalleComprobantesFactura($archivos, $id_factura)
    {
        foreach ($archivos as $key) {
            FacturacionDetalleComprobantesEntity::create([
                'archivo' => $key['nombre'],
                'fecha_carga' => $this->fechaActual,
                'activo' => '1',
                'id_factura' => $id_factura
            ]);
        }
    }

    public function findBySaveDetalleDescuentosFactura($detalle, $id_factura)
    {
        foreach ($detalle as $key) {
            FacturacionDetalleDescuentoEntity::create([
                'descuento' => $key->descuento,
                'importe' => $key->importe,
                'observaciones' => $key->observaciones,
                'id_factura' => $id_factura
            ]);
        }
    }


    public function findByUpdateDatosFactura($params, $fechaActual, $nombre_archivo)
    {
        $facturacion = FacturacionDatosEntity::find($params->id_factura);
        $facturacion->id_tipo_factura = $params->id_tipo_factura;
        $facturacion->cod_sindicato = $params->cod_sindicato;
        $facturacion->id_tipo_comprobante = $params->id_tipo_comprobante;
        $facturacion->fecha_comprobante = $params->fecha_comprobante;
        $facturacion->id_proveedor = empty($params->id_proveedor) ? null : $params->id_proveedor;
        $facturacion->id_prestador = empty($params->id_prestador) ? null : $params->id_prestador;
        $facturacion->periodo = $params->periodo;
        $facturacion->tipo_letra = $params->tipo_letra;
        $facturacion->fecha_vencimiento = $params->fecha_vencimiento;
        $facturacion->sucursal = $params->sucursal;
        $facturacion->numero = $params->numero;
        $facturacion->cae_cai = $params->cae_cai;
        $facturacion->id_tipo_imputacion_sintetizada = $params->id_tipo_imputacion_sintetizada;
        $facturacion->fecha_actualiza = $fechaActual;
        $facturacion->refacturacion = $params->refacturacion;
        $facturacion->subtotal = $params->subtotal;
        $facturacion->total_iva = $params->total_iva;
        $facturacion->total_neto = $params->total_neto;
        $facturacion->id_locatorio = $params->id_locatorio;
        $facturacion->observaciones_resumen = $params->observaciones_resumen;
        $facturacion->comprobante_relacionado = $params->comprobante_relacionado;
        $facturacion->tipo_hospital = $params->tipo_hospital ?? null;
        $facturacion->cod_hospital = $params->cod_hospital ?? null;
        
        $facturacion->fecha_registra = $params->fecha_registra;

        if (!is_null($nombre_archivo)) {
            $facturacion->archivo = $nombre_archivo;
        }
        $facturacion->update();

        return $facturacion;
    }

    public function findByUpdateDetalleFactura($detalle, $id_factura)
    {
        foreach ($detalle as $key) {
            if (empty($key->id_detalle)) {
                FacturacionDetalleEntity::create([
                    'id_articulo' => $key->id_articulo,
                    'cantidad' => $key->cantidad,
                    'precio_neto' => $key->precio_neto,
                    'iva' => $key->iva,
                    'subtotal' => $key->subtotal,
                    'monto_iva' => $key->monto_iva,
                    'total_importe' => $key->total_importe,
                    'id_factura' => $id_factura,
                    'id_tipo_iva' => $key->id_tipo_iva,
                    'observaciones' => $key->observaciones
                ]);
            } else {
                $detalle = FacturacionDetalleEntity::find($key->id_detalle);
                $detalle->id_articulo = $key->id_articulo;
                $detalle->cantidad = $key->cantidad;
                $detalle->precio_neto = $key->precio_neto;
                $detalle->iva = $key->iva;
                $detalle->subtotal = $key->subtotal;
                $detalle->monto_iva = $key->monto_iva;
                $detalle->total_importe = $key->total_importe;
                $detalle->id_tipo_iva = $key->id_tipo_iva;
                $detalle->observaciones = $key->observaciones;
                $detalle->update();
            }
        }
    }

    public function findByDeleteDetalleImpuestos($id_factura)
    {
        return DB::delete("DELETE FROM tb_facturacion_detalle_impuesto WHERE id_detalle_impuesto > 0 AND id_factura = ? ", [$id_factura]);
    }

    public function findByDeleteDetalleDescuentosFactura($id_factura)
    {
        return DB::delete("DELETE FROM tb_facturacion_detalle_descuento WHERE id_detalle_descuento > 0 AND id_factura = ? ", [$id_factura]);
    }

    public function findByIdFactura($id)
    {
        return FacturacionDatosEntity::with([
            'detalle.articulo',
            'detalle',
            'impuesto',
            'descuentos',
            'tipoFactura',
            'filial',
            'tipoComprobante',
            'tipoImputacion',
            'proveedor',
            'prestador',
            'comprobantes',
            'razonSocial',
            'opa.fechapagos',
            'historialAsientos' => function ($query) {
                $query->where('tipo_evento', 'ALTA')
                    ->where('es_contraasiento', false)
                    ->whereHas('asientoContable', fn($q) => $q->where('vigente', 'ACTIVO'))
                    ->with(['asientoContable.detalle.planCuenta']);
            }
        ])
            ->find($id);
    }

    public function findByDeleteId($id)
    {
        $factura = FacturacionDatosEntity::find($id);

        $filePath = 'public/facturacion/' . $factura->archivo;
        if (Storage::exists($filePath)) {
            Storage::delete($filePath);
        }

        DB::delete("DELETE FROM tb_facturacion_detalle WHERE id_detalle > 0 AND id_factura = ? ", [$factura->id_factura]);
        DB::delete("DELETE FROM tb_facturacion_detalle_descuento WHERE id_detalle_descuento > 0 AND id_factura = ? ", [$factura->id_factura]);
        DB::delete("DELETE FROM tb_facturacion_detalle_impuesto WHERE id_detalle_impuesto > 0 AND id_factura = ? ", [$factura->id_factura]);
        DB::delete("DELETE FROM tb_facturacion_detalle_comprobantes WHERE id_comprobante > 0 AND id_factura = ? ", [$factura->id_factura]);
        return $factura->delete();
    }

    public function findByNumeroFactura($numFactura)
    {
        return FacturacionDatosEntity::where('numero', $numFactura)->first();
    }

    public function findByExistsNumeroFactura($numFactura)
    {
        return FacturacionDatosEntity::where('numero', $numFactura)->exists();
    }

    public function findByUpdateEstado($factura, $estado)
    {
        $response = FacturacionDatosEntity::find($factura);
        $response->estado = $estado;
        $response->update();
        return $response;
    }
    public function findById($id)
    {
        return FacturacionDatosEntity::with(['proveedor'])->find($id);
    }

    public function findByUpdateFactusPagoId($id, $estadoPago)
    {
        $factus = FacturacionDatosEntity::find($id);
        if ($factus != null) {
            $factus->estado_pago = $estadoPago;
            $factus->update();
        }

        return $factus;
    }

    public function findByListDetalleArchivos($factura)
    {
        return FacturacionDetalleComprobantesEntity::where('id_factura', $factura)->get();
    }

    public function findByIdDetalleArchivo($id)
    {
        return FacturacionDetalleComprobantesEntity::find($id);
    }

    public function findByIdDeleteDetalleArchivo($id)
    {
        return FacturacionDetalleComprobantesEntity::find($id)->delete();
    }

    public function findByExistsFacturaPrestadorOrPrestador($params)
    {
        if (empty($params->id_proveedor)) {
            return FacturacionDatosEntity::where('numero', $params->numero)
                ->where('sucursal', $params->sucursal)
                ->where('id_prestador', $params->id_prestador)
                ->where('numero', $params->numero)
                ->where('periodo', $params->periodo)
                ->where('tipo_letra', $params->tipo_letra)
                ->where('estado', '!=', 4)
                ->exists();
        } else {
            return FacturacionDatosEntity::where('numero', $params->numero)
                ->where('sucursal', $params->sucursal)
                ->where('id_proveedor', $params->id_proveedor)
                ->where('numero', $params->numero)
                ->where('periodo', $params->periodo)
                ->where('tipo_letra', $params->tipo_letra)
                ->where('estado', '!=', 9)
                ->exists();
        }
    }
}
