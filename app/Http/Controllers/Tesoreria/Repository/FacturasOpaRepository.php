<?php

namespace App\Http\Controllers\Tesoreria\Repository;

use App\Models\facturacion\FacturacionDatosEntity;
use App\Models\Tesoreria\TesFacturasOpaEntity;
use App\Models\Tesoreria\TesOrdenPagoEntity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Saldos de factura para la imputación a OPAs.
 *
 * ⚠️ Este archivo se reescribió entero el 2026-09-12. La versión anterior era código muerto que
 * además no podía funcionar: consultaba `id_estado_pago` (la columna de `tb_facturacion_datos` se
 * llama **`estado_pago`**) y usaba una relación `tesFacturasOpa()` que nunca se declaró en
 * `FacturacionDatosEntity`. Cualquier llamada reventaba con "Unknown column" o
 * "Call to undefined relationship". Solo `getFacturasOPA()` estaba enganchada a una ruta viva.
 *
 * ---
 *
 * **La unidad de `monto_aplicado` es NETA de débito** (decidido el 2026-09-12, al habilitar la
 * imputación parcial desde la pantalla de Crear OPA).
 *
 * Hasta ahora la imputación arrastraba el **bruto** (`total_neto`) porque la OPA tomaba la factura
 * entera o nada: `montoPagableOpa()` le restaba el débito después y listo. Con imputación parcial
 * eso obligaría a prorratear el débito entre las OPAs que se reparten la factura, y no hay ningún
 * criterio no arbitrario para hacerlo. Con el criterio neto, el número que el usuario tipea *es*
 * la plata que se le va a pagar al prestador por esa factura en esa orden.
 *
 * No hay que migrar nada y las órdenes viejas no cambian de comportamiento: el `min()` de
 * `montoPagableOpa()` sigue capeando las filas históricas cargadas en bruto. Por eso
 * `saldoImputableFactura()` arranca del pagable (neto) y clampea en 0 — una factura vieja imputada
 * por el bruto da negativo antes del clamp, y "no queda nada" es la respuesta correcta.
 */
class FacturasOpaRepository
{
    private $user;
    private $fechaActual;

    public function __construct()
    {
        $this->user = Auth::user();
        $this->fechaActual = Carbon::now('America/Argentina/Buenos_Aires');
    }

    /**
     * Lo máximo que se le puede llegar a pagar al beneficiario por esta factura, sin mirar OPAs.
     *
     * Es el bruto menos el débito de liquidación. Mismo criterio que `montoPagableOpa()` — están
     * obligados a coincidir: si esta función dijera más que aquella, se podría imputar plata que
     * la orden después no puede pagar y la OPA quedaría trabada en PAGO PARCIAL para siempre (es
     * exactamente el bug de `docs/circuito-pagos/revisar-debito-no-descontado.md`).
     *
     * Acepta el id o la fila ya cargada, para no repegarle a la base dentro de un `foreach`.
     */
    public function pagableFactura($factura): float
    {
        if (!is_object($factura)) {
            $factura = FacturacionDatosEntity::find($factura);
        }

        if (!$factura) {
            return 0.0;
        }

        return max(
            0.0,
            (float) $factura->total_neto - (float) ($factura->total_debitado_liquidacion ?? 0)
        );
    }

    /**
     * Cuánto de esta factura está comprometido en OPAs que siguen en pie.
     *
     * "En pie" = todas menos las RECHAZADAS. Una orden rechazada se revirtió: su imputación no
     * puede seguir reservando saldo, si no la factura quedaría imposible de volver a pagar.
     *
     * ⚠️ El estado a excluir es el **3**. En el proyecto de referencia (ospf) el equivalente se
     * escribe `whereNotIn(..., [5, 6])`, y acá 5 es PAGADO y 6 es PAGO PARCIAL: copiarlo tal cual
     * descontaría del saldo justo las órdenes cobradas e ignoraría las rechazadas, o sea, al
     * revés. Va la constante, nunca el número suelto.
     *
     * @param int|null $excluirOpa Orden que se está editando: su propia imputación no puede
     *                             contar contra el saldo que ella misma tiene disponible.
     */
    public function imputadoVigenteFactura($idFactura, $excluirOpa = null): float
    {
        $query = DB::table('tb_tes_opa_factura as pf')
            ->join('tb_tes_orden_pago as o', 'o.id_orden_pago', '=', 'pf.id_orden_pago')
            ->where('pf.id_factura', $idFactura)
            ->where('o.id_estado_orden_pago', '!=', TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO);

        if (!is_null($excluirOpa)) {
            $query->where('pf.id_orden_pago', '!=', $excluirOpa);
        }

        return (float) $query->sum('pf.monto_aplicado');
    }

    /**
     * Lo que todavía se puede imputar de una factura. Es EL número de la pantalla de Crear OPA:
     * lo que se muestra en la columna "Saldo Pendiente" y el tope contra el que se valida el
     * monto que tipea el usuario.
     *
     * Una sola función de saldo, a propósito. El proyecto de referencia tiene cuatro variantes
     * conviviendo y su propia documentación lo marca como deuda técnica; acá no se repite.
     *
     * @param int|null $excluirOpa Ver `imputadoVigenteFactura()`.
     */
    public function saldoImputableFactura($factura, $excluirOpa = null): float
    {
        if (!is_object($factura)) {
            $factura = FacturacionDatosEntity::find($factura);
        }

        if (!$factura) {
            return 0.0;
        }

        $saldo = $this->pagableFactura($factura)
            - $this->imputadoVigenteFactura($factura->id_factura, $excluirOpa);

        return round(max(0.0, $saldo), 2);
    }

    /**
     * Igual que `saldoImputableFactura()` pero para muchas facturas de una, con una sola consulta
     * de imputaciones en vez de una por fila.
     *
     * El listado de Crear OPA pagina de a 50 y necesita el saldo de cada fila: hacerlo de a una
     * eran 50 consultas por pantallazo.
     *
     * @param  iterable $facturas Filas con `id_factura`, `total_neto` y `total_debitado_liquidacion`.
     * @return array<int,float>   Saldo indexado por `id_factura`.
     */
    public function saldosImputablesDeFacturas($facturas, $excluirOpa = null): array
    {
        $ids = collect($facturas)->pluck('id_factura')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $imputado = DB::table('tb_tes_opa_factura as pf')
            ->join('tb_tes_orden_pago as o', 'o.id_orden_pago', '=', 'pf.id_orden_pago')
            ->whereIn('pf.id_factura', $ids)
            ->where('o.id_estado_orden_pago', '!=', TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO)
            ->when(!is_null($excluirOpa), fn($q) => $q->where('pf.id_orden_pago', '!=', $excluirOpa))
            ->groupBy('pf.id_factura')
            ->pluck(DB::raw('SUM(pf.monto_aplicado)'), 'pf.id_factura');

        $saldos = [];

        foreach ($facturas as $f) {
            $saldos[$f->id_factura] = round(
                max(0.0, $this->pagableFactura($f) - (float) ($imputado[$f->id_factura] ?? 0)),
                2
            );
        }

        return $saldos;
    }

    /**
     * Estado de `tb_facturacion_datos.estado` que habilita a generarle una OPA a la factura.
     *
     * La columna se llama `estado` a secas (no `id_estado_factura`), y 3 es VALORIZACIÓN FINAL:
     * la liquidación ya cerró y el monto a pagar es definitivo. Antes del 2026-08-13 llegar a
     * este estado creaba la OPA sola; ahora la factura queda acá esperando que Tesorería la
     * levante desde Crear OPA, y mientras nadie lo haga Liquidaciones todavía puede reabrirla.
     */
    const ESTADO_FACTURA_VALORIZACION_FINAL = 3;

    /**
     * ⚠️ Y el equivalente para PROVEEDOR es el **1**, no el 3.
     *
     * `tb_facturacion_datos.estado` carga **dos catálogos distintos** según el circuito, sobre la
     * misma columna:
     *
     *     prestador:  0 pendiente · 1 en proceso · 2 cerrada · 3 VALORIZACIÓN FINAL · 4 anulada
     *     proveedor:  0 pendiente · 1 CONFIRMADA  ·                                   9 anulada
     *
     * Una factura de proveedor no pasa por liquidación: nace final. Su "valorización final" es el
     * estado 1. Verificado el 2026-09-16: las 1.256 facturas de proveedor de Alba y las 317 de OSV
     * están todas en 1, **ninguna en 3** — filtrar por 3 no habría traído ni una.
     *
     * No se migró el valor a 3 a propósito: los badges del visor de proveedores leen 0/1/9, así que
     * cambiarlo dejaría 1.573 facturas sin estado visible, y no haría falta para nada.
     */
    const ESTADO_FACTURA_PROVEEDOR_CONFIRMADA = 1;

    /** `id_tipo_factura` 16 = BIENES Y SERVICIOS: la señal canónica de "es de proveedor". */
    const TIPO_FACTURA_PROVEEDOR = 16;

    /**
     * Facturas de prestador listas para imputar a una OPA, paginadas, con su saldo.
     *
     * Es el listado de la pantalla Tesorería › Crear OPA. Trae SOLO prestadores: para proveedores
     * la OPA todavía nace sola al registrar la factura (`FacturacionProcesosController`), así que
     * listarlas acá mostraría facturas que ya tienen orden. El toggle del front ya contempla el
     * tipo para cuando se unifiquen los dos circuitos.
     *
     * Por defecto esconde las que no tienen nada para imputar. El filtro va en SQL y no en PHP a
     * propósito: si se filtrara después de paginar, el `total` sería mentira y habría páginas
     * medio vacías.
     *
     * @param  object|null $params  desde, hasta, cuit, razon_social, numero_factura, periodo,
     *                              id_prestador, incluir_sin_saldo, page, per_page
     * @param  int|null    $excluirOpa  OPA en edición (su imputación no se descuenta del saldo).
     * @return array{data: array, total: int}
     */
    public function listarFacturasParaOpa($params = null, $excluirOpa = null): array
    {
        // Saldo imputable calculado en SQL. Tiene que dar lo mismo que saldoImputableFactura():
        // si divergen, la grilla muestra un tope y el backend valida contra otro.
        $imputadoSql = 'COALESCE((
            SELECT SUM(pf.monto_aplicado)
              FROM tb_tes_opa_factura pf
              JOIN tb_tes_orden_pago o ON o.id_orden_pago = pf.id_orden_pago
             WHERE pf.id_factura = f.id_factura
               AND o.id_estado_orden_pago <> ' . TestOrdenPagoRepository::ESTADO_OPA_RECHAZADO
            . (is_null($excluirOpa) ? '' : ' AND pf.id_orden_pago <> ' . (int) $excluirOpa) . '
        ), 0)';

        $pagableSql = '(f.total_neto - COALESCE(f.total_debitado_liquidacion, 0))';
        $saldoSql   = "GREATEST({$pagableSql} - {$imputadoSql}, 0)";

        // LEFT y no INNER: hay facturas cuyo `id_prestador` no tiene fila en `tb_prestador`
        // (1 en Alba, 3 en OSV al 2026-09-12). Con INNER esas facturas desaparecían del listado
        // sin ningún aviso — el usuario no tenía forma de saber por qué una factura valorizada no
        // aparecía. Y sería más estricto que el camino viejo: "Generar OPA" desde liquidaciones
        // ni siquiera mira esta tabla, así que esas facturas hoy SÍ se pueden pagar. La orden solo
        // necesita el `id_prestador`; el nombre es para mostrar, y sale vacío.
        // Los dos circuitos usan la misma pantalla y el mismo armado de orden, pero se reconocen
        // distinto y **califican con estados distintos** — ver las constantes de arriba.
        $esProveedor = strtoupper((string) ($params->tipo ?? 'PRESTADOR')) === 'PROVEEDOR';

        $query = DB::table('tb_facturacion_datos as f')
            ->leftJoin('tb_razones_sociales as rs', 'rs.id_razon', '=', 'f.id_locatorio');

        if ($esProveedor) {
            // `id_tipo_factura == 16` es la señal canónica de proveedor, no "tiene id_proveedor":
            // hay facturas con los dos ids cargados a la vez (dato sucio) y decidir por cuál no es
            // null las clasifica mal en un sentido o en el otro.
            $query->leftJoin('tb_proveedor as p', 'p.cod_proveedor', '=', 'f.id_proveedor')
                ->where('f.id_tipo_factura', self::TIPO_FACTURA_PROVEEDOR)
                ->where('f.estado', self::ESTADO_FACTURA_PROVEEDOR_CONFIRMADA);
        } else {
            $query->leftJoin('tb_prestador as p', 'p.cod_prestador', '=', 'f.id_prestador')
                ->whereNotNull('f.id_prestador')
                ->whereNull('f.id_proveedor')
                ->where('f.id_tipo_factura', '!=', self::TIPO_FACTURA_PROVEEDOR)
                ->where('f.estado', self::ESTADO_FACTURA_VALORIZACION_FINAL);
        }

        if (!is_null($params)) {
            if (!empty($params->desde) && !empty($params->hasta)) {
                $query->whereBetween(DB::raw('DATE(f.fecha_registra)'), [$params->desde, $params->hasta]);
            }

            if (!empty($params->periodo)) {
                $query->where('f.periodo', $params->periodo);
            }

            // El beneficiario se acota por id_prestador, NUNCA por CUIT. El CUIT es texto libre:
            // viene vacío o repetido (típico en centros públicos tipo CAPS), así que filtrar por
            // ahí mezcla prestadores distintos bajo un CUIT en blanco. Es el mismo criterio que
            // ya tiene el visor de liquidaciones (2026-08-13); la pantalla de referencia de ospf
            // filtra por CUIT y por eso no se copió esa parte.
            if (!empty($params->id_prestador)) {
                $query->where(
                    $esProveedor ? 'f.id_proveedor' : 'f.id_prestador',
                    $params->id_prestador
                );
            }

            // La grilla se acota por prestador Y razon social juntos: el mismo prestador le puede
            // facturar a dos empresas del grupo, y esas facturas necesitan ordenes separadas.
            if (!empty($params->id_locatorio)) {
                $query->where('f.id_locatorio', $params->id_locatorio);
            }

            // Los de abajo son búsqueda libre del usuario, no la clave de agrupación.
            if (!empty($params->cuit)) {
                $query->where('p.cuit', 'LIKE', "%{$params->cuit}%");
            }

            if (!empty($params->razon_social)) {
                $rs = $params->razon_social;
                $query->where(function ($q) use ($rs) {
                    $q->where('p.razon_social', 'LIKE', "%{$rs}%")
                        ->orWhere('p.nombre_fantasia', 'LIKE', "%{$rs}%");
                });
            }

            if (!empty($params->numero_factura)) {
                $nf = $params->numero_factura;
                $query->where(function ($q) use ($nf) {
                    $q->where('f.numero', 'LIKE', "%{$nf}%")
                        ->orWhere(DB::raw("CONCAT(f.sucursal, '-', f.numero)"), 'LIKE', "%{$nf}%")
                        ->orWhere(DB::raw("CONCAT(f.tipo_letra, ' ', f.sucursal, '-', f.numero)"), 'LIKE', "%{$nf}%");
                });
            }
        }

        if (empty($params->incluir_sin_saldo)) {
            $query->whereRaw("{$saldoSql} > 0.01");
        }

        $total = (int) (clone $query)->count(DB::raw('DISTINCT f.id_factura'));

        $query->select([
            'f.id_factura',
            // `id_prestador` es el nombre que usa el front para anclar la seleccion. Para una
            // factura de proveedor lleva el `id_proveedor`: el beneficiario es uno solo y la
            // pantalla no tiene por que saber de cual de las dos tablas salio.
            DB::raw(($esProveedor ? 'f.id_proveedor' : 'f.id_prestador') . ' as id_prestador'),
            // Cual de nuestras empresas le debe la factura. La orden se paga desde una cuenta que
            // pertenece a UNA razon social, asi que la seleccion no puede cruzarlas. (2026-09-16)
            'f.id_locatorio',
            'f.periodo',
            'f.fecha_registra',
            'f.fecha_comprobante',
            'f.total_neto',
            'f.total_debitado_liquidacion',
            'f.estado',
            'f.estado_pago',
            'f.numero',
            'f.sucursal',
            'f.tipo_letra',
            'p.cuit',
            'p.razon_social',
            'p.nombre_fantasia',
            'rs.razon_social as razon_social_empresa',
            DB::raw("CONCAT(f.tipo_letra, ' ', f.sucursal, '-', f.numero) as comprobante"),
            DB::raw("{$pagableSql} as monto_pagable"),
            DB::raw("{$imputadoSql} as monto_imputado"),
            DB::raw("ROUND({$saldoSql}, 2) as saldo_pendiente"),
        ])
            ->orderByDesc('f.periodo')
            ->orderByRaw('CAST(f.numero AS UNSIGNED) DESC');

        $page = max(1, (int) ($params->page ?? 1));
        $perPage = max(1, (int) ($params->per_page ?? 50));

        $data = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        // Los importes salen como string del driver; el front compara y suma contra ellos.
        $data->transform(function ($f) {
            $f->total_neto = (float) $f->total_neto;
            $f->total_debitado_liquidacion = (float) $f->total_debitado_liquidacion;
            $f->monto_pagable = (float) $f->monto_pagable;
            $f->monto_imputado = (float) $f->monto_imputado;
            $f->saldo_pendiente = (float) $f->saldo_pendiente;
            return $f;
        });

        return ['data' => $data->all(), 'total' => $total];
    }

    /**
     * Facturas de una OPA con el monto imputado a cada una.
     * Es lo que consume `GET /v1/tesoreria/getFacturasOpaId/{id}`.
     */
    public function getFacturasOPA($idOrdenPago)
    {
        return TesFacturasOpaEntity::with(['factura'])
            ->where('id_orden_pago', $idOrdenPago)
            ->get();
    }

    /**
     * Total imputado por una OPA. La cabecera nunca se mantiene con `+=`: se recalcula entera
     * desde la puente, porque una operación que falla a mitad dejaba el monto desincronizado y el
     * error se propagaba en cada agrupado posterior (2026-08-11).
     */
    public function recalcularMontoOPA($idOrdenPago): float
    {
        $total = (float) TesFacturasOpaEntity::where('id_orden_pago', $idOrdenPago)
            ->sum('monto_aplicado');

        TesOrdenPagoEntity::where('id_orden_pago', $idOrdenPago)
            ->update(['monto_orden_pago' => $total]);

        return $total;
    }

    /**
     * 🚧 El estado de pago de la factura NO se toca todavía.
     *
     * `tb_facturacion_datos.estado_pago` hoy tiene dos valores en las dos bases: 0 y 1, donde
     * **1 significa PAGADA** (ver `TesPagosController`). El catálogo de 4 estados que pide el
     * circuito (0 IMPAGA / 1 COMPROMETIDA / 2 PARCIAL / 3 PAGADA) le cambia el significado al 1,
     * así que migrarlo obliga a actualizar en el mismo despliegue los badges de los dos visores de
     * factura y el de liquidaciones, en los dos frontends. Va en su propia ventana.
     *
     * Hasta entonces esto tiene que fallar ruidosamente en vez de escribir: la versión vieja de
     * este archivo hacía `update(['id_estado_pago' => ...])` sobre una columna que ni existe, y de
     * haber existido habría marcado como COMPROMETIDAS facturas que el resto del sistema iba a
     * leer como PAGADAS.
     *
     * Ver `docs/circuito-pagos/plan-fase1-pagos.md` (punto 3) y el README de esa carpeta.
     */
    public function recalcularEstadoPagoFactura($idFactura)
    {
        throw new \RuntimeException(
            'El recálculo de estado_pago de la factura está deshabilitado: el catálogo todavía no '
            . 'se migró (hoy estado_pago=1 significa PAGADA, no COMPROMETIDA). Ver punto 3 de '
            . 'docs/circuito-pagos/README.md.'
        );
    }

    /**
     * 🚧 No-op deliberado, por el mismo motivo que `recalcularEstadoPagoFactura()`.
     *
     * A diferencia de aquella, esta SÍ tiene llamadores vivos: seis, en `TesPagoDetalleRepository`
     * y `PagoRetencionesRepository`, dentro de transacciones que hacen rollback ante cualquier
     * excepción. Tirar acá rompería crear un detalle de pago y cargar una retención.
     *
     * Y no perdemos nada al vaciarla: **ya no hacía nada**. La versión anterior cerraba con
     * `$factura->update(['id_estado_pago' => $nuevoEstado])`, y `id_estado_pago` no está en el
     * `$fillable` de `FacturacionDatosEntity` (la columna real se llama `estado_pago`), así que
     * `fill()` lo descartaba en silencio y `save()` salía sin una sola escritura. Los seis
     * llamadores vienen siendo no-ops desde siempre; lo único que cambia es que ahora está
     * escrito.
     *
     * Cuando se migre el catálogo (punto 3 del README), acá va el cálculo de verdad — y recién
     * ahí esos seis llamadores empiezan a tener efecto, que es justo lo que hay que revisar antes
     * de activarlo.
     *
     * @return null
     */
    public function recalcularEstadoPagoFacturaFromDetalles($idFactura)
    {
        return null;
    }
}
