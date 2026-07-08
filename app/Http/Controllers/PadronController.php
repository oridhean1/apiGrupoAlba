<?php

namespace App\Http\Controllers;

use App\Exports\PadronExport;
use App\Exports\PadronLiquidacionExport;
use App\Mail\NotificarUsuario;
use App\Models\afiliado\AfiliadoCertificadoEntity;
use App\Models\afiliado\AfiliadoDetalleTipoPlanEntity;
use App\Models\afiliado\AfiliadoEscolaridadEntity;
use App\Models\afiliado\AfiliadoPadronEntity;
use App\Models\Afip\DeclaracionesJuradasModelo;
use App\Models\Afip\TransferenciasModelo;
use App\Models\Internaciones\InternacionesNotasEntity;
use App\Models\PrestacionesMedicas\PrestacionesPracticaLaboratorioEntity;
use App\Models\AuditoriaPadronModelo;
use App\Models\BajasAfiliadosModel;
use App\Models\DetalleTipoDocAfiliadoModelo;
use App\Models\PadronComercialModelo;
use App\Models\RelacionLaboralModelo;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class PadronController extends Controller
{
    public function getPadron()
    {
        $padron = AfiliadoPadronEntity::with([
            'origen',
            'user',
            'tipoParentesco',
            'detalleplan.TipoPlan',  // relación en cascada
            'documentos'  // relación definida en el modelo
        ])
            ->where('activo', 1)
            ->orderByDesc('cuil_tit')
            ->limit(50)
            ->get()
            ->map(function ($file) {
                // Calcular edad con accessor
                $fechaNacimiento = Carbon::parse($file->fe_nac);
                $fechaActual = Carbon::now();
                $diferencia = $fechaNacimiento->diff($fechaActual);
                $file->edad = $diferencia->y;

                // Mapear documentos
                $file->detalle_doc = $file->documentos->map(function ($doc) {
                    return [
                        'id_detalle' => $doc->id_detalle,
                        'nombre_archivo' => $doc->nombre_archivo,
                        'url_archivo' => url('/storage/images/' . $doc->nombre_archivo),
                        'tipo_documentacion' => $doc->tipoDocumentacion->tipo_documentacion ?? null,  // Enviar el nombre del tipo de documentación
                        'fecha_carga' => $doc->fecha_carga,
                        'observaciones' => $doc->observacion
                    ];
                });

                return $file;
            });

        return response()->json($padron, 200);
    }

    public function counterDownload()
    {
        $padron = AfiliadoPadronEntity::with(['tipoParentesco', 'detallebaja', 'locatario'])
            ->where('fech_descarga', '!=', null)
            ->count();
        return response()->json(['counter' => $padron], 200);
    }

    public function listPadronDownloadCarnet()
    {
        $files = array();
        $padron = AfiliadoPadronEntity::with(['tipoParentesco', 'detallebaja', 'locatario'])
            ->where('fech_descarga', '!=', null)
            ->get();

        foreach ($padron as $file) {
            $plan = AfiliadoDetalleTipoPlanEntity::with(['TipoPlan'])
                ->where('id_padron', $file->dni)
                ->get();
            $fechaNacimiento = Carbon::parse($file->fe_nac);
            $fechaActual = Carbon::now();
            $diferencia = $fechaNacimiento->diff($fechaActual);
            $file->edad = $diferencia->y;
            $file->plan = $plan;
            array_push($files, $file);
        }
        return response()->json($files, 200);
    }

    public function getListPadronEstado($estado)
    {
        $user = Auth::user();
        $files = [];
        if ($user->cod_usuario == 23 || $user->cod_usuario == 25 || $user->cod_usuario == 2) {
            if ($estado == 4) {
                $padron = AfiliadoPadronEntity::join('tb_escolaridad', 'tb_padron.id', '=', 'tb_escolaridad.id_padron')
                    ->select('tb_padron.*', 'tb_escolaridad.nivel_estudio')
                    ->get();
            } else if ($estado == 5) {
                $padron = AfiliadoPadronEntity::join('tb_discapaciodad', 'tb_padron.id', '=', 'tb_discapaciodad.id_padron')
                    ->join('tb_tipo_discapacidad', 'tb_tipo_discapacidad.id_tipo_discapacidad', '=', 'tb_discapaciodad.id_tipo_discapacidad')
                    ->select('tb_padron.*', 'tb_tipo_discapacidad.tipo_discapacidad')
                    ->get();
            } else if ($estado == 6) {
                $padron = AfiliadoPadronEntity::join('tb_credencial', 'tb_padron.id', '=', 'tb_credencial.id_padron')
                    ->select('tb_padron.*', 'tb_credencial.num_carnet')
                    ->get();
            } else {
                $padron = AfiliadoPadronEntity::where('activo', '=', $estado)->get();
            }
        } else {
            return response()->json(['message' => 'No tiene permisos para realizar el filtro'], 403);
        }

        foreach ($padron as $file) {
            $plan = AfiliadoDetalleTipoPlanEntity::with('TipoPlan')->where('id_padron', $file->id)->get();
            $fechaNacimiento = Carbon::parse($file->fe_nac);
            $fechaActual = Carbon::now();
            $diferencia = $fechaNacimiento->diff($fechaActual);
            $file->id_cpostal = $diferencia->y;
            $file['plan'] = $plan;
            array_push($files, $file);
        }
        return response()->json($files, 200);
    }

    public function getPadronId($id)
    {
        $query = AfiliadoPadronEntity::with(['tipoParentesco', 'detalleplan', 'origen'])->where('id', $id)->first();
        if ($query) {
            $arrayPlan = [];
            $arrayRelacion = [];
            $arrayDoc = [];
            $arrayEmpresa = [];
            $plan = AfiliadoDetalleTipoPlanEntity::with(['TipoPlan'])->where('id_padron', $query->dni)->get();
            foreach ($plan as $id_plan) {
                array_push($arrayPlan, $id_plan);
            }
            $relacionLaboral = RelacionLaboralModelo::with(['relacionEmpresa'])->where('id_padron', $query->dni)->get();
            foreach ($relacionLaboral as $id_relacion) {
                array_push($arrayRelacion, $id_relacion->id_empresa);
                array_push($arrayEmpresa, $id_relacion);
            }

            $detalle_doc = DetalleTipoDocAfiliadoModelo::where('id_padron', $id)->get();
            foreach ($detalle_doc as $id_detalle) {
                array_push($arrayDoc, [
                    'id_detalle' => $id_detalle->id_detalle,
                    'nombre_archivo' => $id_detalle->nombre_archivo,
                    'url_archivo' => url('/storage/images/' . $id_detalle->nombre_archivo),
                    'id_tipo_documentacion' => $id_detalle->id_tipo_documentacion
                ]);
            }
            $query['id_tipo_plan'] = $arrayPlan;
            $query['id_empresa'] = $arrayRelacion;
            $query['tipo_doc'] = $arrayDoc;
            $query['laboral'] = $relacionLaboral;
            $query['empresa'] = $arrayEmpresa;
            return response()->json($query, 200);
        } else {
            return response()->json(['message' => 'No se encontró el registro'], 500);
        }
    }

    public function getLikePadron(Request $request)
    {
        $user = Auth::user();
        $query = AfiliadoPadronEntity::with([
            'origen',
            'user',
            'tipoParentesco',
            'detalleplan.tipoPlan',
            'documentos.tipoDocumentacion'  // Relación para obtener la descripción del tipo de documentación
        ]);
        $hayFiltro = false;

        if (!empty($request->dni)) {
            $hayFiltro = true;

            $afiliado = AfiliadoPadronEntity::where('dni', $request->dni)->first();

            if ($afiliado) {
                $query->where('cuil_tit', $afiliado->cuil_tit);
            } else {
                $query->where(function ($q) use ($request) {
                    $q
                        ->where('nombre', 'like', '%' . $request->dni . '%')
                        ->orWhere('apellidos', 'like', '%' . $request->dni . '%')
                        ->orWhere('cuil_tit', 'like', $request->dni . '%')
                        ->orWhere('cuil_benef', 'like', $request->dni . '%');
                });
            }
        }

        if ($user->cod_usuario == 23 || $user->cod_usuario == 25 || $user->cod_usuario == 2) {
            if (!empty($request->cuil)) {
                $hayFiltro = true;
                $query->where('cuil_tit', 'like', '%' . $request->cuil . '%');
            }

            if (!empty($request->id_comercial_caja)) {
                $hayFiltro = true;
                $query->where('id_comercial_caja', $request->id_comercial_caja);
            }

            if (!empty($request->persona)) {
                $hayFiltro = true;
                $query->where('id_usuario', $request->persona);
            }

            if (!empty($request->desde) && !empty($request->hasta)) {
                $hayFiltro = true;
                $query->whereBetween('fecha_carga', [$request->desde, $request->hasta]);
            }
        }

        if (!$hayFiltro) {
            return response()->json([], 200);
        }
        $files = $query
            ->limit(50)
            ->get()
            ->map(function ($file) {
                // Edad (años)
                $fechaNacimiento = Carbon::parse($file->fe_nac);
                $fechaActual = Carbon::now();
                $diferencia = $fechaNacimiento->diff($fechaActual);
                $file->edad = $diferencia->y;

                // Normalizar planes
                $file->plan = $file->planes;

                // Normalizar documentos
                $file->detalle_doc = $file->documentos->map(function ($doc) {
                    return [
                        'id_detalle' => $doc->id_detalle,
                        'nombre_archivo' => $doc->nombre_archivo,
                        'url_archivo' => url('/storage/images/' . $doc->nombre_archivo),
                        'tipo_documentacion' => $doc->tipoDocumentacion->tipo_documentacion ?? null,  // Enviar el nombre del tipo de documentación
                        'fecha_carga' => $doc->fecha_carga,
                        'observaciones' => $doc->observacion
                    ];
                });

                // Quitar relaciones crudas si no quieres duplicar
                unset($file->planes, $file->documentos);

                return $file;
            });

        return response()->json($files, 200);
    }

    public function getPadronFamiliar($cuit_titular)
    {
        $files = [];
        $query = AfiliadoPadronEntity::with(['tipoParentesco', 'origen'])
            ->where('cuil_tit', '=', "$cuit_titular")
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

    public function getUserDni()
    {
        $user = Auth::user();
        $arrayRelacion = [];
        $query = AfiliadoPadronEntity::with('detalleplan.TipoPlan')->where('dni', $user->documento)->first();
        if ($query) {
            $relacionLaboral = RelacionLaboralModelo::with(['relacionEmpresa'])
                ->where('id_padron', $query->dni)
                ->where('fecha_baja_empresa', '=', '1900-01-01')
                ->get();

            $fechaNacimiento = Carbon::parse($query->fe_nac);
            $fechaActual = Carbon::now();
            $diferencia = $fechaNacimiento->diff($fechaActual);
            $query->edad = $diferencia->y;
            $query->laboral = $relacionLaboral;
            return response()->json($query, 200);
        } else {
            return response()->json(['message' => 'No se encontró el registro con el numero DNI ingresado'], 500);
        }
    }

    public function getDniPadron($dni)
    {
        $query = AfiliadoPadronEntity::with(['detalleplan.TipoPlan', 'transaccion'])->where('dni', $dni)->first();
        if ($query) {
            $fechaNacimiento = Carbon::parse($query->fe_nac);
            $fechaActual = Carbon::now();
            $diferencia = $fechaNacimiento->diff($fechaActual);
            $query->fe_nac = $diferencia->y;
            return response()->json($query, 200);
        } else {
            return response()->json(['message' => 'No se encontró el registro con el numero DNI ingresado'], 500);
        }
    }

    public function getFechaPadron(Request $request)
    {
        $query = AfiliadoPadronEntity::whereBetween('fecha_carga', [$request->desde, $request->hasta])
            ->get();
        return response()->json($query, 200);
    }

    public function UpdateEstadoPadron(Request $request)
    {
        $now = Carbon::now('America/Argentina/Buenos_Aires');
        $query = AfiliadoPadronEntity::with('detalleplan.addplan', 'tipoParentesco', 'obrasocial')->where('dni', $request->id)->first();

        $familia = AfiliadoPadronEntity::where('cuil_tit', $query->cuil_tit)->get();
        if ($request->insertar == 'BAJA') {
            if ($request->fe_baja <= $now) {
                $request->activo = 0;
            } else {
                $request->activo = 1;
            }
            if ($query->id_parentesco != '00') {
                $arrayUpdate[] = array('fe_baja' => $request->fe_baja, 'id_baja_motivos' => $request->id_baja_motivos, 'activo' => $request->activo, 'observaciones' => $request->observaciones);
                $arrayAntes[] = array('fe_baja' => $query->fe_baja, 'id_baja_motivos' => $query->id_baja_motivos, 'activo' => $query->activo, 'observaciones' => $query->observaciones);
                $user = Auth::user();
                $ahora = json_encode($arrayUpdate);
                $antes = json_encode($arrayAntes);
                if ($ahora !== '[]') {
                    AuditoriaPadronModelo::create([
                        'fecha' => $now->format('Y-m-d H:i:s'),
                        'antes' => $antes,
                        'ahora' => $ahora,
                        'id_padron' => $query->dni,
                        'cod_usuario' => $user->cod_usuario,
                        'modulo' => 'AFILIADO',
                    ]);
                }
                AfiliadoPadronEntity::where('id', $query->id)->update(['activo' => $request->activo, 'fe_baja' => $request->fe_baja, 'id_baja_motivos' => $request->id_baja_motivos, 'observaciones' => $request->observaciones]);
                PadronComercialModelo::where('dni', $query->dni)->update(['activo' => $request->activo, 'fe_baja' => $request->fe_baja, 'observaciones' => $request->observaciones]);
            } else {
                foreach ($familia as $array) {
                    $arrayUpdate[] = array('fe_baja' => $request->fe_baja, 'id_baja_motivos' => $request->id_baja_motivos, 'activo' => $request->activo, 'observaciones' => $request->observaciones);
                    $arrayAntes[] = array('fe_baja' => $array->fe_baja, 'id_baja_motivos' => $array->id_baja_motivos, 'activo' => $array->activo, 'observaciones' => $array->observaciones);
                    $user = Auth::user();
                    $ahora = json_encode($arrayUpdate);
                    $antes = json_encode($arrayAntes);
                    if ($ahora !== '[]') {
                        AuditoriaPadronModelo::create([
                            'fecha' => $now->format('Y-m-d H:i:s'),
                            'antes' => $antes,
                            'ahora' => $ahora,
                            'id_padron' => $array->dni,
                            'cod_usuario' => $user->cod_usuario,
                            'modulo' => 'AFILIADO',
                        ]);
                    }
                    AfiliadoPadronEntity::where('id', $array->id)->update(['activo' => $request->activo, 'fe_baja' => $request->fe_baja, 'id_baja_motivos' => $request->id_baja_motivos, 'observaciones' => $request->observaciones]);
                    PadronComercialModelo::where('dni', $array->dni)->update(['activo' => $request->activo, 'fe_baja' => $request->fe_baja, 'observaciones' => $request->observaciones]);
                    User::where('documento', $array->dni)->update(['estado_cuenta' => $request->activo]);
                }
            }
        } elseif ($request->insertar == 'ALTA') {
            foreach ($familia as $array) {
                $arrayUpdate[] = array(
                    'fe_alta' => $request->fe_alta,
                    'id_comercial_caja' => $request->id_comercial_caja,
                    'id_comercial_origen' => $request->id_comercial_origen,
                    'observaciones' => $request->observaciones,
                    'activo' => $request->activo,
                    'fe_baja' => null,
                );
                $arrayAntes[] = array(
                    'fe_alta' => $array->fe_alta,
                    'id_comercial_caja' => $array->id_comercial_caja,
                    'id_comercial_origen' => $array->id_comercial_origen,
                    'observaciones' => $array->observaciones,
                    'activo' => $array->activo,
                    'fe_baja' => $array->fe_baja,
                );

                $user = Auth::user();
                $ahora = json_encode($arrayUpdate);
                $antes = json_encode($arrayAntes);
                if ($ahora !== '[]') {
                    AuditoriaPadronModelo::create([
                        'fecha' => $now->format('Y-m-d H:i:s'),
                        'antes' => $antes,
                        'ahora' => $ahora,
                        'id_padron' => $array->dni,
                        'cod_usuario' => $user->cod_usuario,
                        'modulo' => 'AFILIADO',
                    ]);
                }
                AfiliadoPadronEntity::where('id', $array->id)->update([
                    'activo' => $request->activo,
                    'fe_alta' => $request->fe_alta,
                    'id_comercial_caja' => $request->id_comercial_caja,
                    'id_comercial_origen' => $request->id_comercial_origen,
                    'observaciones' => $request->observaciones,
                    'fe_baja' => null,
                ]);
                PadronComercialModelo::where('dni', $array->dni)->update([
                    'activo' => $request->activo,
                    'fe_alta' => $request->fe_alta,
                    'id_comercial_caja' => $request->id_comercial_caja,
                    'id_comercial_origen' => $request->id_comercial_origen,
                    'observaciones' => $request->observaciones,
                    'fe_baja' => null,
                ]);
                User::where('documento', $array->dni)->update(['estado_cuenta' => $request->activo]);
            }
        }

        if ($request->activo == 0) {
            $query = AfiliadoPadronEntity::with('detalleplan.addplan', 'tipoParentesco', 'obrasocial')->where('dni', $request->id)->first();
            $pdf = Pdf::loadView('baja_afiliado', ['padron' => $query]);
            $pdf->setPaper('A4');
            return response($pdf->output(), 200)
                ->header('Content-Type', 'application/pdf');
        } else {
            return response()->json(['message' => 'Estado cambiado correctamente'], 200);
        }
    }

    public function postSavePadron(Request $request)
    {
        $now = Carbon::now('America/Argentina/Buenos_Aires');
        $arrayUpdate = array();
        $arrayAntes = array();
        $arrayEmpresa = explode(',', $request->id_empresa);
        $titular = json_decode($request->input('newComercial'));
        $familiar = json_decode($request->input('familiarComercial'));
        // return response()->json($titular->empresa,200);
        if ($titular->fe_alta > $now->format('Y-m-d')) {
            $titular->activo = 0;
        }
        if ($titular->id != '') {
            try {
                DB::beginTransaction();
                $nombreTabla = (new AfiliadoPadronEntity)->getTable();
                $nombresDeColumnas = Schema::getColumnListing($nombreTabla);

                $query = AfiliadoPadronEntity::where('id', $titular->id)->first();
                /* if ($query->id_parentesco == '00') {
                    $select_familiar = AfiliadoPadronEntity::where('cuil_tit', $query->cuil_tit)->get();
                    foreach ($select_familiar as $familia) {
                        $familia->domicilio_postal = $titular->domicilio_postal;
                        $familia->domicilio_laboral = $titular->domicilio_laboral;
                        $familia->telefono = $titular->telefono;
                        $familia->celular = $titular->celular;
                        $familia->save();
                        if (count($titular->plan) > 0) {
                            AfiliadoDetalleTipoPlanEntity::where('id_padron', $familia->dni)->delete();
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
                } */

                foreach ($nombresDeColumnas as $nombreColumna) {
                    // Evitar error si alguna columna no existe en $titular
                    if (isset($titular->$nombreColumna) && $query->$nombreColumna != $titular->$nombreColumna) {
                        // Guardar solo los campos que cambiaron
                        $arrayUpdate[$nombreColumna] = $titular->$nombreColumna;  // nuevo valor
                        $arrayAntes[$nombreColumna] = $query->$nombreColumna;  // valor anterior
                    }
                }
                $user = Auth::user();
                $ahora = json_encode($arrayUpdate);
                $antes = json_encode($arrayAntes);
                if ($ahora !== '[]') {
                    AuditoriaPadronModelo::create([
                        'fecha' => $now->format('Y-m-d H:i:s'),
                        'antes' => $antes,
                        'ahora' => $ahora,
                        'id_padron' => $query->dni,
                        'cod_usuario' => $user->cod_usuario,
                        'modulo' => 'AFILIADO',
                    ]);
                }

                $query->cuil_tit = $titular->cuil_tit;
                $query->cuil_benef = $titular->cuil_benef;
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
                $query->id_situacion_de_revista = $titular->id_situacion_de_revista;
                $query->id_tipo_domicilio = $titular->id_tipo_domicilio;
                $query->id_parentesco = $titular->id_parentesco;
                $query->email = $titular->email;
                $query->celular = $titular->celular;
                $query->fe_baja = $titular->fe_baja;
                $query->id_estado_super = $titular->id_estado_super;
                $query->id_cpostal = $titular->id_cpostal;
                $query->observaciones = $titular->observaciones;
                $query->id_delegacion = $titular->id_delegacion;
                $query->domicilio_postal = $titular->domicilio_postal;
                $query->domicilio_laboral = $titular->domicilio_laboral;
                $query->id_locatario = $titular->id_locatario;
                $query->extracapita = $titular->extracapita;
                $query->id_baja_motivos = $titular->id_baja_motivos;
                $query->credencial = $query->credencial;
                $query->id_comercial_origen = $titular->id_comercial_origen;
                $query->id_comercial_caja = $titular->id_comercial_caja;
                $query->discapacidad = $titular->discapacidad;
                $query->orden_grupo = $titular->orden_grupo;
                $query->save();
                $this->postSavePadronComercial($titular);
                // AfiliadoDetalleTipoPlanEntity::where('id_padron', $request->id)->delete();
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

                if (count($titular->empresa) > 0) {
                    RelacionLaboralModelo::where('id_padron', $titular->dni)->delete();
                    foreach ($titular->empresa as $empresaData) {
                        RelacionLaboralModelo::create([
                            'id_padron' => $titular->dni,
                            'id_empresa' => $empresaData->id_empresa,
                            'fecha_alta_empresa' => $empresaData->fecha_ingreso,
                            'fecha_baja_empresa' => $empresaData->fecha_baja ?? null,
                            'id_usuario' => $titular->id_usuario
                        ]);
                    }
                }

                if ($request->hasFile('files')) {
                    foreach ($request->file('files') as $index => $file) {
                        $fileName = time() . $index . '.' . $file->extension();
                        $file->storeAs('images', $fileName, 'public');
                        DetalleTipoDocAfiliadoModelo::create([
                            'nombre_archivo' => $fileName,
                            'id_padron' => $titular->id,
                            'id_tipo_documentacion' => $request->id_tipo_doc[$index]
                        ]);
                    }
                }
                if ($query->id_parentesco == '00') {
                    $this->updateDetallesAfiliados($query);
                }

                DB::commit();
                $msg = 'Datos actualizados correctamente';
            } catch (\Throwable $th) {
                DB::rollBack();
                return response()->json(['message' => $th->getMessage()], 500);
            }
        } else {
            try {
                DB::beginTransaction();
                $dni = AfiliadoPadronEntity::where('dni', $titular->dni)->first();

                if (!$dni) {
                    $user = Auth::user();
                    $titular->id_usuario = $user->cod_usuario;
                    $padron = AfiliadoPadronEntity::create([
                        'cuil_tit' => $titular->cuil_tit,
                        'cuil_benef' => $titular->cuil_benef,
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
                        'id_situacion_de_revista' => '99',
                        'id_tipo_domicilio' => $titular->id_tipo_domicilio,
                        'id_parentesco' => $titular->id_parentesco,
                        'email' => $titular->email,
                        'celular' => $titular->celular,
                        'fe_baja' => $titular->fe_baja,
                        'activo' => $titular->activo,
                        'id_estado_super' => 1,
                        'id_cpostal' => $titular->id_cpostal,
                        'observaciones' => $titular->observaciones,
                        'id_delegacion' => '99',
                        'domicilio_postal' => $titular->domicilio_postal,
                        'domicilio_laboral' => $titular->domicilio_laboral,
                        'id_locatario' => $titular->id_locatario,
                        'id_baja_motivos' => null,
                        'fech_aprobado' => $now->format('Y-m-d'),
                        'fech_descarga' => null,
                        'credencial' => 'Autorizado',
                        'patologia' => 0,
                        'medicacion' => 0,
                        'file_dni' => '',
                        'id_comercial_origen' => $titular->id_comercial_origen,
                        'id_comercial_caja' => $titular->id_comercial_caja,
                        'discapacidad' => $titular->discapacidad,
                        'orden_grupo' => $titular->orden_grupo,
                    ]);
                    AuditoriaPadronModelo::create([
                        'fecha' => $now->format('Y-m-d H:i:s'),
                        'antes' => '-',
                        'ahora' => $padron->nombre . ' ' . $padron->apellidos,
                        'id_padron' => $padron->dni,
                        'cod_usuario' => $user->cod_usuario,
                        'modulo' => 'AFILIADO',
                    ]);
                    if (count($titular->empresa) > 0) {
                        foreach ($titular->empresa as $empresaData) {
                            RelacionLaboralModelo::create([
                                'id_padron' => $padron->dni,
                                'id_empresa' => $empresaData->id_empresa,
                                'fecha_alta_empresa' => $empresaData->fecha_ingreso,
                                'fecha_baja_empresa' => $empresaData->fecha_baja,
                                'id_usuario' => $user->cod_usuario
                            ]);
                        }
                    }

                    if (count($titular->plan) > 0) {
                        foreach ($titular->plan as $plan) {
                            AfiliadoDetalleTipoPlanEntity::create([
                                'fecha_alta' => $plan->fecha_alta,
                                'fecha_baja' => $plan->fecha_baja,
                                'id_tipo_plan' => $plan->id_tipo_plan,
                                'id_padron' => $padron->dni
                            ]);
                        }
                    }

                    if ($request->hasFile('files')) {
                        foreach ($request->file('files') as $index => $file) {
                            $fileName = time() . $index . '.' . $file->extension();
                            $file->storeAs('images', $fileName, 'public');
                            DetalleTipoDocAfiliadoModelo::create([
                                'nombre_archivo' => $fileName,
                                'id_padron' => $padron->id,
                                'id_tipo_documentacion' => $request->id_tipo_doc[$index]
                            ]);
                        }
                    }
                    if ($titular->id_parentesco == '00') {
                        $query = User::where('documento', $titular->dni)->first();
                        if (!$query) {
                            User::create([
                                'nombre_apellidos' => $titular->nombre . ' ' . $titular->apellidos,
                                'documento' => $titular->dni,
                                'telefono' => $titular->telefono,
                                'direccion' => '',
                                'fecha_alta' => $titular->fe_alta,
                                'estado_cuenta' => true,
                                'fecha_cambio_clave' => $titular->fe_alta,
                                'email' => $titular->dni,
                                'codigo_verificacion' => null,
                                'password' => bcrypt($titular->dni),
                                'cod_perfil' => 25,
                                'actualizo_datos' => 0
                            ]);
                            // $afiliado = AfiliadoPadronEntity::with(['obrasocial', 'tipoParentesco', 'origen'])->where('dni', $padron->dni)->first();
                            // Mail::to($afiliado->email)->send(new NotificarUsuario($afiliado));
                        }
                    }
                    $msg = 'Datos registrados correctamente';
                } else {
                    return response()->json(['message' => 'Ya existe un afiliado con el mismo número de documento'], 500);
                }
                DB::commit();
            } catch (\Throwable $th) {
                DB::rollBack();
                return response()->json(['message' => $th->getMessage()], 500);
            }
        }
        return response()->json(['message' => $msg], 200);
    }

    public function deletePadron(Request $request)
    {
        $now = new \DateTime();
        $user = Auth::user();
        $query = AfiliadoPadronEntity::where('id', $request->id)->first();

        if ($query->id_parentesco == '00') {
            $familiar = AfiliadoPadronEntity::where('cuil_tit', $query->cuil_tit)->get();
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
                PadronComercialModelo::where('dni', $familia->dni)->delete();
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
            PadronComercialModelo::where('dni', $request->dni)->delete();
            AfiliadoPadronEntity::where('dni', $query->dni)->delete();
            return response()->json(['message' => 'Afiliado eliminado correctamente'], 200);
        }
    }

    public function getApiDniPadron($dni)
    {
        $query = AfiliadoPadronEntity::where('dni', $dni)->first();
        if ($query != '') {
            return response()->json(['message' => 'El número de documento ya se encuentra registrado en la base de datos'], 500);
        }
        try {
            $client = new Client();
            $response = $client->get('http://179.43.125.22/HC/Api/Api/Values/ConsultaCiudadano?dni=' . $dni . '&sexo=');
            $data = json_decode($response->getBody(), true);
            return response()->json($data);
        } catch (ServerException $e) {
            // throw $th;
            return response()->json(['message' => 'El número de documento no existe en la base de datos, llenar los campos manualmente'], 500);
        }
    }

    public function getDetalleTipoPlanPadron($id)
    {
        $auditoria = AfiliadoDetalleTipoPlanEntity::with('TipoPlan')->where('id_padron', $id)->get();
        return response()->json($auditoria, 200);
    }

    public function getIdTipoPlanPadron($id)
    {
        $plan = AfiliadoDetalleTipoPlanEntity::where('id', $id)->first();
        return response()->json($plan, 200);
    }

    public function exportPadron(Request $request)
    {
        $user = Auth::user();
        if ($user->cod_usuario == 23 || $user->cod_usuario == 25) {
            if ($request->tipo == '1') {
                return Excel::download(new PadronLiquidacionExport, 'padron.xlsx');
            } else {
                return Excel::download(new PadronExport($request->tipoPadron), 'padron.xlsx');
            }
        } else {
            return response()->json(['message' => 'No tiene permisos para descargar'], 403);
        }
    }

    public function postActualizarUser(Request $request)
    {
        $query = AfiliadoPadronEntity::where('id', $request->id)->first();
        if ($query != '') {
            $query->cuil_benef = $request->cuil_benef;
            $query->nombre = $request->nombre;
            $query->apellidos = $request->apellidos;
            $query->fe_nac = $request->fe_nac;
            $query->celular = $request->celular;
            $query->email = $request->email;
            $query->domicilio_laboral = $request->domicilio_laboral;
            $query->id_provincia = $request->id_provincia;
            $query->id_partido = $request->id_partido;
            $query->id_localidad = $request->id_localidad;
            $query->patologia = $request->patologia;
            $query->descripcion_patologia = $request->descripcion_patologia;
            $query->medicacion = $request->medicacion;
            $query->descripcion_medicacion = $request->descripcion_medicacion;
            $query->credencial = $request->credencial;
            $query->save();
            if ($query) {
                $user = Auth::user();
                User::where('cod_usuario', $user->cod_usuario)->update(['actualizo_datos' => 1]);
            }
            return response()->json(['message' => 'Muchas gracias por la actualización de sus datos.
            Se ha generado una solicitud para la emisión de su credencial, en el lapso de 24 a 48 hs será habilitada su credencial.'], 200);
        } else {
            return response()->json(['message' => 'Datos no encontrados'], 500);
        }
    }

    public function getListPadroncredencial($estado)
    {
        $files = [];
        if ($estado == 'Pendiente') {
            $padron = AfiliadoPadronEntity::where('credencial', 'Pendiente')->limit(50)->get();
            foreach ($padron as $file) {
                $plan = AfiliadoDetalleTipoPlanEntity::with(['TipoPlan'])
                    ->where('id_padron', $file->id)
                    ->get();
                $fechaNacimiento = Carbon::parse($file->fe_nac);
                $fechaActual = Carbon::now();
                $diferencia = $fechaNacimiento->diff($fechaActual);
                $file->id_cpostal = $diferencia->y;
                $file->email = $plan;

                array_push($files, $file);
            }
            return response()->json($files, 200);
        } else if ($estado == 'Rechazado') {
            $padron = AfiliadoPadronEntity::where('credencial', 'Rechazado')->limit(50)->get();
            foreach ($padron as $file) {
                $plan = AfiliadoDetalleTipoPlanEntity::with(['TipoPlan'])
                    ->where('id_padron', $file->id)
                    ->get();
                $fechaNacimiento = Carbon::parse($file->fe_nac);
                $fechaActual = Carbon::now();
                $diferencia = $fechaNacimiento->diff($fechaActual);
                $file->id_cpostal = $diferencia->y;
                $file->email = $plan;

                array_push($files, $file);
            }
            return response()->json($files, 200);
        } else if ($estado == 'Autorizado') {
            $padron = AfiliadoPadronEntity::where('credencial', 'Autorizado')->limit(50)->get();
            foreach ($padron as $file) {
                $plan = AfiliadoDetalleTipoPlanEntity::with(['TipoPlan'])
                    ->where('id_padron', $file->id)
                    ->get();
                $fechaNacimiento = Carbon::parse($file->fe_nac);
                $fechaActual = Carbon::now();
                $diferencia = $fechaNacimiento->diff($fechaActual);
                $file->id_cpostal = $diferencia->y;
                $file->email = $plan;

                array_push($files, $file);
            }
            return response()->json($files, 200);
        }
    }

    public function UpdateEstadoCredencial(Request $request)
    {
        AfiliadoPadronEntity::where('id', $request->id)->update(['credencial' => $request->credencial]);
        return response()->json(['message' => 'El afiliado fue ' . $request->credencial . ' con éxito'], 200);
    }

    public function postSavePadronComercial($titular)
    {
        $query = PadronComercialModelo::where('dni', $titular->dni)->first();
        if ($query) {
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
            $query->id_comercial_caja = $titular->id_comercial_caja;
            $query->id_comercial_origen = $titular->id_comercial_origen;
            $query->id_locatario = $titular->id_locatario;
            $query->id_parentesco = $query->id_parentesco;
            $query->save();
        }
    }

    public function getDatosUserDashboar(Request $request)
    {
        $files = [];
        $titular = AfiliadoPadronEntity::with(['tipoParentesco', 'origen', 'obrasocial', 'caja', 'baja'])->where('dni', $request->dni)->first();
        if (!$titular) {
            return response()->json(['message' => 'Datos no encontrados'], 500);
        }
        $query = AfiliadoPadronEntity::with(['tipoParentesco', 'origen', 'obrasocial', 'caja', 'baja'])->where('cuil_tit', $titular->cuil_tit)->get();
        $notas = InternacionesNotasEntity::with(['usuario'])->where('dni_afiliado', $titular->dni)->get();
        $prestaciones = PrestacionesPracticaLaboratorioEntity::with(['detalle', 'detalle.practica', 'estadoPrestacion', 'afiliado', 'afiliado.obrasocial', 'usuario', 'prestador', 'profesional', 'datosTramite', 'datosTramite.tramite', 'datosTramite.prioridad', 'datosTramite.obrasocial'])
            ->where('dni_afiliado', $request->dni)
            ->orderByDesc('fecha_registra')
            ->get();
        foreach ($query as $file) {
            $plan = AfiliadoDetalleTipoPlanEntity::with('TipoPlan')->where('id_padron', $file->dni)->get();
            $escolaridad = AfiliadoEscolaridadEntity::where('id_padron', $file->dni)->first();
            $discapacidad = AfiliadoCertificadoEntity::where('id_padron', $file->dni)->first();
            $ddjj = DeclaracionesJuradasModelo::where('cuil', $file->cuil_benef)->orderBy('fecpresent', 'desc')->first();
            $tranf = TransferenciasModelo::where('cuitapo', $file->cuil_benef)->orderBy('periodo', 'desc')->first();
            $fechaNacimiento = Carbon::parse($file->fe_nac);
            $fechaActual = Carbon::now();
            $diferencia = $fechaNacimiento->diff($fechaActual);
            $file['edad'] = $diferencia->y;
            $file['plan'] = $plan;
            $file['autorizacion'] = $prestaciones;
            $file['notas'] = $notas;
            $file['escolaridad'] = $escolaridad;
            $file['discapacidad'] = $discapacidad;
            $file['ddjj'] = $ddjj;
            $file['tranf'] = $tranf;
            array_push($files, $file);
        }
        return response()->json($files, 200);
    }

    public function postSaveDatosFamiliar(Request $request)
    {
        $now = Carbon::now('America/Argentina/Buenos_Aires');

        $titular = AfiliadoPadronEntity::where('dni', $request->dni_titular)->first();
        $relacionLaboral = RelacionLaboralModelo::where('id_padron', $request->dni_titular)->first();
        $plan = AfiliadoDetalleTipoPlanEntity::where('id', $request->dni_titular)->first();

        DB::beginTransaction();
        $dni = AfiliadoPadronEntity::where('dni', $request->dni)->first();

        if (!$dni) {
            $user = Auth::user();
            $titular->id_usuario = $user->cod_usuario;
            $padron = AfiliadoPadronEntity::create([
                'cuil_tit' => $titular->cuil_tit,
                'cuil_benef' => $request->cuil_benef,
                'id_tipo_documento' => $request->id_tipo_documento,
                'dni' => $request->dni,
                'nombre' => $request->nombre,
                'apellidos' => $request->apellidos,
                'id_sexo' => $request->id_sexo,
                'id_estado_civil' => $request->id_estado_civil,
                'fe_nac' => $request->fe_nac,
                'id_nacionalidad' => $request->id_nacionalidad,
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
                'id_situacion_de_revista' => '99',
                'id_tipo_domicilio' => $titular->id_tipo_domicilio,
                'id_parentesco' => $request->id_parentesco,
                'email' => $titular->email,
                'celular' => $titular->celular,
                'fe_baja' => $titular->fe_baja,
                'activo' => $titular->activo,
                'id_estado_super' => 1,
                'id_cpostal' => $titular->id_cpostal,
                'observaciones' => $titular->observaciones,
                'id_delegacion' => '99',
                'domicilio_postal' => $titular->domicilio_postal,
                'domicilio_laboral' => $titular->domicilio_laboral,
                'id_locatario' => $titular->id_locatario,
                'id_baja_motivos' => null,
                'fech_aprobado' => $now->format('Y-m-d'),
                'fech_descarga' => null,
                'credencial' => 'Autorizado',
                'patologia' => 0,
                'medicacion' => 0,
                'file_dni' => '',
                'id_comercial_origen' => $titular->id_comercial_origen,
                'id_comercial_caja' => $titular->id_comercial_caja,
                'discapacidad' => $request->discapacidad,
            ]);
            AuditoriaPadronModelo::create([
                'fecha' => $now->format('Y-m-d H:i:s'),
                'antes' => '-',
                'ahora' => $padron->nombre . ' ' . $padron->apellidos,
                'id_padron' => $padron->dni,
                'cod_usuario' => $user->cod_usuario,
                'modulo' => 'AFILIADO',
            ]);
            if ($relacionLaboral) {
                RelacionLaboralModelo::create([
                    'id_padron' => $padron->dni,
                    'id_empresa' => $relacionLaboral->id_empresa,
                    'fecha_alta_empresa' => $relacionLaboral->fecha_alta_empresa,
                    'fecha_baja_empresa' => $relacionLaboral->fecha_baja_empresa,
                    'id_usuario' => $user->cod_usuario
                ]);
            }

            if ($plan) {
                AfiliadoDetalleTipoPlanEntity::create([
                    'fecha_alta' => $plan->fecha_alta,
                    'fecha_baja' => $plan->fecha_baja,
                    'id_tipo_plan' => $plan->id_tipo_plan,
                    'id_padron' => $padron->dni
                ]);
            }
            $msg = 'Datos registrados correctamente';
        } else {
            return response()->json(['message' => 'Ya existe un afiliado con el mismo número de documento'], 500);
        }
        DB::commit();
        return response()->json(['message' => $msg], 200);
    }

    public function addFilesAfiliados(Request $request)
    {
        $now = Carbon::now('America/Argentina/Buenos_Aires');
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $index => $file) {
                $fileName = time() . $index . '.' . $file->extension();
                $file->storeAs('images', $fileName, 'public');
                DetalleTipoDocAfiliadoModelo::create([
                    'nombre_archivo' => $fileName,
                    'id_padron' => $request->id_afiliado,
                    'id_tipo_documentacion' => $request->id_tipo_doc[$index],
                    'fecha_carga' => $now->format('Y-m-d'),
                    'observacion' => $request->observaciones[$index]
                ]);
            }
            return response()->json(['message' => 'Documentación del afiliado guardado correctamente'], 200);
        } else {
            return response()->json(['message' => 'No se encontro una documentacion asignada'], 500);
        }
    }

    public function updateDetallesAfiliados($request)
    {
        $Afiliados = AfiliadoPadronEntity::where('cuil_tit', $request->cuil_tit)->get();
        $relacionLaboral = RelacionLaboralModelo::where('id_padron', $request->dni)->get();
        $plan = AfiliadoDetalleTipoPlanEntity::where('id_padron', $request->dni)->get();
        if ($Afiliados) {
            foreach ($Afiliados as $afiliado) {
                if ($afiliado->id_parentesco != '00') {
                    $afiliado->id_comercial_caja = $request->id_comercial_caja;
                    $afiliado->id_comercial_origen = $request->id_comercial_origen;
                    $afiliado->id_locatario = $request->id_locatario;
                    $afiliado->domicilio_postal = $request->domicilio_postal;
                    $afiliado->celular = $request->celular;
                    $afiliado->telefono = $request->telefono;
                    $afiliado->domicilio_laboral = $request->domicilio_laboral;
                    $afiliado->id_provincia = $request->id_provincia;
                    $afiliado->id_partido = $request->id_partido;
                    $afiliado->id_localidad = $request->id_localidad;
                    $afiliado->id_cpostal = $request->id_cpostal;
                    $afiliado->calle = $request->calle;
                    $afiliado->numero = $request->numero;
                    $afiliado->piso = $request->piso;
                    $afiliado->depto = $request->depto;
                    $afiliado->id_tipo_domicilio = $request->id_tipo_domicilio;
                    $afiliado->save();

                    if ($plan && count($plan) > 0) {
                        AfiliadoDetalleTipoPlanEntity::where('id_padron', $afiliado->dni)->delete();
                        foreach ($plan as $planes) {
                            AfiliadoDetalleTipoPlanEntity::create([
                                'fecha_alta' => $planes->fecha_alta,
                                'fecha_baja' => $planes->fecha_baja ?? null,
                                'id_tipo_plan' => $planes->id_tipo_plan,
                                'id_padron' => $afiliado->dni
                            ]);
                        }
                    }

                    if ($relacionLaboral && count($relacionLaboral) > 0) {
                        RelacionLaboralModelo::where('id_padron', $afiliado->dni)->delete();
                        foreach ($relacionLaboral as $empresaData) {
                            RelacionLaboralModelo::create([
                                'id_padron' => $afiliado->dni,
                                'id_empresa' => $empresaData->id_empresa,
                                'fecha_alta_empresa' => $empresaData->fecha_alta_empresa,
                                'fecha_baja_empresa' => $empresaData->fecha_baja_empresa ?? null,
                                'id_usuario' => $request->id_usuario
                            ]);
                        }
                    }
                }
            }
        }
    }

    public function deleteDetalleTipoDoc(Request $request)
    {
        $doc = DetalleTipoDocAfiliadoModelo::where('id_detalle', $request->id)->first();

        if (!$doc) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }
        $doc->delete();

        return response()->json(['message' => 'Archivo eliminado'], 200);
    }

    public function srvReportesAfiliado(Request $request)
    {
        $query = AfiliadoPadronEntity::with(['obrasocial', 'tipoParentesco', 'delegacion', 'baja', 'empresadetalle.relacionEmpresa', 'provincia', 'partido', 'localidad', 'origen']);

        if ($request->id_filial != null) {
            $query->whereIn('id_delegacion', $request->id_filial);
        }

        if ($request->id_locatario != null) {
            $query->whereIn('id_locatario', $request->id_locatario);
        }

        if ($request->id_origen != null) {
            $query->whereIn('id_comercial_origen', $request->id_origen);
        }

        $query->when($request->boolean('grupo'), function ($q) {
            $q->where('id_parentesco', '00');
        });

        if ($request->id_empresa != null) {
            $query->whereHas('empresadetalle', function ($q) use ($request) {
                $q->whereIn('id_empresa', $request->id_empresa);
            });
        }

        if ($request->tipo == 'alta') {
            if ($request->desde != null && $request->hasta != null) {
                $query->whereBetween('fe_alta', [$request->desde, $request->hasta]);
            }
        }
        if ($request->tipo == 'baja') {
            if ($request->id_baja != null) {
                $query->whereIn('id_baja_motivos', $request->id_baja);
            }

            if ($request->desde != null && $request->hasta != null) {
                $query->whereBetween('fe_baja', [$request->desde, $request->hasta]);
            }
        }

        $model = $query
            ->orderBy('cuil_tit')
            ->orderBy('id_parentesco')
            ->get();
        return response()->json($model, 200);
    }

    public function srvReportesCuadroAfiliado(Request $request)
    {
        $query = AfiliadoPadronEntity::select(
            'tb_delegacion.id as id_filial',
            'tb_delegacion.delegacion',
            DB::raw('COUNT(tb_padron.dni) as total_afiliados'),
            DB::raw("COUNT(CASE 
                WHEN tb_padron.id_parentesco = '00' 
                THEN 1 END) as total_titulares"),
            DB::raw("COUNT(CASE 
                WHEN tb_padron.id_parentesco IN ('01','02') 
                THEN 1 END) as total_conyugue"),
            DB::raw("COUNT(CASE 
                WHEN tb_padron.id_parentesco IN ('08') 
                THEN 1 END) as total_familiar"),
            DB::raw("COUNT(CASE 
                WHEN tb_padron.id_parentesco = '10' 
                THEN 1 END) as total_adherente"),
            DB::raw("COUNT(CASE 
                WHEN tb_padron.id_parentesco = '99' 
                THEN 1 END) as sin_identificar"),
            DB::raw("COUNT(CASE 
                WHEN tb_padron.id_parentesco NOT IN ('00','01','02','10','99','08') 
                THEN 1 END) as total_hijos")
        )
            ->join('tb_delegacion', 'tb_delegacion.id', '=', 'tb_padron.id_delegacion')
            ->groupBy('tb_delegacion.id', 'tb_delegacion.delegacion');

        if ($request->tipo == 'alta') {
            if ($request->desde != null && $request->hasta != null) {
                $query->whereBetween('fe_alta', [$request->desde, $request->hasta]);
            }
        }

        if ($request->tipo == 'baja') {
            if ($request->desde != null && $request->hasta != null) {
                $query->whereBetween('fe_baja', [$request->desde, $request->hasta]);
            }
        }

        $model = $query
            ->get();
        return response()->json($model, 200);
    }

    public function exportarMemo(Request $request)
    {
        if ($request->tipo == 'general') {
            $query2 = AfiliadoPadronEntity::select(
                'tb_locatorio.id_locatorio',
                'tb_locatorio.locatorio',
                'tb_delegacion.id as id_filial',
                'tb_delegacion.delegacion',
                DB::raw('COUNT(tb_padron.dni) as total_afiliados')
            )
                ->join('tb_locatorio', 'tb_locatorio.id_locatorio', '=', 'tb_padron.id_locatario')
                ->join('tb_delegacion', 'tb_delegacion.id', '=', 'tb_padron.id_delegacion')
                ->groupBy(
                    'tb_locatorio.id_locatorio',
                    'tb_locatorio.locatorio',
                    'tb_delegacion.id',
                    'tb_delegacion.delegacion',
                )
                ->orderBy('tb_locatorio.id_locatorio')
                ->orderBy('tb_delegacion.id')
                ->get()
                ->groupBy('locatorio');

            $pdf = Pdf::loadView('memorando.memorando_general', ['tipo' => $request->tipo, 'locatarios' => $query2]);
            $pdf->setPaper('A4');
            return $pdf->download('memorando.pdf');
        } else {
            $query = AfiliadoPadronEntity::select(
                'tb_delegacion.id as id_filial',
                'tb_delegacion.delegacion',
                DB::raw('COUNT(tb_padron.dni) as total_afiliados')
            )
                ->join('tb_delegacion', 'tb_delegacion.id', '=', 'tb_padron.id_delegacion')
                ->groupBy('tb_delegacion.id', 'tb_delegacion.delegacion');

            if ($request->tipo == 'alta') {
                $inicioMes = Carbon::now()->startOfMonth()->toDateString();
                $finMes = Carbon::now()->endOfMonth()->toDateString();

                $query->whereBetween('fe_alta', [$inicioMes, $finMes]);
            }

            if ($request->tipo == 'baja' && $request->desde) {
                $inicio = Carbon::createFromFormat('Y-m', $request->desde)
                    ->startOfMonth()
                    ->toDateString();

                $fin = Carbon::createFromFormat('Y-m', $request->desde)
                    ->endOfMonth()
                    ->toDateString();

                $query->whereBetween('fe_baja', [$inicio, $fin]);
            }

            $model = $query
                ->get();
            $pdf = Pdf::loadView('memorando.memorando', ['data' => $model, 'tipo' => $request->tipo, 'periodo' => $request->desde]);
            $pdf->setPaper('A4');
            return $pdf->download('memorando.pdf');
        }
    }

    public function importarExcelPadron(Request $request)
    {
        $request->validate([
            'archivo_excel' => 'required|mimes:xlsx,xls,csv'
        ]);
        try {
            // 1. Leer el archivo Excel y mapearlo
            $datosExcel = Excel::toArray([], $request->file('archivo_excel'))[0];
            array_shift($datosExcel); // Quitar encabezados
            $datosMapeadosDelExcel = [];
            foreach ($datosExcel as $fila) {
                if (isset($fila[0])) {
                    $cuil = $fila[0];
                    $datosMapeadosDelExcel[$cuil] = [
                        'cuil' => $cuil,
                        'origen' => $fila[1] ?? null // Origen desde el excel (columna 2)
                    ];
                }
            }
            $cuilsDelExcel = array_keys($datosMapeadosDelExcel);
            // =================================================================
            // 2. CREAR LA CONEXIÓN DINÁMICA HACIA EL ORIGEN
            // =================================================================
            config(['database.connections.db_origen' => [
                'driver'    => 'mysql',
                'host'      => '127.0.0.1',
                'port'      => '3306',
                'database'  => 'u979626107_albasalud',
                'username'  => 'u979626107_albasalud',
                'password'  => 'zDHwQ|T4*',
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
                'strict'    => true,
                'engine'    => null,
            ]]);
            // 3. Dividimos en lotes de 1000
            $lotes = array_chunk($cuilsDelExcel, 1000);
            // Iniciamos transacción en la base de datos DESTINO (Tu .env actual)
            DB::beginTransaction();
            foreach ($lotes as $loteDeCuils) {

                // 4. BÚSQUEDA MASIVA EN EL ORIGEN (Conexión dinámica)
                $registrosEncontrados = DB::connection('db_origen')->table('tb_padron')
                    ->whereIn('cuil_benef', $loteDeCuils)
                    ->get();

                // Buscar los que YA existen en DESTINO (Tu .env actual) para ignorarlos
                $cuilsExistentes = DB::table('tb_padron')
                    ->whereIn('cuil_benef', $loteDeCuils)
                    ->pluck('cuil_benef')
                    ->toArray();

                // 5. PREPARAR DATA E INYECTAR CAMPOS EXTRA
                $datosAInsertar = [];
                foreach ($registrosEncontrados as $registro) {

                    // Si ya existe en la BD destino, saltamos este registro
                    if (in_array($registro->cuil_benef, $cuilsExistentes)) {
                        continue;
                    }

                    $dataDelExcel = $datosMapeadosDelExcel[$registro->cuil_benef];
                    // --- AGREGAMOS LOS CAMPOS EXTRA ---
                    $datosAInsertar[] = [
                        'cuil_tit' => $registro->cuil_tit,
                        'cuil_benef'=> $registro->cuil_benef,
                        'id_tipo_documento'=> $registro->id_tipo_documento,
                        'dni'=> $registro->dni,
                        'nombre'=> $registro->nombre,
                        'apellidos'=> $registro->apellidos,
                        'id_sexo'=> $registro->id_sexo,
                        'id_estado_civil'=> $registro->id_estado_civil,
                        'fe_nac'=> $registro->fe_nac,
                        'id_nacionalidad'=> $registro->id_nacionalidad,
                        'calle'=> $registro->calle,
                        'numero'=> $registro->numero,
                        'piso'=> $registro->piso,
                        'depto'=> $registro->depto,
                        'id_localidad'=> $registro->id_localidad,
                        'id_partido'=> $registro->id_partido,
                        'id_provincia'=> $registro->id_provincia,
                        'telefono'=> $registro->telefono,
                        'fe_alta'=> $registro->fe_alta,
                        'id_usuario'=> 2,
                        'fecha_carga'=> $registro->fecha_carga,
                        'id_tipo_beneficiario'=> $registro->id_tipo_beneficiario,
                        'id_situacion_de_revista'=> $registro->id_situacion_de_revista,
                        'id_tipo_domicilio'=> $registro->id_tipo_domicilio,
                        'id_parentesco'=> $registro->id_parentesco,
                        'email'=> $registro->email,
                        'celular'=> $registro->celular,
                        'fe_baja'=> $registro->fe_baja,
                        'activo'=> $registro->activo,
                        'id_estado_super'=> $registro->id_estado_super,
                        'id_cpostal'=> $registro->id_cpostal,
                        'observaciones'=> $registro->observaciones,
                        'id_delegacion'=> 1,
                        'domicilio_postal'=> $registro->domicilio_postal,
                        'domicilio_laboral'=> $registro->domicilio_laboral,
                        'id_locatario'=> 8,
                        'patologia'=> $registro->patologia,
                        'descripcion_patologia'=> $registro->descripcion_patologia,
                        'medicacion'=> $registro->medicacion,
                        'descripcion_medicacion'=> $registro->descripcion_medicacion,
                        'credencial'=> $registro->credencial,
                        'extracapita'=> $registro->extracapita,
                        'id_baja_motivos'=> $registro->id_baja_motivos,
                        'id_comercial_origen'=> 8,
                        'id_comercial_caja'=> 11,
                        'discapacidad'=> $registro->discapacidad,
                        'orden_grupo'=> $registro->orden_grupo,
                        // Campos Extra:
                        'estado_imprimir' => "0",
                    ];
                }
                // 6. INSERT MASIVO EN LA DB DESTINO (Usando .env actual)
                if (count($datosAInsertar) > 0) {
                    DB::table('tb_padron')
                        ->insert($datosAInsertar);
                }
            }
            // Confirmamos que todo salió bien y guardamos permanentemente en el destino
            DB::commit();
            DB::purge('db_origen'); // Limpiamos la conexión de memoria origen
            return response()->json([
                'message' => 'Procesamiento exitoso',
                'total_procesados' => count($cuilsDelExcel)
            ]);
        } catch (Exception $e) {
            // Si algo falla, revertimos los cambios en el destino
            DB::rollBack();
            return response()->json(['error' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}
