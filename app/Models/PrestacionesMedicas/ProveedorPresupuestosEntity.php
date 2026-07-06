<?php

namespace App\Models\PrestacionesMedicas;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProveedorPresupuestosEntity extends Model
{
    use HasFactory;
    protected $table = 'tb_presupuesto_proveedor';
    protected $primaryKey = 'id_presupuesto_proveedor';
    public $timestamps = false;

    protected $fillable = [
        'cuit',
        'razon_social',
        'email',
        'telefono',
        'observaciones',
        'id_usuario_crea',
        'fecha_alta',
        'fecha_baja',
        'activo'
    ];
}
