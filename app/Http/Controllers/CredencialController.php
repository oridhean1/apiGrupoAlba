<?php

namespace App\Http\Controllers;

use App\Models\afiliado\AfiliadoCredencialEntity;
use App\Models\afiliado\AfiliadoDetalleTipoPlanEntity;
use App\Models\afiliado\AfiliadoPadronEntity;
use App\Models\CredencialModelo;
use App\Models\afiliado\AfiliadoTipoParentescoEntity;
use App\Models\afiliado\AfiliadoTipoPlanEntity;
use App\Models\DeclaracionesJuradasModelo;
use App\Models\PeriodoModelo;
use App\Models\TransferenciasModelo;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CredencialController extends Controller
{
    //
    public function getCredencial($idPadron)
    {
        $escolaridad =  CredencialModelo::where('id_padron', $idPadron)->first();
        return response()->json($escolaridad, 200);
    }

    public function saveCredencial(Request $request)
    {
        if ($request->id != '') {
            $query = CredencialModelo::where('id', $request->id)->first();
            $query->num_carnet = $request->num_carnet;
            $query->fecha_emision = $request->fecha_emision;
            $query->fecha_vencimiento = $request->fecha_vencimiento;
            $query->id_padron = $request->id_padron;
            $query->save();
            $msg = 'Datos actualizados correctamente';
        } else {
            $escolaridad =  CredencialModelo::where('id_padron', $request->id_padron)->first();
            if ($escolaridad) {
                return response()->json(['message' => 'El afiliado ya tiene un registro de carnet'], 500);
            } else {
                CredencialModelo::create($request->all());
                $msg = 'Credencial registrado correctamente';
            }
        }
        return response()->json(['message' => $msg], 200);
    }

    public function printCarnetFamiliar(Request $request)
    {
        /* $afiliados = DB::table('tb_padron as p')
            ->where('p.id_parentesco', '00')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('tb_usuarios as u')
                    ->whereColumn('u.documento', 'p.dni');
            })
            ->get();
        $data = [];

        foreach ($afiliados as $af) {
            $data[] = [
                'nombre_apellidos'     => $af->nombre . ' ' . $af->apellidos,
                'documento'            => $af->dni,
                'telefono'             => $af->telefono,
                'direccion'            => '',
                'fecha_alta'            => $af->fe_alta,
                'estado_cuenta'        => 1,
                'fecha_cambio_clave'   => $af->fe_alta,
                'email'                => $af->dni, 
                'password'             => bcrypt($af->dni),
                'cod_perfil'           => 25,
                'actualizo_datos'      => 0
            ];
        }

        DB::table('tb_usuarios')->insertOrIgnore($data); */

        $datos = AfiliadoPadronEntity::with('detalleplan.addplan', 'tipoParentesco', 'origen')->where('dni', $request->dni)->first();
        if ($datos->estado_imprimir == 0) {
            return response()->json(['error' => 'No tiene permiso para imprimir'], 404);
        }

        if ($datos->activo != 0) {
            $now = new \DateTime('now', new \DateTimeZone('America/Argentina/Buenos_Aires'));
            $grupal = AfiliadoPadronEntity::with('detalleplan.addplan', 'tipoParentesco', 'origen')->where('cuil_tit', $datos->cuil_tit)->where('activo', '1')
                ->OrderBy('id_parentesco', 'asc')->get();
            $fecha_inicio = $now->format('Y-m-d');
            $fecha_final = $now->modify('last day of this month')->format('Y-m-d');
            $carnet = AfiliadoCredencialEntity::where('dni', $datos->dni)->first();

            $grupal = $grupal->map(function ($item, $index) use ($request) {
                $item->correlativo = $index; // empieza en 0
                $item->es_seleccionado = ($item->dni == $request->dni);
                return $item;
            });
            if ($datos) {
                foreach ($grupal as $afiliado) {
                    $afiliado["cuil_benef"] = $afiliado->dni;
                    if ($afiliado->id_parentesco == '00') {
                        AfiliadoPadronEntity::where('id', $afiliado->id)->update([
                            'fech_descarga' => $now->format('Y-m-d H:i:s'),
                        ]);
                    }
                }
                $pdf = Pdf::loadView('carnet_afiliado', ["data" => $grupal, "f_inicio" => $fecha_inicio, "f_fin" => $fecha_final, "plan" => $grupal]);
                $pdf->setPaper('A5', 'landscape');
                return $pdf->download('carnet.pdf');
            }
        } else {
            return response()->json(['error' => 'El usuario esta inactivo. Muchas gracias.'], 404);
        }
    }


    public function printCarnetPersonal(Request $request)
    {

        $now = new \DateTime('now', new \DateTimeZone('America/Argentina/Buenos_Aires'));
        $fecha_inicio = $now->format('Y-m-d');
        $fecha_final = $now->modify('last day of this month')->format('Y-m-d');
        $datos = AfiliadoPadronEntity::with('detalleplan.addplan', 'tipoParentesco', 'origen')->where('dni', $request->dni)->where('activo', '1')->get();
        if ($datos[0]->activo != 0) {
            $now = new \DateTime('now', new \DateTimeZone('America/Argentina/Buenos_Aires'));
            $datos = AfiliadoPadronEntity::with('detalleplan.addplan', 'tipoParentesco', 'origen')->where('dni', $request->dni)->get();
            $grupal = AfiliadoPadronEntity::with('detalleplan.addplan', 'tipoParentesco', 'origen')->where('cuil_tit', $datos[0]->cuil_tit)
                ->OrderBy('id_parentesco', 'asc')->get();
            $carnet = AfiliadoCredencialEntity::where('dni', $datos[0]->dni)->first();

            $correlativo = null;
            foreach ($grupal as $index => $item) {
                if ($item->dni == $request->dni) {
                    $correlativo = $index;
                    break;
                }
            }
            $datos = $datos->map(function ($afiliado) use ($correlativo) {
                $afiliado->correlativo = $correlativo;
                return $afiliado;
            });

            if ($datos) {
                foreach ($datos as $afiliado) {
                    $afiliado["cuil_benef"] = $afiliado->dni;
                    if ($afiliado->id_parentesco == '00') {
                        AfiliadoPadronEntity::where('id', $afiliado->id)->update([
                            'fech_descarga' => $now->format('Y-m-d H:i:s'),
                        ]);
                    }
                }
                $pdf = Pdf::loadView('carnet_afiliado', ["data" => $datos, "f_inicio" => $fecha_inicio, "f_fin" => $fecha_final, "plan" => $grupal]);
                $pdf->setPaper('A5', 'landscape');
                return $pdf->download('carnet.pdf');
            }
        } else {
            return response()->json(['error' => 'El usuario esta inactivo. Muchas gracias.'], 404);
        }
    }

    public function printCarnetUser(Request $request)
    {

        $user = Auth::user();
        $now = new \DateTime('now', new \DateTimeZone('America/Argentina/Buenos_Aires'));
        $datos = AfiliadoPadronEntity::with('detalleplan.addplan', 'tipoParentesco', 'origen')->where('dni', $user->documento)->first();
        if ($datos->estado_imprimir == 0) {
            return response()->json(['error' => 'No tiene permiso para imprimir'], 404);
        }
        if ($request->id == '0') {
            $grupal = AfiliadoPadronEntity::with('detalleplan.addplan', 'tipoParentesco', 'origen')->where('cuil_tit', $datos->cuil_tit)->where('activo', '1')
                ->OrderBy('id_parentesco', 'asc')->get();
        } else {
            $grupal = AfiliadoPadronEntity::with('detalleplan.addplan', 'tipoParentesco', 'origen')->where('dni', $user->documento)
                ->OrderBy('id_parentesco', 'asc')->get();
        }
        $grupal = $grupal->map(function ($item, $index) use ($request) {
            $item->correlativo = $index; // empieza en 0
            $item->es_seleccionado = ($item->dni == $request->dni);
            return $item;
        });
        if ($datos) {
            foreach ($grupal as $afiliado) {
                if ($afiliado->id_parentesco == '00') {
                    AfiliadoPadronEntity::where('id', $afiliado->id)->update([
                        'fech_descarga' => $now->format('Y-m-d H:i:s'),
                    ]);
                }
            }
            $fecha_inicio = $now->format('Y-m-d');
            $fecha_final = $now->modify('last day of this month')->format('Y-m-d');
            $pdf = Pdf::loadView('carnet_afiliado', ["data" => $grupal, "f_inicio" => $fecha_inicio, "f_fin" => $fecha_final, "plan" => $grupal]);
            $pdf->setPaper('A5', 'landscape');
            return $pdf->download('carnet.pdf');
        }
        //}
    }


    public function postUpdateCarnet(Request $request)
    {
        $credencial = '';
        $message = '';
        if ($request->estado == 'Autorizado') {
            $credencial = 'Denegado';
            $message = 'Se bloqueo la visualización de carnet para el afiliado';
        } else {
            $credencial = 'Autorizado';
            $message = 'Se desbloqueo la visualización de carnet para el afiliado';
        }
        $query = AfiliadoPadronEntity::where('dni', $request->dni)->first();
        $query->credencial = $credencial;
        $query->update();
        return response()->json(['message' => $message], 200);
    }
}
