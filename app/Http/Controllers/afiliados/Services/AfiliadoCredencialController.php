<?php

namespace App\Http\Controllers\Afiliados\Services;

use App\Http\Controllers\afiliados\Repository\AfiliadoFatfaRepository;
use App\Models\afiliado\AfiliadoCredencialEntity;
use App\Models\afiliado\AfiliadoPadronEntity;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class AfiliadoCredencialController extends Controller
{
    private $user;
    private $fechaActual;

    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }

    public function getCredencial($idPadron)
    {
        $escolaridad = AfiliadoCredencialEntity::where('id_padron', $idPadron)->first();
        return response()->json($escolaridad, 200);
    }

    public function saveCredencial(Request $request)
    {
        if ($request->id != '') {
            $query = AfiliadoCredencialEntity::where('id', $request->id)->first();
            $query->num_carnet = $request->num_carnet;
            $query->fecha_emision = $request->fecha_emision;
            $query->fecha_vencimiento = $request->fecha_vencimiento;
            $query->id_padron = $request->id_padron;
            $query->fecha_modifica = $this->fechaActual;
            $query->cod_usuario_modifica = $this->user->cod_usuario;
            $query->dni = $request->dni;
            $query->save();
            $msg = 'Datos actualizados correctamente';
        } else {
            $escolaridad = AfiliadoCredencialEntity::where('dni', $request->dni)->first();
            if ($escolaridad) {
                return response()->json(['message' => 'El afiliado ya tiene un registro de carnet'], 500);
            } else {
                AfiliadoCredencialEntity::create([
                    'num_carnet' => $request->num_carnet,
                    'fecha_emision' => $request->fecha_emision,
                    'fecha_vencimiento' => $request->fecha_vencimiento,
                    'id_padron' => $request->id_padron,
                    'fecha_registra' => $this->fechaActual,
                    'cod_usuario_registra' => $this->user->cod_usuario,
                    'dni' => $request->dni
                ]);
                $msg = 'Credencial registrado correctamente';
            }
        }
        return response()->json(['message' => $msg], 200);
    }

    public function printCarnet(Request $request)
    {
        $now = new \DateTime();
        $fecha_inicio = $now->format('Y-m-d');
        $fecha_final = $now->modify('last day of this month')->format('Y-m-d');
        $grupal = AfiliadoPadronEntity::with('detalleplan.addplan', 'tipoParentesco', 'origen')->where('id', $request->id_padron)->get();
        if ($grupal) {
            $pdf = Pdf::loadView('carnet_afiliado', ["data" => $grupal, "f_inicio" => $fecha_inicio, "f_fin" => $fecha_final]);
            $pdf->setPaper('A4', 'landscape');
            return $pdf->download('carnet.pdf');
        }
    }

    public function printCarnetUser(AfiliadoFatfaRepository $repoFatfa)
    {
        $user = Auth::user();
        $now = new \DateTime();
        $fecha_inicio = $now->format('Y-m-d');
        $now->modify('+30 days'); // Añadir 30 días
        $fecha_final = $now->format('Y-m-d');
        $datos = AfiliadoPadronEntity::with('detalleplan.addplan', 'tipoParentesco')->where('dni', $user->documento)->first();

        if ($datos) {
            $isFatfa = 0;
            $isFatfa = $repoFatfa->findByExistsConvenioFatfa($datos->cuil_benef, $datos->dni);

            $urlReporte = "https://fatfa.site/apiospf/api/v1/reportes/generar-carnet-afiliado?cuilTitular=$datos->cuil_tit&isFatfa=$isFatfa&desde=$fecha_inicio&hasta=$fecha_final";
            $resReportecarnets = Http::withOptions([
                'verify' => false,
            ])->get($urlReporte);
            if ($resReportecarnets->successful()) {
                $pdfContent = $resReportecarnets->body();
                return response($pdfContent, 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'inline; filename="carnet.pdf"');
            }
            return response()->json(['error' => 'No se pudo generar el carnet'], 404);
        }
    }

    public function getListar(Request $request)
    {
        $data = [];
        $query = AfiliadoCredencialEntity::with(['afiliado.obrasocial']);

        if (!is_null($request->searchs)) {
            $query->whereHas('afiliado', function ($subQuery) use ($request) {
                $subQuery->where('dni', 'LIKE', "%$request->searchs%")
                    ->orWhere('apellidos', 'LIKE', "%$request->searchs%");
            });
        }

        if (!is_null($request->id_locatorio)) {
            $query->whereHas('afiliado', function ($subQuery) use ($request) {
                $subQuery->where('id_locatario', "$request->id_locatorio");
            });
        }

        if (!is_null($request->desde) && !is_null($request->hasta)) {
            $query->whereBetween('fecha_emision', [$request->desde, $request->hasta]);
        }

        $data = $query->get();

        return response()->json($data);
    }

    public function getId(Request $request)
    {
        $escolaridad = AfiliadoCredencialEntity::with(['afiliado'])->where('dni', $request->id)->first();
        return response()->json($escolaridad, 200);
    }
}
