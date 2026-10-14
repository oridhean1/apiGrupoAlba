<?php

namespace App\Http\Controllers;

use App\Exports\PadronComercialExport;
use App\Mail\NotificarUsuario;
use App\Models\afiliado\AfiliadoDetalleTipoPlanEntity;
use App\Models\PadronComercialModelo;
use App\Models\BajasAfiliadosModel;
use App\Models\afiliado\AfiliadoPadronEntity;
use App\Models\AuditoriaPadronModelo;
use App\Models\RelacionLaboralModelo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

class PadronComercialController extends Controller
{

    public function getPadronComercial()
    {
        $arrayPlan = [];
        $padron =  PadronComercialModelo::with(['Autorizacion', 'locatario', 'tipoParentesco', 'origen'])->where('id_parentesco', '=', '00')->limit(50)->orderBy('id', 'asc')->get();
        foreach ($padron as $file) {
            $familia = PadronComercialModelo::with(['Autorizacion', 'locatario', 'tipoParentesco', 'origen'])
                ->where('cuil_tit', '=', $file->cuil_tit)->where('id', '!=', $file->id)->get();
            foreach ($familia as $f_file) {
                $plan = AfiliadoDetalleTipoPlanEntity::with(['TipoPlan'])
                    ->where('id_padron', $f_file->dni)
                    ->get();
                $f_file->id_tipo_plan = $plan;
            }
            $plan = AfiliadoDetalleTipoPlanEntity::with(['TipoPlan'])
                ->where('id_padron', $file->dni)
                ->get();
            $file->id_tipo_plan = $plan;
            $file->familia = $familia;
        }
        return response()->json($padron, 200);
    }

    public function getPadronComercialEstado($id)
    {
        $padron =  PadronComercialModelo::with(['Autorizacion', 'locatario',  'tipoParentesco', 'origen'])->where('id_estado_autorizacion', $id)->get();
        foreach ($padron as $file) {
            $plan = AfiliadoDetalleTipoPlanEntity::with(['TipoPlan'])
                ->where('id_padron', $file->dni)
                ->get();
            $file["id_tipo_plan"] = $plan;
        }
        return response()->json($padron, 200);
    }

    public function getPadronComercialBaja($id)
    {
        if ($id == '3') {
            $query =  PadronComercialModelo::with(['Autorizacion', 'locatario', 'tipoParentesco', 'origen'])->get();
        } else {
            $query =  PadronComercialModelo::with(['Autorizacion', 'locatario', 'tipoParentesco', 'origen'])->where('activo', $id)->get();
        }

        foreach ($query as $file) {
            $plan = AfiliadoDetalleTipoPlanEntity::with(['TipoPlan'])
                ->where('id_padron', $file->dni)
                ->get();
            $file["id_tipo_plan"] = $plan;
        }
        return response()->json($query, 200);
    }

    public function getIDPadronComercial($id)
    {
        $arrayPlan = [];
        $arrayEmpresa = [];
        $arrayRelacion = [];
        $query = PadronComercialModelo::where('id', $id)->first();
        $plan = AfiliadoDetalleTipoPlanEntity::with(['TipoPlan'])->where('id_padron', $query->dni)->get();
        foreach ($plan as $id_plan) {
            array_push($arrayPlan, $id_plan);
        }
        $relacionLaboral = RelacionLaboralModelo::with(['relacionEmpresa'])->where('id_padron', $query->dni)->get();
        foreach ($relacionLaboral as $id_relacion) {
            array_push($arrayRelacion, $id_relacion->id_empresa);
            array_push($arrayEmpresa, $id_relacion);
        }
        $familia = PadronComercialModelo::where('cuil_tit', $query->cuil_tit)->where('id', '!=', $id)->get();
        $query['id_tipo_plan'] = $arrayPlan;
        $query['empresa'] = $arrayEmpresa;
        $query['id_empresa'] = $arrayRelacion;
        $query['familiar'] = $familia;
        return response()->json($query, 200);
    }

    public function UpdateEstadoPadron(Request $request)
    {
        $now = new \DateTime();
        $fecha = '1900-01-01 00:00:00';
        $query = PadronComercialModelo::where('id', $request->id)->first();
        if ($query->fe_baja == $fecha) {
            $fecha = $now->format('Y-m-d H:i:s');
        }
        $familia = AfiliadoPadronEntity::where('cuil_tit', $query->cuil_tit)->get();
        foreach ($familia as $array) {
            AfiliadoPadronEntity::where('id', $array->id)->update(['activo' => $request->activo, 'fe_baja' => $fecha]);
            PadronComercialModelo::where('dni', $array->dni)->update(['activo' => $request->activo, 'fe_baja' => $fecha]);
            User::where('documento', $array->dni)->update(['estado_cuenta' => $request->activo]);
        }
        return response()->json(['message' => 'Estado cambiado correctamente'], 200);
    }

    public function getLikePadronComercial(Request $request)
    {
        $query = PadronComercialModelo::with([
            'Autorizacion',
            'locatario',
            'tipoParentesco',
            'origen'
        ]);

        if (!empty($request->dni)) {
            $query->where(function ($q) use ($request) {
                $q->where('dni', 'like', $request->dni . '%')
                    ->orWhere('cuil_tit', 'like', '%' . $request->dni . '%')
                    ->orWhere('cuil_benef', 'like', '%' . $request->dni . '%')
                    ->orWhere('nombre', 'like', '%' . $request->dni . '%')
                    ->orWhere('apellidos', 'like', '%' . $request->dni . '%');
            });
        }

        if (!empty($request->id_estado_autorizacion)) {
            $query->whereHas('Autorizacion', function ($q) use ($request) {
                $q->where(
                    'id_estado_autorizacion',
                    $request->id_estado_autorizacion
                );
            });
        }

        if (!empty($request->persona)) {
            $query->where('id_usuario', $request->persona);
        }

        if (!empty($request->desde) && !empty($request->hasta)) {
            $query->whereBetween('fecha_carga', [
                $request->desde,
                $request->hasta
            ]);
        }

        $datos = $query->limit(50)->get();

        foreach ($datos as $file) {
            $familia = PadronComercialModelo::with(['Autorizacion', 'locatario', 'tipoParentesco', 'origen'])
                ->where('cuil_tit', '=', $file->cuil_tit)->where('id', '!=', $file->id)->get();
            foreach ($familia as $f_file) {
                $plan = AfiliadoDetalleTipoPlanEntity::with(['TipoPlan'])
                    ->where('id_padron', $f_file->dni)->get();
                $f_file->id_tipo_plan = $plan;
            }
            $plan = AfiliadoDetalleTipoPlanEntity::with(['TipoPlan'])
                ->where('id_padron', $file->dni)->get();
            $file->id_tipo_plan = $plan;
            $file->familia = $familia;
            $file->origen_tabla = 'comercial';
        }

        // R-00000570: al buscar por texto, sumar los afiliados que existen solo en Afiliaciones (tb_padron).
        // Se devuelven marcados con origen_tabla = 'afiliaciones' para que el front los muestre en solo lectura,
        // ya que su id no pertenece a tb_padron_comercial. Sin estado de autorizacion, no aplican a ese filtro.
        if (!empty($request->dni) && empty($request->id_estado_autorizacion)) {
            $queryAfiliaciones = AfiliadoPadronEntity::with(['tipoParentesco', 'origen'])
                ->where(function ($q) use ($request) {
                    $q->where('dni', 'like', $request->dni . '%')
                        ->orWhere('cuil_tit', 'like', '%' . $request->dni . '%')
                        ->orWhere('cuil_benef', 'like', '%' . $request->dni . '%')
                        ->orWhere('nombre', 'like', '%' . $request->dni . '%')
                        ->orWhere('apellidos', 'like', '%' . $request->dni . '%');
                })
                ->whereNotIn('dni', PadronComercialModelo::select('dni')->whereNotNull('dni'));

            if (!empty($request->persona)) {
                $queryAfiliaciones->where('id_usuario', $request->persona);
            }

            if (!empty($request->desde) && !empty($request->hasta)) {
                $queryAfiliaciones->whereBetween('fecha_carga', [
                    $request->desde,
                    $request->hasta
                ]);
            }

            $afiliaciones = $queryAfiliaciones->limit(50)->get();

            foreach ($afiliaciones as $file) {
                $familia = AfiliadoPadronEntity::with(['tipoParentesco', 'origen'])
                    ->where('cuil_tit', '=', $file->cuil_tit)->where('id', '!=', $file->id)->get();
                foreach ($familia as $f_file) {
                    $plan = AfiliadoDetalleTipoPlanEntity::with(['TipoPlan'])
                        ->where('id_padron', $f_file->dni)->get();
                    $f_file->id_tipo_plan = $plan;
                    $f_file->origen_tabla = 'afiliaciones';
                }
                $plan = AfiliadoDetalleTipoPlanEntity::with(['TipoPlan'])
                    ->where('id_padron', $file->dni)->get();
                $file->id_tipo_plan = $plan;
                $file->familia = $familia;
                $file->origen_tabla = 'afiliaciones';
            }

            $datos = $datos->concat($afiliaciones);
        }

        return response()->json($datos, 200);
    }

    public function getPadronComercialFamiliar($cuit_titular)
    {
        $files = [];
        $query = PadronComercialModelo::with(['Autorizacion', 'locatario', 'tipoParentesco', 'origen'])->where('cuil_tit', '=', "$cuit_titular")
            ->limit(50)
            ->get();
        foreach ($query as $file) {
            $plan = AfiliadoDetalleTipoPlanEntity::with('TipoPlan')->where('id_padron', $file->dni)->get();
            $fechaNacimiento = Carbon::parse($file->fe_nac);
            $fechaActual = Carbon::now();
            $diferencia = $fechaNacimiento->diff($fechaActual);
            $file->id_cpostal = $diferencia->y;
            $file['plan'] = $plan;
            array_push($files, $file);
        }
        return response()->json($files, 200);
    }

    public function postSavePadronComercial(Request $request)
    {
        $now = Carbon::now('America/Argentina/Buenos_Aires');
        $titular = json_decode($request->input('newComercial'));
        $familiar = json_decode($request->input('familiarComercial'));

        if ($titular->id != '') {
            try {

                $query = PadronComercialModelo::where('dni', $titular->dni)->first();
                $nombreTabla = (new PadronComercialModelo)->getTable();
                $nombresDeColumnas = Schema::getColumnListing($nombreTabla);
                foreach ($nombresDeColumnas as $nombreColumna) {
                    // Evitar error si alguna columna no existe en $titular
                    if (isset($titular->$nombreColumna) && $query->$nombreColumna != $titular->$nombreColumna) {

                        // Guardar solo los campos que cambiaron
                        $arrayUpdate[$nombreColumna] = $titular->$nombreColumna; // nuevo valor
                        $arrayAntes[$nombreColumna] = $query->$nombreColumna;     // valor anterior
                    }
                }
                $user = Auth::user();
                $ahora = json_encode($arrayUpdate);
                $antes = json_encode($arrayAntes);
                if ($ahora !== '[]') {
                    AuditoriaPadronModelo::create([
                        'fecha' => $now->format('Y-m-d H:i:s'),
                        'antes' =>  $antes,
                        'ahora' => $ahora,
                        'id_padron' => $query->dni,
                        'cod_usuario' => $user->cod_usuario,
                        'modulo' => 'COMERCIAL'
                    ]);
                }
                $query->cuil_tit = $titular->cuil_tit;
                $query->id_tipo_documento = $titular->id_tipo_documento;
                $query->dni = $titular->dni;
                $query->nombre = $titular->nombre;
                $query->apellidos = $titular->apellidos;
                $query->id_sexo = $titular->id_sexo;
                $query->id_estado_civil = $titular->id_estado_civil;
                $query->fe_nac = $titular->fe_nac;
                $query->id_nacionalidad = $titular->id_nacionalidad;
                $query->calle = $titular->calle;
                $query->numero = $titular->numero;
                $query->piso = $titular->piso;
                $query->depto = $titular->depto;
                $query->id_localidad = $titular->id_localidad;
                $query->id_partido = $titular->id_partido;
                $query->id_provincia = $titular->id_provincia;
                $query->telefono = $titular->telefono;
                $query->fe_alta = $titular->fe_alta;
                $query->id_usuario = $titular->id_usuario;
                $query->fecha_carga = $titular->fecha_carga;
                $query->id_tipo_beneficiario = $titular->id_tipo_beneficiario;
                $query->id_tipo_domicilio = $titular->id_tipo_domicilio;
                $query->email = $titular->email;
                $query->celular = $titular->celular;
                $query->fe_baja = $titular->fe_baja;
                $query->activo = $titular->activo;
                $query->id_cpostal = $titular->id_cpostal;
                $query->observaciones = $titular->observaciones;
                $query->id_tipo_carpeta = $titular->id_tipo_carpeta;
                $query->id_qr = $titular->id_qr;
                $query->id_supervisor = $titular->id_supervisor;
                $query->id_agente = $titular->id_agente;
                $query->id_regimen = $titular->id_regimen;
                $query->id_gerente = $titular->id_gerente;
                $query->id_gestoria = $titular->id_gestoria;
                $query->aporte = $titular->aporte;
                $query->clave_fiscal = $titular->clave_fiscal;
                $query->tramite = $titular->tramite;
                $query->id_comercial_caja = $titular->id_comercial_caja;
                $query->id_comercial_origen = $titular->id_comercial_origen;
                $query->observaciones_auditoria = $titular->observaciones_auditoria;
                $query->id_estado_autorizacion = $titular->id_estado_autorizacion;
                $query->id_locatario = $titular->id_locatario;
                $query->id_parentesco = $query->id_parentesco;
                $query->cuil_benef = $query->cuil_benef;
                $query->orden = $titular->orden;
                $query->discapacidad = $titular->discapacidad;
                $query->rnos_anterior = $titular->rnos_anterior;
                $query->save();
                if ($titular->id_estado_autorizacion == '1') {
                    $this->savePadron($query);
                }

                if (count($familiar) > 0) {
                    $response = $this->addNewFamilia($familiar, $titular);

                    if ($response instanceof \Illuminate\Http\JsonResponse) {
                        return $response;
                    }
                }

                foreach ($titular->empresa as $empresaData) {
                    $laboral = RelacionLaboralModelo::where('id_padron', $titular->dni)->where('id_empresa', '=', $empresaData->id_empresa)->first();
                    if ($laboral != '') {
                        RelacionLaboralModelo::where('id', $laboral->id)->update([
                            'fecha_alta_empresa' => $empresaData->fecha_ingreso,
                            'fecha_baja_empresa' => $empresaData->fecha_baja,
                            'id_usuario' => $titular->id_usuario
                        ]);
                    } else {
                        RelacionLaboralModelo::create([
                            'id_padron' => $titular->dni,
                            'id_empresa' => $empresaData->id_empresa,
                            'fecha_alta_empresa' => $empresaData->fecha_ingreso,
                            'fecha_baja_empresa' => $empresaData->fecha_baja,
                            'id_usuario' => $titular->id_usuario
                        ]);
                    }
                }

                if (count($titular->plan) > 0) {
                    AfiliadoDetalleTipoPlanEntity::where('id_padron', $titular->dni)->delete();
                    foreach ($titular->plan as $plan) {
                        AfiliadoDetalleTipoPlanEntity::create([
                            'fecha_alta' => $plan->fecha_alta,
                            'fecha_baja' => $plan->fecha_baja,
                            'id_tipo_plan' => $plan->id_tipo_plan,
                            'id_padron' => $titular->dni
                        ]);
                    }
                }
                DB::commit();
            } catch (\Throwable $th) {
                DB::rollBack();
                return response()->json(['message' => $th->getMessage()], 500);
            }

            $msg = 'Datos actualizados correctamente';
        } else {
            $dni = PadronComercialModelo::where('dni', $titular->dni)->orWhere('cuil_tit', $titular->cuil_tit)->first();
            if (!$dni) {
                $titularBuscar = PadronComercialModelo::where('id_parentesco', '00')->where('cuil_tit', $titular->cuil_tit)->first();
                if ($titularBuscar) {
                    return response()->json(['message' => 'Ya existe un titular en el grupo familiar'], 500);
                }
                $user = Auth::user();
                $titular->id_usuario = $user->cod_usuario;
                $newpadron = PadronComercialModelo::create([
                    'cuil_tit' => $titular->cuil_tit,
                    'id_tipo_documento' => $titular->id_tipo_documento,
                    'dni' => $titular->dni,
                    'nombre' => $titular->nombre,
                    'apellidos' => $titular->apellidos,
                    'id_sexo' => $titular->id_sexo,
                    'id_estado_civil' => $titular->id_estado_civil,
                    'fe_nac' => $titular->fe_nac,
                    'id_nacionalidad' => $titular->id_nacionalidad,
                    'calle' => $titular->calle,
                    'numero' => $titular->numero,
                    'piso' => $titular->piso,
                    'depto' => $titular->depto,
                    'id_localidad' => $titular->id_localidad,
                    'id_partido' => $titular->id_partido,
                    'id_provincia' => $titular->id_provincia,
                    'telefono' => $titular->telefono,
                    'fe_alta' => $titular->fe_alta,
                    'id_usuario' => $titular->id_usuario,
                    'fecha_carga' => $titular->fecha_carga,
                    'id_tipo_beneficiario' => $titular->id_tipo_beneficiario,
                    'id_tipo_domicilio' => $titular->id_tipo_domicilio,
                    'email' => $titular->email,
                    'celular' => $titular->celular,
                    'fe_baja' => $titular->fe_baja,
                    'activo' => $titular->activo,
                    'id_cpostal' => $titular->id_cpostal,
                    'observaciones' => $titular->observaciones,
                    'id_tipo_carpeta' => $titular->id_tipo_carpeta,
                    'id_qr' => $titular->id_qr,
                    'id_supervisor' => $titular->id_supervisor,
                    'id_agente' => $titular->id_agente,
                    'id_regimen' => $titular->id_regimen,
                    'id_gerente' => $titular->id_gerente,
                    'id_gestoria' => $titular->id_gestoria,
                    'aporte' => $titular->aporte,
                    'clave_fiscal' => $titular->clave_fiscal,
                    'tramite' => $titular->tramite,
                    'id_comercial_caja' => $titular->id_comercial_caja,
                    'id_comercial_origen' => $titular->id_comercial_origen,
                    'observaciones_auditoria' => $titular->observaciones_auditoria,
                    'id_estado_autorizacion' => $titular->id_estado_autorizacion,
                    'id_locatario' => $titular->id_locatario,
                    'id_parentesco' => '00',
                    'cuil_benef' => $titular->cuil_tit,
                    'orden' => $titular->orden,
                    'discapacidad' => $titular->discapacidad,
                    'rnos_anterior' => $titular->rnos_anterior,
                    //'numero_form' => $titular->numero_form,
                    //'id_locatorio_comercial'=>$titular->id_locatorio_comercial,
                    // 'id_baja_motivos' => ''
                ]);
                AuditoriaPadronModelo::create([
                    'fecha' => $now->format('Y-m-d H:i:s'),
                    'antes' =>  '-',
                    'ahora' => $newpadron->nombre . ' ' . $newpadron->apellidos,
                    'id_padron' => $newpadron->dni,
                    'cod_usuario' => $user->cod_usuario,
                    'modulo' => 'COMERCIAL',
                ]);
                if ($titular->id_estado_autorizacion == '1') {
                    $this->savePadron($newpadron);
                }

                if (count($familiar) > 0) {
                    $response = $this->addNewFamilia($familiar, $titular);

                    if ($response instanceof \Illuminate\Http\JsonResponse) {
                        return $response;
                    }
                }

                if (count($titular->empresa) > 0) {
                    foreach ($titular->empresa as $empresaData) {
                        RelacionLaboralModelo::create([
                            'id_padron' => $newpadron->dni,
                            'id_empresa' => $empresaData->id_empresa,
                            'fecha_alta_empresa' => $empresaData->fecha_ingreso,
                            'fecha_baja_empresa' => $empresaData->fecha_baja,
                            'id_usuario' => $titular->id_usuario
                        ]);
                    }
                }


                if (count($titular->plan) > 0) {
                    foreach ($titular->plan as $plan) {
                        AfiliadoDetalleTipoPlanEntity::create([
                            'fecha_alta' => $plan->fecha_alta,
                            'fecha_baja' => $plan->fecha_baja,
                            'id_tipo_plan' => $plan->id_tipo_plan,
                            'id_padron' => $newpadron->dni
                        ]);
                    }
                }
            } else {
                return response()->json(['message' => 'Ya existe un afiliado con el mismo número de documento ó el CUIL es de un titular ya registrado'], 500);
            }
            $msg = 'Datos registrados correctamente';
        }
        return response()->json(['message' => $msg], 200);
    }

    public function deletePadronComercial(Request $request)
    {
        $now = new \DateTime();
        $query = PadronComercialModelo::where('id', $request->id)->first();
        $user = Auth::user();
        if ($query->id_parentesco == '00') {
            $familiar = PadronComercialModelo::where('cuil_tit', $query->cuil_tit)->get();
            foreach ($familiar as $familia) {
                BajasAfiliadosModel::create([
                    'cuil_tit' => $familia->cuil_tit,
                    'cuil_benef' => $familia->cuil_benef,
                    'dni' => $familia->dni,
                    'nombres' => $familia->nombre,
                    'apellidos' => $familia->apellidos,
                    'fech_nac' => $familia->fech_nac,
                    'fech_eliminado' => $now->format('Y-m-d H:i:s'),
                    'id_usuario' => $user->cod_usuario
                ]);
                PadronComercialModelo::where('id', $familia->id)->delete();
                AfiliadoPadronEntity::where('dni', $familia->dni)->delete();
            }
            User::where('documento', $query->dni)->delete();
            return response()->json(['message' => 'Afiliado eliminado correctamente'], 200);
        } else {
            BajasAfiliadosModel::create([
                'cuil_tit' => $query->cuil_tit,
                'cuil_benef' => $query->cuil_benef,
                'dni' => $query->dni,
                'nombres' => $query->nombre,
                'apellidos' => $query->apellidos,
                'fech_nac' => $query->fech_nac,
                'fech_eliminado' => $now->format('Y-m-d H:i:s'),
                'id_usuario' => $user->cod_usuario
            ]);
            PadronComercialModelo::where('id', $request->id)->delete();
            AfiliadoPadronEntity::where('dni', $query->dni)->delete();
            return response()->json(['message' => 'Afiliado eliminado correctamente'], 200);
        }
        //PadronComercialModelo::where('id', $request->id)->delete();

    }

    public function savePadron($request)
    {
        $now = new \DateTime();
        $user = Auth::user();
        $dni = AfiliadoPadronEntity::with(['obrasocial', 'tipoParentesco', 'origen'])->where('dni', $request->dni)->first();
        if (!$dni) {
            $padron = AfiliadoPadronEntity::create([
                'cuil_tit' => $request->cuil_tit,
                'cuil_benef' => $request->cuil_benef,
                'id_tipo_documento' => $request->id_tipo_documento,
                'dni' => $request->dni,
                'nombre' => $request->nombre,
                'apellidos' => $request->apellidos,
                'id_sexo' => $request->id_sexo,
                'id_estado_civil' => $request->id_estado_civil,
                'fe_nac' => $request->fe_nac,
                'id_nacionalidad' => $request->id_nacionalidad,
                'calle' => $request->calle,
                'numero' => $request->numero,
                'piso' => $request->piso,
                'depto' => $request->depto,
                'id_localidad' => $request->id_localidad,
                'id_partido' => $request->id_partido,
                'id_provincia' => $request->id_provincia,
                'telefono' => $request->telefono,
                'fe_alta' => $request->fe_alta,
                'id_usuario' => $request->id_usuario,
                'fecha_carga' => $request->fecha_carga,
                'id_tipo_beneficiario' => $request->id_tipo_beneficiario,
                'id_situacion_de_revista' => '99',
                'id_tipo_domicilio' => $request->id_tipo_domicilio,
                'id_parentesco' => $request->id_parentesco,
                'email' => $request->email,
                'celular' => $request->celular,
                'fe_baja' => $request->fe_baja,
                'activo' => $request->activo,
                'id_estado_super' => 1,
                'id_cpostal' => $request->id_cpostal,
                'observaciones' => $request->observaciones,
                'id_delegacion' => '99',
                'domicilio_postal' => $request->domicilio_postal,
                'domicilio_laboral' => $request->domicilio_laboral,
                'id_locatario' => $request->id_locatario,
                'id_baja_motivos' => 0,
                'fech_aprobado' => $now->format('Y-m-d'),
                'fech_descarga' => null,
                'credencial' => 'Autorizado',
                'patologia' => 0,
                'medicacion' => 0,
                'file_dni' => '',
                'id_comercial_origen' => $request->id_comercial_origen,
                'id_comercial_caja' => $request->id_comercial_caja,
            ]);

            AuditoriaPadronModelo::create([
                'fecha' => $now->format('Y-m-d H:i:s'),
                'antes' =>  '-',
                'ahora' => $padron->nombre . ' ' . $padron->apellidos,
                'id_padron' => $padron->dni,
                'cod_usuario' => $user->cod_usuario,
                'modulo' => 'AFILIADO',
            ]);

            if ($request->id_parentesco == '00') {
                $query = User::where('documento', $request->dni)->first();
                if (!$query) {
                    User::create([
                        'nombre_apellidos' => $request->nombre . ' ' . $request->apellidos,
                        'documento' => $request->dni,
                        'telefono' => $request->telefono,
                        'direccion' => '',
                        'fecha_alta' => $request->fe_alta,
                        'estado_cuenta' => true,
                        'fecha_cambio_clave' => $request->fe_alta,
                        'email' => $request->dni,
                        'codigo_verificacion' => null,
                        'password' => bcrypt($request->dni),
                        'cod_perfil' => 25,
                        'actualizo_datos' => 0
                    ]);
                    //$afiliado = AfiliadoPadronEntity::with(['obrasocial', 'tipoParentesco', 'origen'])->where('dni', $request->dni)->first();
                    //Mail::mailer('gmail_padron')
                    //    ->to($afiliado->email)
                    //    ->send(new NotificarUsuario($afiliado));
                }
            }
        } else {
            $nombreTabla = (new AfiliadoPadronEntity)->getTable();
            $nombresDeColumnas = Schema::getColumnListing($nombreTabla);
            foreach ($nombresDeColumnas as $nombreColumna) {
                if ($dni->$nombreColumna != $request->$nombreColumna) {
                    // Guardar solo el campo si ha cambiado

                    //array con los nuevos datos
                    $arrayUpdate[] = array($nombreColumna => $dni->$nombreColumna);
                    //array con los datos anteriores
                    $arrayAntes[] = array($nombreColumna => $dni->$nombreColumna);
                }
            }
            $ahora = json_encode($arrayUpdate);
            $antes = json_encode($arrayAntes);
            if ($ahora !== '[]') {
                AuditoriaPadronModelo::create([
                    'fecha' => $now->format('Y-m-d H:i:s'),
                    'antes' =>  $antes,
                    'ahora' => $ahora,
                    'id_padron' => $dni->dni,
                    'cod_usuario' => $user->cod_usuario,
                    'modulo' => 'AFILIADO',
                ]);
            }
            $dni->cuil_tit = $request->cuil_tit;
            $dni->cuil_benef = $request->cuil_benef;
            $dni->id_tipo_documento = $request->id_tipo_documento;
            $dni->dni = $request->dni;
            $dni->nombre = $request->nombre;
            $dni->apellidos = $request->apellidos;
            $dni->id_sexo = $request->id_sexo;
            $dni->id_estado_civil = $request->id_estado_civil;
            $dni->fe_nac = $request->fe_nac;
            $dni->id_nacionalidad = $request->id_nacionalidad;
            $dni->calle = $request->calle;
            $dni->numero = $request->numero;
            $dni->piso = $request->piso;
            $dni->depto = $request->depto;
            $dni->id_localidad = $request->id_localidad;
            $dni->id_partido = $request->id_partido;
            $dni->id_provincia = $request->id_provincia;
            $dni->telefono = $request->telefono;
            $dni->fe_alta = $request->fe_alta;
            $dni->id_usuario = $request->id_usuario;
            $dni->fecha_carga = $request->fecha_carga;
            $dni->id_tipo_beneficiario = $request->id_tipo_beneficiario;
            $dni->id_tipo_domicilio = $request->id_tipo_domicilio;
            $dni->id_parentesco = $request->id_parentesco;
            $dni->email = $request->email;
            $dni->celular = $request->celular;
            $dni->fe_baja = $request->fe_baja;
            $dni->activo = $request->activo;
            $dni->id_cpostal = $request->id_cpostal;
            $dni->observaciones = $request->observaciones;
            $dni->domicilio_postal = $request->domicilio_postal;
            $dni->domicilio_laboral = $request->domicilio_laboral;
            $dni->id_locatario = $request->id_locatario;
            $dni->id_comercial_origen = $request->id_comercial_origen;
            $dni->id_comercial_caja = $request->id_comercial_caja;
            $dni->save();
        }
    }

    public function addNewFamilia($familiar, $titular)
    {

        foreach ($familiar as $familia) {
            if ($familia->id_parentesco == '00') {
                return response()->json([
                    'message' => 'No puede existir dos titulares en el grupo familiar'
                ], 500);
            }
            if ($familia->id != 'A') {
                $query = PadronComercialModelo::where('dni', $familia->dni)->first();
                $query->id_tipo_documento = $familia->id_tipo_documento;
                $query->dni = $familia->dni;
                $query->nombre = $familia->nombre;
                $query->apellidos = $familia->apellidos;
                $query->id_sexo = $familia->id_sexo;
                $query->id_estado_civil = $familia->id_estado_civil;
                $query->fe_nac = $familia->fe_nac;
                $query->id_nacionalidad = $familia->id_nacionalidad;
                $query->id_parentesco = $familia->id_parentesco;
                $query->cuil_benef = $familia->cuil_benef;
                $query->orden = $familia->orden;
                $query->discapacidad = $familia->discapacidad;
                $query->save();

                if ($titular->id_estado_autorizacion == '1') {
                    $this->savePadron($query);
                }
            } else {
                $query2 = PadronComercialModelo::where('dni', $familia->dni)->first();
                if (empty($query2)) {
                    $newFamilia = PadronComercialModelo::create([
                        'cuil_tit' => $titular->cuil_tit,
                        'id_tipo_documento' => $titular->id_tipo_documento,
                        'dni' => $familia->dni,
                        'nombre' => $familia->nombre,
                        'apellidos' => $familia->apellidos,
                        'id_sexo' => $familia->id_sexo,
                        'id_estado_civil' => $familia->id_estado_civil,
                        'fe_nac' => $familia->fe_nac,
                        'id_nacionalidad' => $titular->id_nacionalidad,
                        'calle' => $titular->calle,
                        'numero' => $titular->numero,
                        'piso' => $titular->piso,
                        'depto' => $titular->depto,
                        'id_localidad' => $titular->id_localidad,
                        'id_partido' => $titular->id_partido,
                        'id_provincia' => $titular->id_provincia,
                        'telefono' => $titular->telefono,
                        'fe_alta' => $titular->fe_alta,
                        'id_usuario' => $titular->id_usuario,
                        'fecha_carga' => $titular->fecha_carga,
                        'id_tipo_beneficiario' => $titular->id_tipo_beneficiario,
                        'id_tipo_domicilio' => $titular->id_tipo_domicilio,
                        'email' => $titular->email,
                        'celular' => $titular->celular,
                        'fe_baja' => $titular->fe_baja,
                        'activo' => $titular->activo,
                        'id_cpostal' => $titular->id_cpostal,
                        'observaciones' => $titular->observaciones,
                        'id_tipo_carpeta' => $titular->id_tipo_carpeta,
                        'id_qr' => $titular->id_qr,
                        'id_supervisor' => $titular->id_supervisor,
                        'id_agente' => $titular->id_agente,
                        'id_regimen' => $titular->id_regimen,
                        'id_gerente' => $titular->id_gerente,
                        'id_gestoria' => $titular->id_gestoria,
                        'aporte' => $titular->aporte,
                        'clave_fiscal' => $titular->clave_fiscal,
                        'tramite' => $titular->tramite,
                        'id_comercial_caja' => $titular->id_comercial_caja,
                        'id_comercial_origen' => $titular->id_comercial_origen,
                        'observaciones_auditoria' => $titular->observaciones_auditoria,
                        'id_estado_autorizacion' => $titular->id_estado_autorizacion,
                        'id_locatario' => $titular->id_locatario,
                        'id_parentesco' => $familia->id_parentesco,
                        'cuil_benef' => $familia->cuil_benef,
                        'orden' => $familia->orden,
                        'discapacidad' => $familia->discapacidad,
                        'rnos_anterior' => $titular->rnos_anterior,
                        //'numero_form' => $titular->numero_form,
                        //'id_locatorio_comercial' => $titular->id_locatorio_comercial,
                        // 'id_baja_motivos' => ''
                    ]);
                    if ($titular->id_estado_autorizacion == '1') {
                        $this->savePadron($newFamilia);
                    }

                    foreach ($titular->empresa as $empresaData) {
                        $laboral = RelacionLaboralModelo::where('id_padron', $newFamilia->dni)->where('id_empresa', '=', $empresaData->id_empresa)->first();
                        if ($laboral != '') {
                            RelacionLaboralModelo::where('id', $laboral->id)->update([
                                'fecha_alta_empresa' => $empresaData->fecha_ingreso,
                                'fecha_baja_empresa' => $empresaData->fecha_baja,
                                'id_usuario' => $titular->id_usuario
                            ]);
                        } else {
                            RelacionLaboralModelo::create([
                                'id_padron' => $newFamilia->dni,
                                'id_empresa' => $empresaData->id_empresa,
                                'fecha_alta_empresa' => $empresaData->fecha_ingreso,
                                'fecha_baja_empresa' => $empresaData->fecha_baja,
                                'id_usuario' => $titular->id_usuario
                            ]);
                        }
                    }

                    if (count($titular->plan) > 0) {
                        foreach ($titular->plan as $plan) {
                            AfiliadoDetalleTipoPlanEntity::create([
                                'fecha_alta' => $plan->fecha_alta,
                                'fecha_baja' => $plan->fecha_baja,
                                'id_tipo_plan' => $plan->id_tipo_plan,
                                'id_padron' => $familia->dni
                            ]);
                        }
                    }
                }
            }
        }
    }

    public function getDniPadronComercial(Request $request)
    {
        $query = PadronComercialModelo::where('dni', $request->dni)->first();
        return response()->json($query, 200);
    }

    public function exportPadronComercial(Request $request)
    {
        $user = Auth::user();
        if ($user->cod_perfil == 2 || $user->cod_perfil == 15) {
            return Excel::download(new PadronComercialExport($request), 'padronComercial.xlsx');
        } else {
            return response()->json(['message' => 'No tiene permisos para descargar'], 403);
        }
    }
}
