<?php

namespace  App\Models\Internaciones;

use App\Models\afiliado\AfiliadoPadronEntity;
use App\Models\PrestacionesMedicas\PrestacionesPracticaLaboratorioEntity;
use App\Models\PrestacionesMedicas\TipoEstadoPrestacionEntity;
use App\Models\prestadores\PrestadorEntity;
use App\Models\prestadores\PrestadorEspecialidadesMedicasEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InternacionesEntity extends Model
{
    use HasFactory;
    protected $table = 'tb_internaciones';
    protected $primaryKey = 'cod_internacion';
    public $timestamps = false;
    /* 'cod_profesional',  'cod_tipo_facturacion',*/
    protected $fillable = [
        'dni_afiliado',
        'fecha_internacion',
        'cod_prestador',
        'vigente',
        'cod_tipo_prestacion',
        'cod_tipo_internacion',
        'cod_tipo_habitacion',
        'cod_categoria_internacion',
        'cod_especialidad',
        'cod_tipo_egreso',
        'cod_tipo_diagnostico',
        'fecha_ingresa',
        'fecha_egreso',
        'cantidad_dias',
        'diagnostico_presuntivo',
        'tratamiento_indicado',
        'observaciones',
        'nombre_archivo',
        'cod_tipo_estado',
        'cod_usuario_registra',
        'edad_afiliado',
        'medico_prescribiente',
        'num_internacion',
        'cod_tipo_estado_detalle_prestacion',
        'hospital',
        'cod_hospital',
        'cerrar',
        'estado',
    ];

    public function prestador()
    {
        return $this->hasOne(PrestadorEntity::class, 'cod_prestador', 'cod_prestador');
    }

    /*    public function profesional()
    {
        return $this->hasOne(PrestadorMedicosEntity::class, 'cod_profesional', 'cod_profesional');
    } */
    public function tipoPrestacion()
    {
        return $this->hasOne(TipoPrestacionEntity::class, 'cod_tipo_prestacion', 'cod_tipo_prestacion');
    }
    public function tipoInternacion()
    {
        return $this->hasOne(TipoInternacionEntity::class, 'cod_tipo_internacion', 'cod_tipo_internacion');
    }
    public function tipoHabitacion()
    {
        return $this->hasOne(TipoHabitacionEntity::class, 'cod_tipo_habitacion', 'cod_tipo_habitacion');
    }
    public function afiliado()
    {
        return $this->hasOne(AfiliadoPadronEntity::class, 'dni', 'dni_afiliado');
    }
    public function categoria()
    {
        return $this->hasOne(CategoriaInternacionEntity::class, 'cod_categoria_internacion', 'cod_categoria_internacion');
    }
    /*  public function facturacion()
    {
        return $this->hasOne(TipoFacturacionInternacionEntity::class, 'cod_tipo_facturacion', 'cod_tipo_facturacion');
    } */
    public function especialidad()
    {
        return $this->hasOne(PrestadorEspecialidadesMedicasEntity::class, 'cod_especialidad', 'cod_especialidad');
    }
    public function tipoEgreso()
    {
        return $this->hasOne(TipoEgresoInternacionEntity::class, 'cod_tipo_egreso', 'cod_tipo_egreso');
    }
    public function tipoDiagnostico()
    {
        return $this->hasOne(TipoDiagnosticoInternacionEntity::class, 'cod_tipo_diagnostico', 'cod_tipo_diagnostico');
    }

    public function usuario()
    {
        return $this->hasOne(User::class, 'cod_usuario', 'cod_usuario_registra');
    }

    public function estadoPrestacion()
    {
        return $this->hasOne(TipoEstadoPrestacionEntity::class, 'cod_tipo_estado', 'cod_tipo_estado');
    }

    public function internacion()
    {
        return $this->hasOne(PrestacionesPracticaLaboratorioEntity::class, 'cod_internacion', 'cod_internacion');
    }

    // Todas las autorizaciones principales (1:N) — T-00000804
    public function prestaciones_principales()
    {
        return $this->hasMany(PrestacionesPracticaLaboratorioEntity::class, 'cod_internacion', 'cod_internacion');
    }

    public function autorizacion()
    {
        return $this->hasMany(InternacionAutorizacionEntity::class, 'cod_internacion', 'cod_internacion');
    }

    public function recien_nacido()
    {
        return $this->hasMany(RecienNacidoEntity::class, 'cod_internacion', 'cod_internacion');
    }
}
