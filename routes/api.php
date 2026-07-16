<?php

use App\Http\Controllers\AutorizacionController;
use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => ['api'],
    'prefix' => 'auth'
], function () {
    Route::post('login', [App\Http\Controllers\Auth\Services\AuthController::class, 'login']);
    Route::post('authenticate', [App\Http\Controllers\Auth\Services\AuthController::class, 'loginApis']);
    Route::post('register', [App\Http\Controllers\Auth\Services\AuthController::class, 'register']);
    Route::post('logout', [App\Http\Controllers\Auth\Services\AuthController::class, 'logout']);
    Route::post('updateToken', [App\Http\Controllers\Auth\Services\AuthController::class, 'refresh']);
    Route::post('cambioClave', [App\Http\Controllers\Auth\Services\AuthController::class, 'postCambiarContraseña']);
    Route::get('obtener-acceso-modulos', [App\Http\Controllers\Auth\Services\AuthController::class, 'getObtenerAcceso']);
    Route::post('verificar-cuenta', [App\Http\Controllers\Auth\Services\RecuperarContraseniaController::class, 'getVerificarCuenta']);
    Route::post('actualizar-clave-verificada', [App\Http\Controllers\Auth\Services\RecuperarContraseniaController::class, 'getCambiarClave']);
    Route::get('obtener-roles', [App\Http\Controllers\Auth\Services\AuthController::class, 'getRolesUsuarios']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/mantenimiento'
], function () {
    Route::resource('perfiles', App\Http\Controllers\PerfilesController::class);
    Route::resource('sindicatos', App\Http\Controllers\SindicatoController::class);
    Route::resource('tipoDocumentacion', App\Http\Controllers\TipoDocumentacionController::class);
    Route::resource('tipoArea', App\Http\Controllers\TipoAreaController::class);
    Route::resource('mesaEntrada', App\Http\Controllers\MesaEntradaController::class);
    Route::get('ver-adjunto', [App\Http\Controllers\MesaEntradaController::class, 'getVerAdjunto']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/reportes'
], function () {
    Route::post('pdfMesaEntrada', [App\Http\Controllers\MesaEntradaController::class, 'srvRptMesaEntrada']);
    Route::get('pdfprestacion', [App\Http\Controllers\ReportJaspersonController::class, 'index']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/empresa'
], function () {
    Route::get('listEmpresa', [App\Http\Controllers\EmpresaController::class, 'getEmpresa']);
    Route::get('listEmpresaId/{id}', [App\Http\Controllers\EmpresaController::class, 'getEmpresaId']);
    Route::get('listLikeEmpresa/{busqueda}', [App\Http\Controllers\EmpresaController::class, 'getLikeEmpresa']);
    Route::post('saveEmpresa', [App\Http\Controllers\EmpresaController::class, 'postSaveEmpresa']);
    Route::get('listFechaEmpresa', [App\Http\Controllers\EmpresaController::class, 'getFechaEmpresa']);
    Route::post('deleteEmpresa', [App\Http\Controllers\EmpresaController::class, 'deleteEmpresa']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/padron'
], function () {
    Route::post('savePadron', [App\Http\Controllers\PadronController::class, 'postSavePadron']);
    Route::get('listPadron', [App\Http\Controllers\PadronController::class, 'getPadron']);
    Route::get('listPadronId/{id}', [App\Http\Controllers\PadronController::class, 'getPadronId']);
    Route::get('listLikePadron', [App\Http\Controllers\PadronController::class, 'getLikePadron']);
    Route::get('listFechaPadron', [App\Http\Controllers\PadronController::class, 'getFechaPadron']);
    Route::post('updateEstado', [App\Http\Controllers\PadronController::class, 'UpdateEstadoPadron']);
    Route::get('listPadronEstado/{estado}', [App\Http\Controllers\PadronController::class, 'getListPadronEstado']);
    Route::post('deletePadron', [App\Http\Controllers\PadronController::class, 'deletePadron']);
    Route::get('getDniPadron/{dni}', [App\Http\Controllers\PadronController::class, 'getDniPadron']);
    Route::get('getPadronFamiliar/{cuit_titular}', [App\Http\Controllers\PadronController::class, 'getPadronFamiliar']);
    Route::get('getApiDniGenero/{dni}', [App\Http\Controllers\PadronController::class, 'getApiDniPadron']);
    Route::get('getDetallePlan/{id}', [App\Http\Controllers\PadronController::class, 'getDetalleTipoPlanPadron']);
    Route::get('getIdDetallePlan/{id}', [App\Http\Controllers\PadronController::class, 'getIdTipoPlanPadron']);
    Route::get('getExportPadron', [App\Http\Controllers\PadronController::class, 'exportPadron']);
    Route::get('getUserPadron', [App\Http\Controllers\PadronController::class, 'getUserDni']);
    Route::post('postUserUpdate', [App\Http\Controllers\PadronController::class, 'postActualizarUser']);
    Route::get('getListCredencial/{estado}', [App\Http\Controllers\PadronController::class, 'getListPadroncredencial']);
    Route::post('updateEstadoCredencial', [App\Http\Controllers\PadronController::class, 'UpdateEstadoCredencial']);
    Route::get('counterDownload', [App\Http\Controllers\PadronController::class, 'counterDownload']);
    Route::get('listPadronDownloadCarnet', [App\Http\Controllers\PadronController::class, 'listPadronDownloadCarnet']);
    Route::post('saveFamiliar', [App\Http\Controllers\PadronController::class, 'postSaveDatosFamiliar']);
    Route::post('saveFileAfiliado', [App\Http\Controllers\PadronController::class, 'addFilesAfiliados']);
    Route::post('deleteDetalleDoc', [App\Http\Controllers\PadronController::class, 'deleteDetalleTipoDoc']);

    Route::get('getReportesAfil', [App\Http\Controllers\PadronController::class, 'srvReportesAfiliado']);
    Route::get('getReportesCuadroAfil', [App\Http\Controllers\PadronController::class, 'srvReportesCuadroAfiliado']);
    Route::post('postExportMemo', [App\Http\Controllers\PadronController::class, 'exportarMemo']);
    Route::post('importarExcelPadron', [App\Http\Controllers\PadronController::class, 'importarExcelPadron']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/list'
], function () {
    Route::get('beneficiario', [App\Http\Controllers\BeneficiarioController::class, 'getBeneficiario']);
    Route::get('cpostal', [App\Http\Controllers\CpostalController::class, 'getCpostal']);
    Route::get('estadocivil', [App\Http\Controllers\EstadoCivilController::class, 'getEstadoCivil']);
    Route::get('nacionalidad', [App\Http\Controllers\NacionalidadController::class, 'getNacionalidad']);
    Route::get('sexo', [App\Http\Controllers\SexoController::class, 'getSexo']);
    Route::get('provincia', [App\Http\Controllers\ProvinciasController::class, 'getProvincia']);
    Route::get('tipoDocumento', [App\Http\Controllers\TipoDocumentoController::class, 'getTipoDocumento']);
    Route::get('tipoPlan', [App\Http\Controllers\TipoPlanController::class, 'getPlan']);
    Route::get('tipoDomicilio', [App\Http\Controllers\TipoDomicilioController::class, 'getTipoDomicilio']);
    Route::get('situacionRevista', [App\Http\Controllers\SituacionRevistaController::class, 'getSituacion']);
    Route::get('actividadEconomica', [App\Http\Controllers\ActividadEconomicaController::class, 'getActividadEconomica']);
    Route::get('parentesco', [App\Http\Controllers\ParentescoController::class, 'getParentesco']);
    Route::get('listPartidos/{idProv}', [App\Http\Controllers\PartidosController::class, 'getPartidos']);
    Route::get('listLocalidad/{idProv}/{idParido}', [App\Http\Controllers\LocalidadController::class, 'getLocalidad']);
    Route::get('listDelegacion', [App\Http\Controllers\DelegadosController::class, 'getDelegacion']);
    Route::get('listPeriodo', [App\Http\Controllers\PeriodoController::class, 'getPeriodo']);
    Route::get('listTipoDiscapacidad', [App\Http\Controllers\tipoDiscapacidadController::class, 'getTipoDiscapacidad']);
    Route::get('listTipoDocumentoAfiliado', [App\Http\Controllers\TipoDocumentacionAfiliadoController::class, 'getTipoDocumentacionAfiliado']);
    Route::get('listTipoGerentes', [App\Http\Controllers\GerentesController::class, 'getGerentes']);
    Route::get('listTipoGestoria', [App\Http\Controllers\GestoriaController::class, 'getGestoria']);
    Route::get('listTipoRegimen', [App\Http\Controllers\RegimenController::class, 'getRegimen']);
    Route::get('listTipoSupervisores', [App\Http\Controllers\SupervisoresController::class, 'getSupervisores']);
    Route::get('listTipoCarpeta', [App\Http\Controllers\TipoCarpetaController::class, 'getTipoCarpeta']);
    Route::get('listTipoQr', [App\Http\Controllers\TipoQrController::class, 'getQr']);
    Route::get('listObraSocial', [App\Http\Controllers\ObraSocialController::class, 'getObraSocial']);
    Route::get('listPatalogia', [App\Http\Controllers\patalogiaController::class, 'getPatalogia']);
    Route::get('tipo-coberturas-afiliado', [App\Http\Controllers\prestadores\CoberturaController::class, 'getTipoCoberturaAfiliado']);
    Route::get('listUsuarios', [App\Http\Controllers\RecetasController::class, 'getListuser']);
    Route::get('listFilial', [App\Http\Controllers\FilialController::class, 'getFilial']);
    Route::get('listAutorizacion', [App\Http\Controllers\EstadoAutorizacionController::class, 'getAutorizacion']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/Superintendencia'
], function () {
    Route::post('saveSuperintendencia', [App\Http\Controllers\SuperIntendencia\Services\SuperIntendenciaImportarPadronController::class, 'getImportarPadron']);
    Route::get('ListaSuperPadron', [App\Http\Controllers\SuperPadronController::class, 'getListSuperPadron']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/escolaridad'
], function () {
    Route::post('saveEscolaridad', [App\Http\Controllers\afiliados\Services\AfiliadoEscolaridadController::class, 'saveEscolaridad']);

    Route::get('ver-adjunto-escolaridad', [App\Http\Controllers\afiliados\Services\AfiliadoEscolaridadController::class, 'getVerAdjunto']);
    Route::get('listaEscolaridad/{id}', [App\Http\Controllers\afiliados\Services\AfiliadoEscolaridadController::class, 'getEscolaridad']);
    Route::get('cs-escolaridad-afiliado', [App\Http\Controllers\afiliados\Services\AfiliadoEscolaridadController::class, 'getListarEscolaridad']);
    Route::get('obtener-escolaridad-afiliado', [App\Http\Controllers\afiliados\Services\AfiliadoEscolaridadController::class, 'getBuscarId']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/discapacidad'
], function () {
    Route::get('cs-discapacidad-afiliado', [App\Http\Controllers\afiliados\Services\AfiliadoDiscapacidadController::class, 'getListarDiscapacidad']);
    Route::get('ver-adjunto-disca', [App\Http\Controllers\afiliados\Services\AfiliadoDiscapacidadController::class, 'getVerAdjunto']);
    Route::get('obtener-discapacidad-afiliado', [App\Http\Controllers\afiliados\Services\AfiliadoDiscapacidadController::class, 'getBuscarId']);
    Route::get('consultar-legajo', [App\Http\Controllers\Discapacidad\LegajoAfiliadoController::class, 'getListarLegajo']);
    Route::get('obtener-legajo', [App\Http\Controllers\Discapacidad\LegajoAfiliadoController::class, 'getObtenerLegajoId']);

    Route::post('saveDiscapacidad', [App\Http\Controllers\afiliados\Services\AfiliadoDiscapacidadController::class, 'saveDiscapacidad']);
    Route::delete('eliminar-legajo', [App\Http\Controllers\Discapacidad\LegajoAfiliadoController::class, 'getEliminarLegajoId']);
    Route::get('getDiscapacidadPadron/{id}', [App\Http\Controllers\DiscapacidadController::class, 'getDiscapacidadIdPadron']);
    Route::get('filterDataCertificadoDiscapacidad', [App\Http\Controllers\DiscapacidadController::class, 'srvFilterData']);
    Route::get('buscarDiscapacidadCertificadoID', [App\Http\Controllers\DiscapacidadController::class, 'getBuscarDiscapacidadCertificado']);
    Route::post('procesar-legajo', [App\Http\Controllers\Discapacidad\LegajoAfiliadoController::class, 'getProcesar']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/discasuper'
], function () {
    Route::post('procesarregistro', [App\Http\Controllers\Discapacidad\DiscapacidadSuperintendenciaController::class, 'srvRegistrarDiscapacidad']);
    Route::post('subirArchivoPresupuesto', [App\Http\Controllers\Discapacidad\DiscapacidadSuperintendenciaController::class, 'srvProcesarPresupuesto']);
    Route::delete('eliminarPresupuesto', [App\Http\Controllers\Discapacidad\DiscapacidadSuperintendenciaController::class, 'srvEliminarPresupuesto']);
    Route::get('filterdata', [App\Http\Controllers\Discapacidad\DiscapacidadSuperintendenciaController::class, 'srvListarData']);
    Route::get('consultar-prestaciones-usuario', [App\Http\Controllers\Discapacidad\DiscapacidadSuperintendenciaController::class, 'getFiltrarPorUsuario']);
    Route::get('generararchivo', [App\Http\Controllers\Discapacidad\DiscapacidadSuperintendenciaController::class, 'generarArchivo']);
    Route::post('importarsucces', [App\Http\Controllers\Discapacidad\DiscapacidadSuperintendenciaController::class, 'srvImportarArchivosOK']);
    Route::post('cargaMasivaFacturas', [App\Http\Controllers\Discapacidad\DiscapacidadSuperintendenciaController::class, 'srvCargaMasivaFacturas']);
    Route::post('importarerroneos', [App\Http\Controllers\Discapacidad\DiscapacidadSuperintendenciaController::class, 'srvImportarArchivosErroneos']);
    Route::post('importarsubsidios', [App\Http\Controllers\Discapacidad\DiscaSubsidiosController::class, 'srvImportarArchivosSubsidio']);
    Route::get('filtersubsidio', [App\Http\Controllers\Discapacidad\DiscapacidadSuperintendenciaController::class, 'onFiltersSubsidios']);
    Route::get('exportsubsidio', [App\Http\Controllers\Discapacidad\DiscapacidadSuperintendenciaController::class, 'export']);
    Route::get('listarDataPresupuesto', [App\Http\Controllers\Discapacidad\DiscapacidadSuperintendenciaController::class, 'srvListarPresupuesto']);
    Route::delete('eliminarPrestacion', [App\Http\Controllers\Discapacidad\DiscapacidadSuperintendenciaController::class, 'srvEliminarPrestacion']);
    Route::get('buscarPrestacionId', [App\Http\Controllers\Discapacidad\DiscapacidadSuperintendenciaController::class, 'srvBuscarPrestacionEdit']);
    Route::get('exportar-rendicion-fondos', [App\Http\Controllers\Discapacidad\RendicionFondosController::class, 'getExportarRendicionFondos']);

    Route::get('disca-tesoreria-facturas', [App\Http\Controllers\Discapacidad\RendicionFondosTesoreriaController::class, 'getfiltrarDataTesoreriafacturas']);
    Route::get('disca-tesoreria-facturas-count', [App\Http\Controllers\Discapacidad\RendicionFondosTesoreriaController::class, 'getObtenerCantidadRegistros']);
    Route::get('disca-tesoreria-id', [App\Http\Controllers\Discapacidad\RendicionFondosTesoreriaController::class, 'getBuscarId']);

    Route::post('procesar-tesoreria', [App\Http\Controllers\Discapacidad\RendicionFondosTesoreriaController::class, 'postProcesar']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/credencial'
], function () {
    Route::post('savecredencial', [App\Http\Controllers\afiliados\Services\AfiliadoCredencialController::class, 'saveCredencial']);
    Route::get('getcredencial/{id}', [App\Http\Controllers\CredencialController::class, 'getCredencial']);
    Route::post('getPrintCarnetFamiliar', [App\Http\Controllers\CredencialController::class, 'printCarnetFamiliar']);
    Route::post('getPrintCarnetPersonal', [App\Http\Controllers\CredencialController::class, 'printCarnetPersonal']);
    Route::post('getPrintCarnetUser', [App\Http\Controllers\CredencialController::class, 'printCarnetUser']);
    Route::post('postUpdateCarnet', [App\Http\Controllers\CredencialController::class, 'postUpdateCarnet']);

    Route::get('obtener-credenciales', [App\Http\Controllers\afiliados\Services\AfiliadoCredencialController::class, 'getId']);
    Route::get('cs-credenciales', [App\Http\Controllers\afiliados\Services\AfiliadoCredencialController::class, 'getListar']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/acceso'
], function () {
    Route::post('postsavemenu', [App\Http\Controllers\AccesosController::class, 'saveAccesos']);
    Route::get('listPerfil', [App\Http\Controllers\AccesosController::class, 'listPerfiles']);
    Route::get('listMenu/{idPerfil}', [App\Http\Controllers\AccesosController::class, 'listMenu']);
    Route::get('listMenuAsignado', [App\Http\Controllers\AccesosController::class, 'getListAccesoMenu']);
    Route::post('updateEstado', [App\Http\Controllers\AccesosController::class, 'estadoMenu']);
    Route::get('validarActualizacionDatos', [App\Http\Controllers\AccesosController::class, 'validarActualizacionDatos']);
    Route::get('getDatosUserDni', [App\Http\Controllers\PadronController::class, 'getDatosUserDashboar']);
    Route::post('otorgar-permiso', [App\Http\Controllers\AccesosController::class, 'otorgarPermiso']);
    Route::get('modulos-perfil', [App\Http\Controllers\AccesosController::class, 'menuAccesoPerfil']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/auditoriaAfil'
], function () {
    Route::get('listAuditoria/{id}', [App\Http\Controllers\AuditoriaAfiliadoController::class, 'getListIDAuditoria']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/comercialAfiliado'
], function () {
    Route::post('saveComercialAfiliado', [App\Http\Controllers\PadronComercialController::class, 'postSavePadronComercial']);
    Route::get('getListIDComercialAfiliado/{id}', [App\Http\Controllers\PadronComercialController::class, 'getIDPadronComercial']);
    Route::post('updateEstadoPadronComercial', [App\Http\Controllers\PadronComercialController::class, 'UpdateEstadoPadron']);
    Route::get('getListComercialAfiliado', [App\Http\Controllers\PadronComercialController::class, 'getPadronComercial']);
    Route::get('getListComercialAfiliadoBaja/{id}', [App\Http\Controllers\PadronComercialController::class, 'getPadronComercialBaja']);
    Route::post('postdeletePadronComercial', [App\Http\Controllers\PadronComercialController::class, 'deletePadronComercial']);
    Route::get('getLikePadronComercial', [App\Http\Controllers\PadronComercialController::class, 'getLikePadronComercial']);
    Route::post('saveFamiliarComercialAfiliado', [App\Http\Controllers\GrupoFamiliarComercialController::class, 'savePadronFamiliarComercial']);
    Route::get('getListFamiliarComercialAfiliado/{cuil_tit}', [App\Http\Controllers\GrupoFamiliarComercialController::class, 'getFamiliarAfiliadoComercial']);
    Route::get('getIdFamiliarPadron/{id}', [App\Http\Controllers\GrupoFamiliarComercialController::class, 'getIDFamiliarPadronComercial']);
    Route::post('updateEstadoPadronFamiliarComercial', [App\Http\Controllers\GrupoFamiliarComercialController::class, 'UpdateEstadoFamiliarComercial']);
    Route::post('postdeletePadronFamiliar', [App\Http\Controllers\GrupoFamiliarComercialController::class, 'deletePadronFamiliar']);
    Route::get('getPadronComercialEstado/{id}', [App\Http\Controllers\PadronComercialController::class, 'getPadronComercialEstado']);
    Route::get('getPadronComercialFamiliar/{cuit_titular}', [App\Http\Controllers\PadronComercialController::class, 'getPadronComercialFamiliar']);
    Route::get('getDniPadronComercial', [App\Http\Controllers\PadronComercialController::class, 'getDniPadronComercial']);
    Route::get('getExportPadron', [App\Http\Controllers\PadronComercialController::class, 'exportPadronComercial']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => 'reportes'
], function () {
    Route::get('rptMesaEntradaprint', [App\Http\Controllers\ReportesController::class, 'srvMesaEntrada']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/filterSoporte'
], function () {
    Route::get('listPrioridad', [App\Http\Controllers\FIlterSoporteController::class, 'soportePrioridad']);
    Route::get('listCategoria', [App\Http\Controllers\FIlterSoporteController::class, 'soporteCategoria']);
    Route::get('listEstado', [App\Http\Controllers\FIlterSoporteController::class, 'soporteEstado']);
    Route::get('listInstancia', [App\Http\Controllers\FIlterSoporteController::class, 'soporteInstancia']);
    Route::get('listProductos', [App\Http\Controllers\FIlterSoporteController::class, 'soporteProductos']);
    Route::get('listTareas', [App\Http\Controllers\FIlterSoporteController::class, 'soportetarea']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/filters'
], function () {
    Route::get('mesaEntrada', [App\Http\Controllers\MesaEntradaController::class, 'filtersMesaentrada']);
    Route::get('consultar-usuarios-mesa-entrada', [App\Http\Controllers\MesaEntradaController::class, 'getListarUsuariosMesaEntrada']);
    Route::get('reportMesaEntrada', [App\Http\Controllers\MesaEntradaController::class, 'srvRptMesaEntrada']);
    Route::get('buscarafiliado', [App\Http\Controllers\FiltersController::class, 'srvFilterPadron']);
    Route::get('tipocomprobantes', [App\Http\Controllers\FiltersController::class, 'srvFilterTipoComprobantes']);
    Route::get('provinciadiscapacidad', [App\Http\Controllers\FiltersController::class, 'srvProvinciasDiscapacidad']);
    Route::get('tipoemisioncomprobante', [App\Http\Controllers\FiltersController::class, 'srvFilterTipoEmisionComprobantes']);
    Route::get('practicasdiscapacidad', [App\Http\Controllers\FiltersController::class, 'srvFiltersPracticasDiscapacidad']);
    Route::get('buscarprovedorfilters', [App\Http\Controllers\FiltersController::class, 'srvBuscarProvedor']);
    Route::get('filterDocumentacionPresupuesto', [App\Http\Controllers\FiltersController::class, 'srvListaDocumentacionpresupuesto']);
    Route::get('filterTipoEntidad', [App\Http\Controllers\FiltersController::class, 'srvListaTipoEntidad']);
    Route::get('buscarafiliadoUser', [App\Http\Controllers\FiltersController::class, 'srvFilterPadronUser']);
    Route::get('getListaZona', [App\Http\Controllers\filtros\AlimentadoresController::class, 'getListaZona']);
    Route::get('filterComercialcaja', [App\Http\Controllers\filtros\AlimentadoresController::class, 'getListaComercialCaja']);
    Route::get('filterComercialorigen/{id}', [App\Http\Controllers\filtros\AlimentadoresController::class, 'getListaComercialOrigen']);
    Route::get('getListamotivobaja', [App\Http\Controllers\FiltersController::class, 'srvListaMotivoBaja']);
    Route::get('getlistOrigen', [App\Http\Controllers\FiltersController::class, 'getListOrigen']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/ticketSoporte'
], function () {
    Route::post('postsaveTickets', [App\Http\Controllers\TicketSoporteController::class, 'postSaveTicketsSoporte']);
    Route::get('getlistTicket/{sistema}', [App\Http\Controllers\TicketSoporteController::class, 'getListTickets']);
    Route::get('getlistTicketgeneral', [App\Http\Controllers\TicketSoporteController::class, 'getLitsTicketGeneral']);
    Route::get('getIdTicket/{id}', [App\Http\Controllers\TicketSoporteController::class, 'getIdticket']);

    Route::get('notificaciones', [App\Http\Controllers\Notificaciones\Services\NotificacionesController::class, 'listar']);
    // tickets
    Route::get('getArchivosPorTicket/{id}', [App\Http\Controllers\TicketSoporteController::class, 'getArchivosPorTicket']);
    Route::get('getArchivoAdjunto', [App\Http\Controllers\TicketSoporteController::class, 'getArchivoAdjunto']);
    Route::get('getHistorial/{id}', [App\Http\Controllers\TicketSoporteController::class, 'getHistorial']);
    Route::post('postUpdateasignacion', [App\Http\Controllers\TicketSoporteController::class, 'updateAsignacion']);
    Route::get('getListFechaResponsable', [App\Http\Controllers\TicketSoporteController::class, 'getFechaAndResponsable']);
    Route::post('postUpdateEstado', [App\Http\Controllers\TicketSoporteController::class, 'updateEstado']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/afip'
], function () {
    Route::post('saveAfip', [App\Http\Controllers\AfipController::class, 'saveAfip']);
    Route::get('listAfip', [App\Http\Controllers\AfipController::class, 'listAfip']);
    Route::get('aportes-afiliado', [App\Http\Controllers\Afip\Services\AfipController::class, 'getPorAfiliado']);
    Route::get('listAfipCuit', [App\Http\Controllers\AfipController::class, 'getListCuitFile']);
    Route::get('listAfipCuil', [App\Http\Controllers\AfipController::class, 'getListCuilFile']);
    Route::get('listAfipEmpresa', [App\Http\Controllers\AfipController::class, 'getListNombreEmpresa']);
    Route::get('listAfipFecha', [App\Http\Controllers\AfipController::class, 'getListFecha']);
    Route::get('getExportAfip', [App\Http\Controllers\AfipController::class, 'exportAfip']);
    Route::get('listar-import-compras-afip', [App\Http\Controllers\Afip\Services\ComprobantesAfipCompraController::class, 'getListar']);
    Route::get('listar-import-factus-afip', [App\Http\Controllers\Afip\Services\FacturasAfipController::class, 'getListar']);

    Route::post('importar-compras-afip', [App\Http\Controllers\Afip\Services\ComprobantesAfipCompraController::class, 'getImportarComprobantes']);
    Route::post('importar-facturas-afip', [App\Http\Controllers\Afip\Services\FacturasAfipController::class, 'getImportar']);

    Route::post('getTableroAfip', [App\Http\Controllers\AfipController::class, 'filterTablero']);
    Route::get('getTipoAporte', [App\Http\Controllers\AfipController::class, 'getListTipoAporte']);
    Route::post('getTableroAfipDeudores', [App\Http\Controllers\AfipController::class, 'filterTableroDeudores']);
    Route::get('getListComisiones', [App\Http\Controllers\AfipController::class, 'getListComisiones']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/farmacia'
], function () {
    Route::post('saveFarmacia', [App\Http\Controllers\FarmaciaController::class, 'postSaveFarmacia']);
    Route::get('listfarmacia', [App\Http\Controllers\FarmaciaController::class, 'getListFarmacia']);
    Route::get('listFarmaciaCuit/{cuit}', [App\Http\Controllers\FarmaciaController::class, 'getListFarmaciaCuit']);
    Route::get('listfechafarmacia', [App\Http\Controllers\FarmaciaController::class, 'getFechaFarmacia']);
    Route::get('listLikeFarmacia/{dato}', [App\Http\Controllers\FarmaciaController::class, 'getLikeFarmacia']);
    Route::post('deleteFarmacias', [App\Http\Controllers\FarmaciaController::class, 'postdeleteFarmacia']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/cronicos'
], function () {
    Route::post('saveCronicos', [App\Http\Controllers\afiliados\Services\AfiliadoCronicoController::class, 'postSaveCronicos']);
    Route::get('listcronicos', [App\Http\Controllers\afiliados\Services\AfiliadoCronicoController::class, 'getListCronicos']);
    Route::get('buscar-cronico-afi', [App\Http\Controllers\afiliados\Services\AfiliadoCronicoController::class, 'getBuscarId']);
    Route::get('cs-cronocidad-afil', [App\Http\Controllers\afiliados\Services\AfiliadoCronicoController::class, 'getListar']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/recetas'
], function () {
    Route::post('saveRecetas', [App\Http\Controllers\RecetasController::class, 'postSaveRecetas']);
    Route::get('listRecetas', [App\Http\Controllers\RecetasController::class, 'getListRecetas']);
    Route::get('listLikeVademecum/{nombre}', [App\Http\Controllers\RecetasController::class, 'getBuscramedicamentoVademecum']);
    Route::get('listFilterRecetas/{datos}', [App\Http\Controllers\RecetasController::class, 'getfilterReceta']);
    Route::get('listfechaRecetas', [App\Http\Controllers\RecetasController::class, 'getFechaRecetas']);
    Route::get('getUltimoRegistro', [App\Http\Controllers\RecetasController::class, 'getUltimoRegistro']);
    Route::get('listar-matriz-recetarios', [App\Http\Controllers\RecetasController::class, 'getListarRecetarios']);
    Route::get('listar-recetarios-afiliado', [App\Http\Controllers\RecetasController::class, 'getListarRecetariosAfiliado']);

    Route::post('deleteRecetas', [App\Http\Controllers\RecetasController::class, 'postdeleteRecetas']);
    Route::get('filterDatos/{datos}', [App\Http\Controllers\RecetasController::class, 'filtrarMedico']);
    Route::post('postVademecum', [App\Http\Controllers\RecetasController::class, 'postSaveVademecum']);
    Route::get('exportExcel', [App\Http\Controllers\RecetasController::class, 'getExportRecetas']);
    Route::get('filterUsuarioReceta/{user}', [App\Http\Controllers\RecetasController::class, 'filtrarRecetasUsuario']);
    Route::post('saveEntrega', [App\Http\Controllers\EntregaController::class, 'saveEntrega']);
    Route::get('listEntregas', [App\Http\Controllers\EntregaController::class, 'getListEntregas']);
    Route::get('listfechaentregas', [App\Http\Controllers\EntregaController::class, 'getFechaEntrega']);
    Route::get('listLikeEntrega/{datos}', [App\Http\Controllers\EntregaController::class, 'getLikeEntrega']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/farmanexus'
], function () {
    Route::post('postSaveFarmanexus', [App\Http\Controllers\FarmanexusController::class, 'saveFarmanexus']);
    Route::get('listFarmanexus', [App\Http\Controllers\FarmanexusController::class, 'getFarmanexus']);
    Route::get('listFechaFarmanexus', [App\Http\Controllers\FarmanexusController::class, 'getFechaFarmanexus']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/configuraciones'
], function () {
    Route::post('postsaveAgente', [App\Http\Controllers\AgentesController::class, 'saveAgente']);
    Route::get('getFilterAgente/{id}', [App\Http\Controllers\AgentesController::class, 'filterAgente']);
    Route::get('listTipoAgentes', [App\Http\Controllers\AgentesController::class, 'getAgentes']);
    Route::get('listTipoAgentesActivos', [App\Http\Controllers\AgentesController::class, 'getAgentesActivos']);
    Route::post('updateEstadoagente', [App\Http\Controllers\AgentesController::class, 'updateEstado']);

    Route::post('postsaveLocatorio', [App\Http\Controllers\LocatorioController::class, 'saveLocatorio']);
    Route::get('getFilterLocatorio/{id}', [App\Http\Controllers\LocatorioController::class, 'filterLocatorio']);
    Route::get('listTipoLocatorio', [App\Http\Controllers\LocatorioController::class, 'getLocatorio']);
    Route::get('listTipoLocatorioActivos', [App\Http\Controllers\LocatorioController::class, 'getoLocatorioActivos']);
    Route::post('updateEstadoLocatorio', [App\Http\Controllers\LocatorioController::class, 'updateEstado']);

    Route::post('saveComercialCaja', [App\Http\Controllers\configuracion\ComercialCajaController::class, 'saveComercialCaja']);
    Route::post('updateEstadoComercialCaja', [App\Http\Controllers\configuracion\ComercialCajaController::class, 'updateEstado']);
    Route::post('postsaveComercialCaja', [App\Http\Controllers\configuracion\ComercialCajaController::class, 'saveComercialCaja']);
    Route::get('getIdComercialCaja/{id}', [App\Http\Controllers\configuracion\ComercialCajaController::class, 'getIdComercilaCaja']);
    Route::get('filterComercialcaja', [App\Http\Controllers\configuracion\ComercialCajaController::class, 'getListaComercialCaja']);
    Route::post('deleteComercialCaja', [App\Http\Controllers\configuracion\ComercialCajaController::class, 'deleteComercialCaja']);

    Route::post('saveComercialOrigen', [App\Http\Controllers\configuracion\ComercialOrigenController::class, 'saveComercialOrigen']);
    Route::post('updateEstadoComercialOrigen', [App\Http\Controllers\configuracion\ComercialOrigenController::class, 'updateEstado']);
    Route::post('postsaveComercialOrigen', [App\Http\Controllers\configuracion\ComercialOrigenController::class, 'saveComercialOrigen']);
    Route::get('getIdComercialOrigen/{id}', [App\Http\Controllers\configuracion\ComercialOrigenController::class, 'getIdComercilaOrigen']);
    Route::get('filterComercialOrigen', [App\Http\Controllers\configuracion\ComercialOrigenController::class, 'getListaComercialOrigen']);

    Route::post('postsaveGerenciadora', [App\Http\Controllers\configuracion\GerenciadoraController::class, 'saveGerenciadora']);
    Route::post('updateGerenciadora', [App\Http\Controllers\configuracion\GerenciadoraController::class, 'updateEstado']);
    Route::get('getIdGerenciadora/{id}', [App\Http\Controllers\configuracion\GerenciadoraController::class, 'getIdGerenciadora']);
    Route::get('filterGerenciadora', [App\Http\Controllers\configuracion\GerenciadoraController::class, 'getListaGerenciadora']);

    Route::post('postsaveRazonSocial', [App\Http\Controllers\configuracion\RazonSocialController::class, 'saveRazonSocial']);
    Route::post('updateRazonSocial', [App\Http\Controllers\configuracion\RazonSocialController::class, 'updateEstado']);
    Route::get('getIdRazonSocial', [App\Http\Controllers\configuracion\RazonSocialController::class, 'getIdRazonSocial']);
    Route::get('filterRazonSocial', [App\Http\Controllers\configuracion\RazonSocialController::class, 'getListaRazonSocial']);

    Route::post('postsaveEntidad', [App\Http\Controllers\EntidadesBancariasController::class, 'saveEntidadBancaria']);
    Route::get('getEntidad/{id}', [App\Http\Controllers\EntidadesBancariasController::class, 'filterEntidadBancaria']);
    Route::get('listTipoEntidad', [App\Http\Controllers\EntidadesBancariasController::class, 'getEntidadBancaria']);
    Route::get('listTipoEntidadActivos', [App\Http\Controllers\EntidadesBancariasController::class, 'getEntidadBancariaActivo']);
    Route::post('updateEstadoEntidad', [App\Http\Controllers\EntidadesBancariasController::class, 'updateEstado']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/reintegro'
], function () {
    Route::post('postSaveReintegro', [App\Http\Controllers\ReintegrosController::class, 'saveReintegro']);
    Route::get('listReintegro', [App\Http\Controllers\ReintegrosController::class, 'getLikeReintegro']);
    Route::get('filterReintegro/{id}', [App\Http\Controllers\ReintegrosController::class, 'filterReintegro']);
    Route::get('listFechaReintegro', [App\Http\Controllers\ReintegrosController::class, 'getFechaReintegro']);
    Route::post('deleteReintegro', [App\Http\Controllers\ReintegrosController::class, 'deleteReintegro']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/prestador'
], function () {
    Route::get('buscar-prestador', [App\Http\Controllers\prestadores\PrestadoresController::class, 'srvFilterDataPadronPrestador']);
    Route::get('listarRegimenGanancia', [App\Http\Controllers\prestadores\TipoEfectorController::class, 'getTipoRegimenGanancia']);
    Route::get('listarPrestadorTipoPago', [App\Http\Controllers\prestadores\TipoEfectorController::class, 'getTipoPrestadorPago']);
    Route::get('listarTipoEfector', [App\Http\Controllers\prestadores\TipoEfectorController::class, 'getTipoEfector']);
    Route::get('eliminar', [App\Http\Controllers\mantenimiento\PrestadoresController::class, 'getEliminarPrestador']);
    Route::get('buscarId/{id}', [App\Http\Controllers\prestadores\PrestadoresController::class, 'getBuscarPrestadorId']);
    Route::get('filtrar', [App\Http\Controllers\mantenimiento\PrestadoresController::class, 'getConsultarPrestadores']);
    Route::get('impuestoGanancias', [App\Http\Controllers\filtros\AlimentadoresController::class, 'getListarImpuestoGanancias']);
    Route::get('tipoCondicionIva', [App\Http\Controllers\filtros\AlimentadoresController::class, 'getListarTipoCondicionIva']);
    Route::get('tipoPrestador', [App\Http\Controllers\filtros\AlimentadoresController::class, 'getListarTipoPrestador']);
    Route::get('ubigeoProvincias', [App\Http\Controllers\filtros\AlimentadoresController::class, 'getListarProvincias']);
    Route::get('filtrarPrestadorSelect', [App\Http\Controllers\mantenimiento\PrestadoresController::class, 'getFiltrarPrestador']);
    Route::get('ubigeoProvinciasLocalidades/{id}', [App\Http\Controllers\filtros\AlimentadoresController::class, 'getListarProvinciasLocalidades']);
    Route::get('tipo-imputaciones-contables', [App\Http\Controllers\prestadores\PrestadoresController::class, 'getTipoImputacionContable']);
    Route::get('cs-imputacion-prestador', [App\Http\Controllers\prestadores\PrestadoresImputacionesContablesController::class, 'getListar']);

    Route::post('add-imputacion-prestador', [App\Http\Controllers\prestadores\PrestadoresImputacionesContablesController::class, 'getProcesar']);
    Route::post('add-tipo-imputacion', [App\Http\Controllers\prestadores\PrestadoresImputacionesContablesController::class, 'getProcesarTipo']);
    Route::post('anular-imputacion-prestador', [App\Http\Controllers\prestadores\PrestadoresImputacionesContablesController::class, 'getAnular']);
    Route::post('registrar', [App\Http\Controllers\prestadores\PrestadoresController::class, 'postRegistrarPrestador']);
    Route::post('registro-rapido', [App\Http\Controllers\prestadores\PrestadoresController::class, 'postProcesarPrestadorFlash']);

    Route::post('postsaveHospital', [App\Http\Controllers\prestadores\HospitalPublicoController::class, 'saveHospital']);
    Route::get('getIdHospital/{id}', [App\Http\Controllers\prestadores\HospitalPublicoController::class, 'getIdHospital']);
    Route::get('filterHospital', [App\Http\Controllers\prestadores\HospitalPublicoController::class, 'getListaHospital']);

    Route::get('/facturas-impagas/excel', [App\Http\Controllers\prestadores\ReportesPrestadorController::class, 'exportarExcelFacturasImpagas']);
    Route::get('/facturas-impagas/resumen/excel', [App\Http\Controllers\prestadores\ReportesPrestadorController::class, 'exportarExcelResumenFacturasImpagas']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/profesionales'
], function () {
    Route::get('tipoMatriculas', [App\Http\Controllers\filtros\AlimentadoresController::class, 'getListarTipoMatriculas']);
    Route::get('especialidadesMedicas', [App\Http\Controllers\filtros\AlimentadoresController::class, 'getListarEspecialidadesMedicas']);

    Route::post('registrar', [App\Http\Controllers\prestadores\PrestadoresMedicosController::class, 'postCrearProfesional']);
    Route::post('crear-flash', [App\Http\Controllers\Profesionales\ProfesionalMedicoController::class, 'getRegistroRapido']);

    Route::get('buscarId/{id}', [App\Http\Controllers\prestadores\PrestadoresMedicosController::class, 'getBuscarProfesionalId']);
    Route::get('filtrar', [App\Http\Controllers\prestadores\PrestadoresMedicosController::class, 'getConsultarProfesionales']);
    Route::get('buscarProfesionalesPrestador', [App\Http\Controllers\prestadores\PrestadoresMedicosController::class, 'getBuscarProfesionalesSegunPrestador']);
    Route::get('all-profesionales-prestador', [App\Http\Controllers\prestadores\PrestadoresMedicosController::class, 'getListarTodoPrestadorProfesional']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/prestaciones'
], function () {
    Route::get('buscarPracticaLaboratorio', [App\Http\Controllers\filtros\FiltrosPracticasLaboratorioController::class, 'getBuscarTipoPractica']);
    Route::get('listarMotivosRechazos', [App\Http\Controllers\filtros\FiltrosPracticasLaboratorioController::class, 'getListarMotivosRechazos']);
    Route::get('obtenerPracticaLaboratorioId/{id}', [App\Http\Controllers\filtros\FiltrosPracticasLaboratorioController::class, 'getBuscarPracticaId']);
    Route::get('buscarPrestacionesDNI', [App\Http\Controllers\mantenimiento\PrestacionesController::class, 'getBuscarPrestacionesDNI']);
    Route::get('getImprimirPrestacion', [App\Http\Controllers\mantenimiento\PrestacionesController::class, 'getImprimirReporte']);
    Route::get('getImprimirPrestacionRN', [App\Http\Controllers\mantenimiento\PrestacionesController::class, 'getImprimirReporteRN']);
    Route::get('consultar-prestaciones-medicas', [App\Http\Controllers\PrestacionesMedicas\Services\PrestacionMedicaController::class, 'getConsultarPrestaciones']);
    Route::get('obtener-prestacion-medica', [App\Http\Controllers\PrestacionesMedicas\Services\PrestacionMedicaController::class, 'getBuscarPrestacionId']);
    Route::get('listar-tipo-tramite', [App\Http\Controllers\PrestacionesMedicas\Services\CatalogoPrestacionesMedicasController::class, 'getListarTipoTramites']);
    Route::get('listar-tipo-prioridad', [App\Http\Controllers\PrestacionesMedicas\Services\CatalogoPrestacionesMedicasController::class, 'getListarTipoPrioridad']);
    Route::get('historial-autorizaciones-afiliado', [App\Http\Controllers\PrestacionesMedicas\Services\AuditarPrestacionesMedicasController::class, 'getListarHistorialAutorizacionesAfiliado']);
    Route::get('adjunto-prestacion-medica', [App\Http\Controllers\PrestacionesMedicas\Services\PrestacionMedicaController::class, 'getVerAdjunto']);
    Route::delete('eliminar-item-file', [App\Http\Controllers\PrestacionesMedicas\Services\PrestacionMedicaController::class, 'getEliminarAdjunto']);
    Route::get('export-prestacion-medica', [App\Http\Controllers\PrestacionesMedicas\Services\PrestacionMedicaController::class, 'getExportPrestacion']);

    Route::post('obtenerCostoPractica', [App\Http\Controllers\procesos\ProcesosPrestacionesPracticaLaboratorioController::class, 'postObtenerCostoPractica']);
    Route::post('crear-tipo-prioridad', [App\Http\Controllers\PrestacionesMedicas\Services\CatalogoPrestacionesMedicasController::class, 'getCrearTipoPrioridad']);
    Route::post('crear-tipo-tramite', [App\Http\Controllers\PrestacionesMedicas\Services\CatalogoPrestacionesMedicasController::class, 'getCrearTipoTramite']);
    Route::post('crear-prestacion-medica', [App\Http\Controllers\PrestacionesMedicas\Services\PrestacionMedicaController::class, 'getAltaPrestacionMedica']);
    Route::post('actualizar-prestacion-medica', [App\Http\Controllers\PrestacionesMedicas\Services\PrestacionMedicaController::class, 'getActualizarPrestacion']);
    Route::post('auditar-prestacion-medica', [App\Http\Controllers\PrestacionesMedicas\Services\AuditarPrestacionesMedicasController::class, 'getAuditarPrestacionMedica']);
    Route::post('actualizar-estado-imprimir', [App\Http\Controllers\PrestacionesMedicas\Services\PrestacionMedicaController::class, 'getEstadoImprimirDetalle']);

    Route::delete('eliminar-prestacion', [App\Http\Controllers\PrestacionesMedicas\Services\PrestacionMedicaController::class, 'deleteEliminarPrestacion']);
    Route::delete('eliminar-item-prestacion', [App\Http\Controllers\PrestacionesMedicas\Services\PrestacionMedicaController::class, 'getEliminarItemDetalle']);

    Route::get('listDiagnostico', [App\Http\Controllers\PrestacionesMedicas\Services\DiagnosticoController::class, 'getListarData']);
    Route::post('saveDiagnostico', [App\Http\Controllers\PrestacionesMedicas\Services\DiagnosticoController::class, 'postSaveDiagnostico']);
    Route::get('getListPrestacion', [App\Http\Controllers\PrestacionesMedicas\Services\PrestacionMedicaController::class, 'getListPrestacion']);
    Route::get('getListPrestacionIds', [App\Http\Controllers\PrestacionesMedicas\Services\PrestacionMedicaController::class, 'getListPrestacionIds']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/bonos'
], function () {
    Route::get('obtenerTipobonos', [App\Http\Controllers\filtros\AlimentadoresController::class, 'getListarTipoBonosClinicos']);
    Route::post('crearbono', [App\Http\Controllers\mantenimiento\MantenimientoBonoClinicoController::class, 'postCrearBonoClinico']);
    Route::post('actualizarBono', [App\Http\Controllers\mantenimiento\MantenimientoBonoClinicoController::class, 'putActualizarBonoClinico']);
    Route::delete('eliminarBono/{id}', [App\Http\Controllers\mantenimiento\MantenimientoBonoClinicoController::class, 'deleteEliminarBonoClinico']);
    Route::get('consultar', [App\Http\Controllers\mantenimiento\MantenimientoBonoClinicoController::class, 'getConsultarBonos']);
    Route::get('buscarBonoId/{id}', [App\Http\Controllers\mantenimiento\MantenimientoBonoClinicoController::class, 'getBuscarBonoID']);
    Route::get('buscarBonosAfiliadoDNI', [App\Http\Controllers\mantenimiento\MantenimientoBonoClinicoController::class, 'getBuscarBonosAfiliadoDNI']);
    Route::get('getImprimirPrestacion', [App\Http\Controllers\mantenimiento\MantenimientoBonoClinicoController::class, 'getImprimirReporte']);
    Route::get('consultarUser', [App\Http\Controllers\mantenimiento\MantenimientoBonoClinicoController::class, 'getConsultarBonosUser']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/recetarios'
], function () {
    Route::get('tipoTroquel', [App\Http\Controllers\filtros\AlimentadoresController::class, 'getListaTipoTroquel']);
    Route::get('buscarMonodrogas', [App\Http\Controllers\filtros\AlimentadoresController::class, 'getListarMonodrogas']);
    Route::post('crearRecetario', [App\Http\Controllers\mantenimiento\RecetariosController::class, 'posCrearRecetario']);
    Route::post('updateRecetario', [App\Http\Controllers\mantenimiento\RecetariosController::class, 'putEditarRecetario']);
    Route::get('consultar', [App\Http\Controllers\mantenimiento\RecetariosController::class, 'getConsultarRecetarios']);
    Route::get('buscarRecetaId/{id}', [App\Http\Controllers\mantenimiento\RecetariosController::class, 'getBuscarRecetarioId']);
    Route::post('auditar', [App\Http\Controllers\procesos\AuditarRecetariosController::class, 'postAuditar']);
    Route::get('buscarRecetasAfiliadoDNI', [App\Http\Controllers\mantenimiento\RecetariosController::class, 'getBuscarRecetarioDNI']);
    Route::get('getImprimirRecetario', [App\Http\Controllers\mantenimiento\RecetariosController::class, 'getImprimirReporte']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/internaciones'
], function () {
    Route::get('buscarTipoPrestacion', [App\Http\Controllers\Internaciones\Services\CatalogoInternacionController::class, 'getListarTipoPrestacion']);
    Route::get('buscarTipoInternacion', [App\Http\Controllers\Internaciones\Services\CatalogoInternacionController::class, 'getListarTipoInternacion']);
    Route::get('buscarTipoHabitacion', [App\Http\Controllers\Internaciones\Services\CatalogoInternacionController::class, 'getListarTipoHabitacionInternacion']);
    Route::get('buscarTipoCategoriaInternacion', [App\Http\Controllers\Internaciones\Services\CatalogoInternacionController::class, 'getListarTipoCategoriaInternacion']);
    Route::get('buscarTipoFacturacion', [App\Http\Controllers\Internaciones\Services\CatalogoInternacionController::class, 'getListarTipoFacturacionInternacion']);
    Route::get('buscarTipoEgresoInternacion', [App\Http\Controllers\Internaciones\Services\CatalogoInternacionController::class, 'getListarTipoEgresoInternacion']);
    Route::get('buscarTipoDiagnostico', [App\Http\Controllers\Internaciones\Services\CatalogoInternacionController::class, 'getListarTipoDiagnosticoInternacion']);
    Route::get('buscarTipoDiagnosticoId', [App\Http\Controllers\Internaciones\Services\CatalogoInternacionController::class, 'getListarTipoDiagnosticoInternacionId']);

    Route::get('buscar-internacion-id', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'getId']);
    Route::get('filtrar-internaciones', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'getConsultarInternaciones']);
    Route::get('obtener-internaciones-dni', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'getBuscarInternacionesDNI']);
    Route::get('obtener-internacion-prestaciones', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'getObtenerInternacionId']);

    Route::post('procesar-internacion', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'getProcesarInternacion']);
    Route::post('autorizar-internacion', [App\Http\Controllers\Internaciones\Services\AuditarInternacionController::class, 'getAuditarInternacion']);
    Route::post('crear-tipo-diagnostico-internacion', [App\Http\Controllers\Internaciones\Services\CatalogoInternacionController::class, 'getCrearDiagnostico']);

    Route::delete('eliminar-internacion', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'getEliminarInternacion']);
    Route::post('update-estado-internacion', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'postUpdateEstado']);
    Route::get('validar-internacion', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'validarInternacion']);
    Route::post('save-notas-internacion', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'postSaveNotasInternacion']);

    Route::get('export-internacion', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'getExportInternacion']);
    Route::post('save-prestaciones-autorizadas', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'postSavePrestacionesAutorizadas']);
    Route::post('delete-prestaciones-autorizadas', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'postDeletePrestacionesAutorizadas']);
    Route::post('save-recien-nacido', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'postSaveRN']);
    Route::post('delete-recien-nacido', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'postDeleteRN']);
    Route::post('save-prestaciones-autorizadas-rn', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'postSavePrestacionesAutorizadasRN']);
    Route::get('list-prestaciones-autorizadas-rn', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'getListAutorizadasRN']);
    Route::post('delete-prestaciones-autorizadas-rn', [App\Http\Controllers\Internaciones\Services\InternacionesController::class, 'postDeletePrestacionesAutorizadasRN']);

    // Rutas para la Autorización de Recién Nacido
    Route::get('autorizaciones-rn', [App\Http\Controllers\Internaciones\Services\AutorizacionDatosRNController::class, 'getConsultarAutorizaciones']);
    Route::get('autorizacion-rn', [App\Http\Controllers\Internaciones\Services\AutorizacionDatosRNController::class, 'getObtenerAutorizacion']);
    Route::post('save-autorizacion-rn', [App\Http\Controllers\Internaciones\Services\AutorizacionDatosRNController::class, 'postGuardarAutorizacion']);
    Route::delete('delete-autorizacion-rn', [App\Http\Controllers\Internaciones\Services\AutorizacionDatosRNController::class, 'deleteEliminarAutorizacion']);
    Route::post('migrar-autorizaciones-rn', [App\Http\Controllers\Internaciones\Services\AutorizacionDatosRNController::class, 'postMigrarAutorizaciones']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/historia-clinica'
], function () {
    Route::get('tipoAlergias', [App\Http\Controllers\filtros\AlimentadoresController::class, 'getListaTipoAlergias']);
    Route::get('tipoEstado', [App\Http\Controllers\filtros\FiltrosInternacionesController::class, 'listEstadoInternacion']);
    Route::post('crearHistoriaClinica', [App\Http\Controllers\mantenimiento\HistoriaClinicaController::class, 'postCrearHistoriaClinica']);
    Route::post('updateHistoriaClinica', [App\Http\Controllers\mantenimiento\HistoriaClinicaController::class, 'putUpdateHistoriaClinica']);
    Route::get('consultar', [App\Http\Controllers\mantenimiento\HistoriaClinicaController::class, 'getListarHistoriaClinica']);
    Route::get('buscarHistoriaClinicaId/{id}', [App\Http\Controllers\mantenimiento\HistoriaClinicaController::class, 'getHistoriaClinicaId']);
    Route::delete('deleteHistoriaClinicaId/{id}', [App\Http\Controllers\mantenimiento\HistoriaClinicaController::class, 'deleteHistoriaClinica']);
    Route::get('getBuscarHistoriaClinicaDNI', [App\Http\Controllers\mantenimiento\HistoriaClinicaController::class, 'getBuscarHistoriaClinicaAfiliadoDNI']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/transacciones'
], function () {
    Route::post('postSaveTransacciones', [App\Http\Controllers\TransaccionesController::class, 'saveTransacciones']);
    Route::get('listTransacciones', [App\Http\Controllers\TransaccionesController::class, 'getTransacciones']);
    Route::get('listFechaTransacciones', [App\Http\Controllers\TransaccionesController::class, 'getFechaTransacciones']);
    Route::get('getNumReceta', [App\Http\Controllers\TransaccionesController::class, 'getTransaccionesNumReceta']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/especialidad'
], function () {
    Route::post('postSaveEspecialidad', [App\Http\Controllers\medicos\EspecialidadController::class, 'saveEspecialidad']);
    Route::get('listEspecialidad', [App\Http\Controllers\medicos\EspecialidadController::class, 'getListEspecialidad']);
    Route::get('listLikeEspecialidad/{dato}', [App\Http\Controllers\medicos\EspecialidadController::class, 'getLikeEspecialidad']);
    Route::delete('deleteEspecialidad', [App\Http\Controllers\medicos\EspecialidadController::class, 'deleteEspecialidad']);
    Route::post('postUpdateEstado', [App\Http\Controllers\medicos\EspecialidadController::class, 'updateEstado']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/centromedico'
], function () {
    Route::post('postCentromedico', [App\Http\Controllers\medicos\CentrosMedicosController::class, 'saveCentroMedico']);
    Route::get('listCentromedico', [App\Http\Controllers\medicos\CentrosMedicosController::class, 'getListCentroMedico']);
    Route::get('listLikeCentromedico/{dato}', [App\Http\Controllers\medicos\CentrosMedicosController::class, 'getLikeEntrega']);
    Route::delete('deleteCentromedico', [App\Http\Controllers\medicos\CentrosMedicosController::class, 'deleteCentroMedico']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/medicos'
], function () {
    Route::post('postmedico', [App\Http\Controllers\medicos\MedicoController::class, 'saveMedico']);
    Route::get('listmedico', [App\Http\Controllers\medicos\MedicoController::class, 'getListMedico']);
    Route::get('listLikemedico/{dato}', [App\Http\Controllers\medicos\MedicoController::class, 'getLikeMedico']);
    Route::delete('deletemedico', [App\Http\Controllers\medicos\MedicoController::class, 'deleteMedico']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/turno'
], function () {
    Route::post('postturno', [App\Http\Controllers\medicos\TurnosController::class, 'saveTurnos']);
    Route::get('listturno', [App\Http\Controllers\medicos\TurnosController::class, 'getListTurnos']);
    Route::get('listLiketurno/{dato}', [App\Http\Controllers\medicos\TurnosController::class, 'getLikeTurnos']);
    Route::delete('deleteturno', [App\Http\Controllers\medicos\TurnosController::class, 'deleteTurnos']);
    Route::get('getfiltrarFecha', [App\Http\Controllers\medicos\TurnosController::class, 'getFechaTurno']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/convenios'
], function () {
    Route::get('obtenerCategoriaPagos', [App\Http\Controllers\convenios\ConveniosAlimentadoresController::class, 'getListarCategoriaPagos']);
    Route::get('obtenerAltaCategorias', [App\Http\Controllers\convenios\ConveniosAlimentadoresController::class, 'getListarAltaCategorias']);
    Route::get('obtenerTipoValorizacion', [App\Http\Controllers\convenios\ConveniosAlimentadoresController::class, 'getListarTipoValorizacion']);
    Route::get('obtenerPrestadorTipoComprobante', [App\Http\Controllers\convenios\ConveniosAlimentadoresController::class, 'getListarPrestadorTipoComprobante']);
    Route::get('obtenerTipoAlicuotaIva', [App\Http\Controllers\convenios\ConveniosAlimentadoresController::class, 'getListarAlicuotaIva']);
    Route::get('obtenerTipoMediosPagos', [App\Http\Controllers\convenios\ConveniosAlimentadoresController::class, 'getListarTipoMediosPago']);
    Route::get('obtenerTipoCBU', [App\Http\Controllers\convenios\ConveniosAlimentadoresController::class, 'getListarTipoCBU']);
    Route::get('obtenerTipoPropuesta', [App\Http\Controllers\convenios\ConvenioNegociacionTipoPropuestaController::class, 'getListarTipoPropuesta']);
    Route::get('obtenerTipoRespuesta', [App\Http\Controllers\convenios\ConvenioNegociacionTipoRespuestaController::class, 'getListarTipoRespuesta']);
    Route::get('obtenerTipoSectores', [App\Http\Controllers\convenios\ConvenioNegociacionTipoSectoresController::class, 'getListarTipoSectores']);
    Route::get('obtenerModulos', [App\Http\Controllers\convenios\ConvenioInclusionExclusionController::class, 'getListarModulosTipo']);

    Route::get('buscarConveniosId/{id}', [App\Http\Controllers\convenios\ConveniosMantenimientoController::class, 'getObtenerConevenioId']);
    Route::get('obtener-convenio-by/{id}', [App\Http\Controllers\convenios\ConveniosMantenimientoController::class, 'getObtenerConevenioIdPrincipal']);
    Route::get('listarDatos', [App\Http\Controllers\convenios\ConveniosFiltrosController::class, 'getListarConvenios']);
    Route::get('buscarPrestadoresConvenio', [App\Http\Controllers\convenios\ConveniosFiltrosController::class, 'getListarPrestadorConvenio']);
    Route::get('listarConvenioTipoUnidades', [App\Http\Controllers\convenios\ConveniosFiltrosController::class, 'getListarTipoUnidadConvenio']);
    Route::get('listarConveniosPracticas', [App\Http\Controllers\convenios\ConveniosFiltrosController::class, 'getListarPracticasConvenio']);
    Route::get('listarConveniosGalenos', [App\Http\Controllers\convenios\ConvenioGalenosController::class, 'getListarConvenioGalenos']);
    Route::get('listarNormasOprativas', [App\Http\Controllers\convenios\ConveniosNormasOperativasController::class, 'getListarNormasOperativas']);
    Route::get('listarDocumentacion', [App\Http\Controllers\convenios\ConveniosDocumentacionController::class, 'getListarDocumentacion']);
    Route::get('listarConvenioNegociaciones', [App\Http\Controllers\convenios\ConvenioNegociacionesController::class, 'getListarNegociaciones']);
    Route::get('listarConvenioDetalleNegociaciones', [App\Http\Controllers\convenios\ConvenioNegociacionesController::class, 'getListarDetalleNegociaciones']);
    Route::get('listarConvenioNegociacionesRespuestas', [App\Http\Controllers\convenios\ConvenioNegociacionesController::class, 'getListarRespuestasNegociaciones']);
    Route::get('listarDetalleModulo', [App\Http\Controllers\convenios\ConvenioInclusionExclusionController::class, 'getListarDetalleModulo']);
    Route::get('obtenerCabecera', [App\Http\Controllers\convenios\ConvenioInclusionExclusionController::class, 'getBuscarCabecera']);
    Route::get('filtrar-practicas-convenio-prestador', [App\Http\Controllers\convenios\PracticasConvenioController::class, 'getListarPracticasConvenioPrestador']);
    Route::get('costo-practica-convenio', [App\Http\Controllers\convenios\PracticasConvenioController::class, 'getObtenerCostoPractica']);
    Route::get('buscar-contrato', [App\Http\Controllers\convenios\ConveniosController::class, 'getBuscarConvenioExistente']);
    Route::get('agrupa-fecha-vigencia-convenio-practica', [App\Http\Controllers\convenios\PracticasConvenioController::class, 'getAgrupadorFechaPracticas']);

    Route::get('listar-tipos-origen-convenio', [App\Http\Controllers\convenios\TipoOrigenConvController::class, 'getListarData']);
    Route::get('exportar-matriz', [App\Http\Controllers\convenios\PracticasConvenioController::class, 'getExportarMatrizPractica']);

    Route::post('create', [App\Http\Controllers\convenios\ConveniosMantenimientoController::class, 'postCrearConvenio']);
    Route::post('agregarPrestador', [App\Http\Controllers\convenios\ConveniosMantenimientoController::class, 'postAgregarPrestadorConvenio']);
    Route::post('updatePrestador', [App\Http\Controllers\convenios\ConveniosMantenimientoController::class, 'postUpdatePrestadorConvenio']);
    Route::post('update', [App\Http\Controllers\convenios\ConveniosMantenimientoController::class, 'postUpdateConvenio']);
    Route::post('asociarPracticasConvenios', [App\Http\Controllers\convenios\PracticasConvenioController::class, 'postPracticasConvenios']);
    Route::post('asociarGalenosConvenio', [App\Http\Controllers\convenios\ConvenioGalenosController::class, 'postAgregarGalenosConvenio']);
    Route::post('actualizarGalenoConvenio', [App\Http\Controllers\convenios\ConvenioGalenosController::class, 'postActualizarGalenosConvenio']);
    Route::post('actualizarMontosManualesPracticaConvenio', [App\Http\Controllers\convenios\PracticasConvenioController::class, 'updateMontoPracticaConvenio']);
    Route::post('guardarNormaOperativa', [App\Http\Controllers\convenios\ConveniosNormasOperativasController::class, 'postCargarNormaOperativa']);
    Route::post('guardarDocumentacion', [App\Http\Controllers\convenios\ConveniosDocumentacionController::class, 'postCargarDocumentacion']);
    Route::post('leerArchivo', [App\Http\Controllers\convenios\ConveniosNormasOperativasController::class, 'getObtenerArchivo']);
    Route::post('leerArchivoDocumentacion', [App\Http\Controllers\convenios\ConveniosDocumentacionController::class, 'getObtenerArchivoDocumentacion']);
    Route::post('agregarNegociacion', [App\Http\Controllers\convenios\ConvenioNegociacionesController::class, 'postCrearNegociacion']);
    Route::post('actualizarNegociacion', [App\Http\Controllers\convenios\ConvenioNegociacionesController::class, 'postActualizarNegociacion']);
    Route::post('crearDetalleNegociacion', [App\Http\Controllers\convenios\ConvenioNegociacionesController::class, 'postCrearDetalleNegociacion']);
    Route::post('crearRespuestaNegociacion', [App\Http\Controllers\convenios\ConvenioNegociacionesController::class, 'postNuevaRespuestaNegociacion']);
    Route::post('cargaMasivaPracticas', [App\Http\Controllers\convenios\PracticasConvenioController::class, 'postCargaMasivaPracticas']);
    Route::post('cargarModulo', [App\Http\Controllers\convenios\ConvenioInclusionExclusionController::class, 'postCrearModulo']);
    Route::post('cargarDetalleModulo', [App\Http\Controllers\convenios\ConvenioInclusionExclusionController::class, 'postCrearDetalleModulo']);
    Route::delete('eliminarInclucion', [App\Http\Controllers\convenios\ConvenioInclusionExclusionController::class, 'getEliminarDetalle']);
    Route::post('agregarIncluExclu', [App\Http\Controllers\convenios\ConvenioInclusionExclusionController::class, 'postAgregarInclusionExclusion']);
    Route::post('leerArchivoCabecera', [App\Http\Controllers\convenios\ConvenioInclusionExclusionController::class, 'getObtenerArchivoCabecera']);
    Route::post('aplicarajustelineal', [App\Http\Controllers\convenios\PracticasConvenioController::class, 'getAplicarAjusteLineal']);
    Route::post('registrar-observacion', [App\Http\Controllers\convenios\PracticasConvenioController::class, 'getInsertarObservacion']);

    Route::delete('eliminarConvenio/{id}', [App\Http\Controllers\convenios\ConveniosMantenimientoController::class, 'deleteConvenioId']);
    Route::delete('eliminarConvenioPrestador/{id}', [App\Http\Controllers\convenios\ConveniosMantenimientoController::class, 'deleteConvenioPrestadorId']);
    Route::delete('eliminarConvenioGaleno', [App\Http\Controllers\convenios\ConvenioGalenosController::class, 'eliminarGalenoConvenio']);
    Route::delete('eliminarNormaOperativa', [App\Http\Controllers\convenios\ConveniosNormasOperativasController::class, 'eliminarNormaOperativa']);
    Route::delete('eliminarDocumentacion', [App\Http\Controllers\convenios\ConveniosDocumentacionController::class, 'eliminarDocumentacion']);
    Route::delete('eliminarItemDetalleNegociacion', [App\Http\Controllers\convenios\ConvenioNegociacionesController::class, 'eliminarItemDetalle']);
    Route::delete('eliminarItemRespuestaNegociacion', [App\Http\Controllers\convenios\ConvenioNegociacionesController::class, 'eliminarItemRespuesta']);
    Route::delete('eliminarNegociacion', [App\Http\Controllers\convenios\ConvenioNegociacionesController::class, 'eliminarNegociacion']);
    Route::delete('eliminarPracticaConvenio/{id}', [App\Http\Controllers\convenios\PracticasConvenioController::class, 'postDeletePracticaConvenio']);
    Route::delete('eliminarGalenoMasivo', [App\Http\Controllers\convenios\ConvenioGalenosController::class, 'eliminarGalenoMasivo']);
    Route::post('eliminarPracticasMasivo', [App\Http\Controllers\convenios\PracticasConvenioController::class, 'eliminarPracticaMasivo']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/matriz-practica'
], function () {
    Route::get('filtrarPracticas', [App\Http\Controllers\matrizPracticas\BuscardoresController::class, 'getFiltrarMatriz']);
    Route::get('buscarNomencladores', [App\Http\Controllers\matrizPracticas\BuscardoresController::class, 'getListarNomencladores']);
    Route::get('allsNomencladores', [App\Http\Controllers\matrizPracticas\BuscardoresController::class, 'getListarTodoLossNomencladores']);
    Route::get('buscarSeccionesNomenclador', [App\Http\Controllers\matrizPracticas\BuscardoresController::class, 'getListarSecciones']);
    Route::get('allSeccionesNomenclador', [App\Http\Controllers\matrizPracticas\BuscardoresController::class, 'getListarTodaLasSecciones']);
    Route::get('buscarPracticaPadre', [App\Http\Controllers\matrizPracticas\BuscardoresController::class, 'getListarPracticasPadres']);
    Route::get('nomencladorId', [App\Http\Controllers\matrizPracticas\MantenimientoController::class, 'getNomencladorId']);
    Route::get('buscarPracticasMatriz', [App\Http\Controllers\matrizPracticas\BuscardoresController::class, 'getAdministrarBuscarPracticasMatriz']);
    Route::get('historialPagoConvenioPractica', [App\Http\Controllers\matrizPracticas\BuscardoresController::class, 'getListarHistoricoPagosPracticaConvenio']);
    Route::get('cabecera-historial', [App\Http\Controllers\matrizPracticas\BuscardoresController::class, 'getListarCabeceraHistorial']);
    Route::get('tipo-cobertura', [App\Http\Controllers\matrizPracticas\BuscardoresController::class, 'getListarTipoCobertura']);
    Route::get('tipo-coseguro', [App\Http\Controllers\matrizPracticas\BuscardoresController::class, 'getListarTipoCoseguro']);
    Route::get('obtener-id', [App\Http\Controllers\matrizPracticas\MantenimientoController::class, 'getBuscarPracticaId']);
    Route::get('combo-matriz', [App\Http\Controllers\matrizPracticas\BuscardoresController::class, 'getComboMatriz']);

    Route::post('altaNomenclador', [App\Http\Controllers\matrizPracticas\MantenimientoController::class, 'postAltaNomenclador']);
    Route::post('altaSeccionNomenclador', [App\Http\Controllers\matrizPracticas\MantenimientoController::class, 'postAltaSeccionNomenclador']);
    Route::post('alta-practica', [App\Http\Controllers\matrizPracticas\MantenimientoController::class, 'postAltaPractica']);

    Route::delete('eliminarPractica', [App\Http\Controllers\matrizPracticas\MantenimientoController::class, 'eliminarPractica']);
    Route::delete('eliminarNomenclador', [App\Http\Controllers\matrizPracticas\MantenimientoController::class, 'deleteNomenclador']);
    Route::delete('eliminarSeccionNomenclador', [App\Http\Controllers\matrizPracticas\MantenimientoController::class, 'deleteSeccionNomenclador']);

    Route::get('getExportTractica', [App\Http\Controllers\matrizPracticas\MantenimientoController::class, 'getExportarPractica']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/setting'
], function () {
    Route::get('listarTipoGalenos', [App\Http\Controllers\configuracion\MantenimientoGalenosController::class, 'getListarGalenos']);
    Route::get('listarPlanesGalenos', [App\Http\Controllers\configuracion\MantenimientoGalenosController::class, 'getListarTipoPlanesGalenos']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/importarArchivos'
], function () {
    Route::post('practicas', [App\Http\Controllers\importadorArchivos\ImportadorArchivosController::class, 'getImportarPracticas']);
    Route::post('import-dr-envio', [App\Http\Controllers\Discapacidad\DiscapacidaddrEnvioController::class, 'getImportarDrEnvio']);
    Route::post('import-tesoreria', [App\Http\Controllers\Discapacidad\DiscapacidaddrEnvioController::class, 'getImportarTesoreria']);

    Route::get('certificados', [App\Http\Controllers\importadorArchivos\ImportadorArchivosController::class, 'getImportarCertificados']);
    Route::get('ocr', [App\Http\Controllers\Ocr\PruebaController::class, 'getLeerArchivo']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/recursos'
], function () {
    Route::get('formato-practicas', [App\Http\Controllers\storage\StorageController::class, 'getDescargarFormatoImportadorPracticas']);
    Route::get('formato-liquidaciones', [App\Http\Controllers\storage\StorageController::class, 'getDescargarFormatoImportadorLiquidaciones']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/facturacion'
], function () {
    Route::get('tipos-facturas', [App\Http\Controllers\facturacion\AuxiliaresFacturaController::class, 'getListaTipoFactura']);
    Route::get('tipos-factura-id', [App\Http\Controllers\facturacion\AuxiliaresFacturaController::class, 'getTipoFacturaId']);
    Route::get('tipos-comprobantes', [App\Http\Controllers\facturacion\AuxiliaresFacturaController::class, 'getListaTipoComprobante']);
    Route::get('filtrar-facturas-prestadores', [App\Http\Controllers\facturacion\FacturasPrestadoresController::class, 'getFacturasPrestadores']);
    Route::get('filtrar-facturas-proveedores', [App\Http\Controllers\facturacion\FacturasProveedoresController::class, 'getFacturasProveedoresWithComprobantes']);
    Route::get('filtrar-facturas-ospf', [App\Http\Controllers\facturacion\Services\FacturasOspfController::class, 'getFacturasOspf']);
    Route::get('facturas-pendientes-ospf', [App\Http\Controllers\facturacion\Services\FacturasOspfController::class, 'getFacturasPendientesOspf']);
    Route::get('factura-id', [App\Http\Controllers\facturacion\FacturacionProcesosController::class, 'getBuscarFacturaId']);
    Route::get('tipo-imputacion-contable', [App\Http\Controllers\facturacion\AuxiliaresFacturaController::class, 'getListaTipoImputacionContable']);
    Route::get('tipo-iva', [App\Http\Controllers\facturacion\AuxiliaresFacturaController::class, 'getListaTipoIVA']);
    Route::get('tipo-filtro', [App\Http\Controllers\facturacion\AuxiliaresFacturaController::class, 'getListaTipoFiltro']);
    Route::get('tipo-efector', [App\Http\Controllers\facturacion\AuxiliaresFacturaController::class, 'getListaTipoEfector']);
    Route::get('tipo-letra-factura', [App\Http\Controllers\facturacion\FacturacionTipoLetraController::class, 'getListaTipoLetraFactura']);
    Route::get('tipo-imputacion-sintetizada', [App\Http\Controllers\facturacion\AuxiliaresFacturaController::class, 'getListaTipoImputacioncontableSintetizada']);
    Route::get('factura-completa-id', [App\Http\Controllers\facturacion\FacturasPrestadoresController::class, 'getFacturaCompletaId']);
    Route::get('consultar-numero-factura', [App\Http\Controllers\facturacion\FacturacionProcesosController::class, 'getBuscarNumeroFactura']);
    Route::get('ver-factura', [App\Http\Controllers\facturacion\FacturacionProcesosController::class, 'getVerAdjunto']);
    Route::get('enviar-factura-mail', [App\Http\Controllers\Emails\FacturaProveedorController::class, 'getEnviarMail']);
    Route::get('detalle-comprobantes-factura', [App\Http\Controllers\facturacion\FacturacionProcesosController::class, 'getListarDetalleComprobantes']);

    Route::post('procesar', [App\Http\Controllers\facturacion\FacturacionProcesosController::class, 'postProcesarFactura']);
    // Route para recibir facturas automatizadas (API externa)
    Route::post('automation', [App\Http\Controllers\facturacion\Services\FacturaAutomaticaController::class, 'recibirFactura']);

    Route::post('auditar', [App\Http\Controllers\facturacion\AuditarFacturaController::class, 'postAuditarFactura']);
    Route::post('alta-tipo-letra-factura', [App\Http\Controllers\facturacion\FacturacionTipoLetraController::class, 'getProcesarTipoLetraFactura']);
    Route::post('cambiar-estado', [App\Http\Controllers\facturacion\FacturasPrestadoresController::class, 'getActualizarEstadoLiquidacion']);
    Route::post('cambiar-tipo-detalle-carga', [App\Http\Controllers\facturacion\FacturasPrestadoresController::class, 'updateDetalleCarga']);
    Route::post('cambiar-tipo-imputacion', [App\Http\Controllers\facturacion\FacturasPrestadoresController::class, 'updateImputacion']);

    Route::delete('eliminar-factura', [App\Http\Controllers\facturacion\FacturacionProcesosController::class, 'deleteFacturaDetalle']);
    Route::delete('eliminar-adjunto', [App\Http\Controllers\facturacion\FacturacionProcesosController::class, 'getEliminarAdjunto']);
    Route::delete('del-tipo-letra-factura', [App\Http\Controllers\facturacion\FacturacionTipoLetraController::class, 'getEliminarTipoLetraFactura']);

    Route::get('imprimir-comprobante-facturacion', [App\Http\Controllers\facturacion\FacturacionProcesosController::class, 'printComprobanteFacturacion']);
    Route::get('exportarFactura', [App\Http\Controllers\facturacion\FacturasPrestadoresController::class, 'getExportFacturaPrestador']);
    Route::get('exportarFacturaProveedor', [App\Http\Controllers\facturacion\FacturasPrestadoresController::class, 'getExportFacturaProveedor']);

    Route::get('comprobante_relacionado', [App\Http\Controllers\facturacion\FacturacionProcesosController::class, 'selectComprobanteRelacionado']);

    Route::post('generar-multiple_fc', [App\Http\Controllers\facturacion\FacturasPrestadoresController::class, 'getGenerarMultipleOpa']);
    Route::post('agregar-multiple_fc', [App\Http\Controllers\facturacion\FacturasPrestadoresController::class, 'findAddFacturaMultiple']);
    Route::post('remove-multiple_fc', [App\Http\Controllers\facturacion\FacturasPrestadoresController::class, 'getRemoveMultipleOpa']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/proveedor'
], function () {
    Route::get('filtrar', [App\Http\Controllers\proveedor\ProveedorController::class, 'getFiltrarProveedor']);
    Route::get('proveedor-id', [App\Http\Controllers\proveedor\ProveedorController::class, 'getProveedorId']);
    Route::get('filtrar-matriz', [App\Http\Controllers\proveedor\ProveedorController::class, 'getFiltrarMatrizProveedor']);
    Route::get('id', [App\Http\Controllers\proveedor\ProveedorController::class, 'getProveedorMatrizId']);
    Route::get('vencimiento-pago', [App\Http\Controllers\proveedor\ProveedorController::class, 'getVencimientoPago']);
    Route::get('detalle-imputaciones-proveedor', [App\Http\Controllers\proveedor\ProveedorController::class, 'getListarImputacionesProveedor']);

    Route::post('create-update', [App\Http\Controllers\proveedor\ProveedorController::class, 'postProcesarProveedor']);
    Route::post('alta-rapida-proveedor', [App\Http\Controllers\proveedor\ProveedorController::class, 'postCreateFlash']);
    Route::post('anular-imputaciones-proveedor', [App\Http\Controllers\proveedor\ProveedorController::class, 'getAnularImputacion']);

    Route::delete('eliminar', [App\Http\Controllers\proveedor\ProveedorController::class, 'getEliminarProveedorMatrizId']);
    Route::get('tipo-proveedor', [App\Http\Controllers\proveedor\ProveedorController::class, 'getListTipoProveedor']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/articulos'
], function () {
    Route::get('filtrar', [App\Http\Controllers\articulos\ArcitulosMatrizController::class, 'getFiltrarArticulos']);
    Route::get('articulo-id', [App\Http\Controllers\articulos\ArcitulosMatrizController::class, 'getArticuloId']);
    Route::get('articulo-familia', [App\Http\Controllers\articulos\MantenimientoArticuloController::class, 'getListarFamilia']);
    Route::get('articulo-subfamilia', [App\Http\Controllers\articulos\MantenimientoArticuloController::class, 'getListarSubFamilia']);
    Route::get('articulo-rubro', [App\Http\Controllers\articulos\MantenimientoArticuloController::class, 'getListarRubroFamilia']);
    Route::get('articulo-unidad-medida', [App\Http\Controllers\articulos\MantenimientoArticuloController::class, 'getListarUnidaMedidaArticulo']);

    Route::post('procesar', [App\Http\Controllers\articulos\ArcitulosMatrizController::class, 'getProcesarArticulo']);

    Route::delete('eliminar', [App\Http\Controllers\articulos\ArcitulosMatrizController::class, 'getEliminarArticulo']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/liquidaciones'
], function () {
    Route::post('procesar-adjunto', [App\Http\Controllers\liquidaciones\LiqDocumentosAdjuntosController::class, 'postCargarNormaOperativa']);
    Route::post('procesar-liquidacion-practicas', [App\Http\Controllers\liquidaciones\LiquidacionesController::class, 'postProcesarLiquidacion']);
    Route::post('alta-debito-interno', [App\Http\Controllers\liquidaciones\DebitoInternoController::class, 'postAltaDocumento']);
    Route::post('update-linea', [App\Http\Controllers\liquidaciones\LiquidacionesController::class, 'postUpdateLinea']);
    Route::post('import-detalle-liquid', [App\Http\Controllers\liquidaciones\LiqImportarDatosController::class, 'getImportarLiquidaciones']);
    Route::post('alta-medicamento', [App\Http\Controllers\liquidaciones\LiqMatrizMedicamentosController::class, 'postAlta']);
    Route::post('procesar-liquidacion-medicamentos', [App\Http\Controllers\liquidaciones\LiqMedicamentosController::class, 'postProcesar']);
    Route::post('dictamen-medico', [App\Http\Controllers\liquidaciones\LiqDictamenMedicoController::class, 'getProcesarDictamen']);

    Route::delete('eliminar-adjunto', [App\Http\Controllers\liquidaciones\LiqDocumentosAdjuntosController::class, 'getEliminarArchivo']);
    Route::delete('eliminar-debito', [App\Http\Controllers\liquidaciones\DebitoInternoController::class, 'deleteDebitoInterno']);
    Route::delete('eliminar-liquidacion-detalle', [App\Http\Controllers\liquidaciones\LiquidacionesController::class, 'getEliminarLiquidacionConDetalle']);
    Route::post('eliminar-masivo-liquidacion-detalle', [App\Http\Controllers\liquidaciones\LiquidacionesController::class, 'getEliminarMasivoLiquidacionConDetalle']);
    Route::delete('eliminar-medicamento', [App\Http\Controllers\liquidaciones\LiqMatrizMedicamentosController::class, 'getEliminar']);

    Route::get('obtener-dictamen-medico', [App\Http\Controllers\liquidaciones\LiqDictamenMedicoController::class, 'getBuscarId']);
    Route::get('facturas-liquidadas', [App\Http\Controllers\liquidaciones\LiquidacionesFacturaController::class, 'getFacturaLiquidaciones']);
    Route::get('facturas-liquidadas-detallado-export', [App\Http\Controllers\liquidaciones\LiquidacionesFacturaController::class, 'getFacturaLiquidacionesDetallado']);
    Route::get('facturas-liquidadas-cabecera', [App\Http\Controllers\liquidaciones\LiquidacionesFacturaController::class, 'getCabeceraFacturaLiquidacion']);
    Route::get('obtener-matriz-medicamentos', [App\Http\Controllers\liquidaciones\LiqMatrizMedicamentosController::class, 'getMatriz']);
    Route::get('ver-adjunto', [App\Http\Controllers\liquidaciones\LiqDocumentosAdjuntosController::class, 'getVerAdjunto']);
    Route::get('ver-adjunto-id', [App\Http\Controllers\liquidaciones\LiqDocumentosAdjuntosController::class, 'getById']);
    Route::get('filter-tipo-motivos-debito', [App\Http\Controllers\liquidaciones\LiqTipoMotivoDebitoController::class, 'getListarTipoMotivoDebito']);
    Route::get('liquidaciones-factura', [App\Http\Controllers\liquidaciones\LiquidacionesController::class, 'getLiquidaciones']);
    Route::get('liquidaciones-factura-detallado', [App\Http\Controllers\liquidaciones\LiquidacionesController::class, 'getLiquidacionesDetallado']);
    Route::get('liquidacion-practica-id', [App\Http\Controllers\liquidaciones\LiquidacionesController::class, 'getDatosEditarliquidacionPractica']);
    Route::get('liquidacion-practica-detallado-id', [App\Http\Controllers\liquidaciones\LiquidacionesController::class, 'getDatosEditarliquidacionPracticaDetallado']);
    Route::get('list-debito-interno', [App\Http\Controllers\liquidaciones\DebitoInternoController::class, 'getListarDebitosInternos']);
    Route::post('download-file-debito', [App\Http\Controllers\liquidaciones\DebitoInternoController::class, 'getViewFile']);
    Route::post('download-file-dictamen-medico', [App\Http\Controllers\liquidaciones\LiqDictamenMedicoController::class, 'getViewFile']);
    Route::get('filters-matriz-medicamentos', [App\Http\Controllers\liquidaciones\LiqMatrizMedicamentosController::class, 'getListMatrizActivos']);
    Route::get('datos-editar-liquidacion-medicamento', [App\Http\Controllers\liquidaciones\LiqMedicamentosController::class, 'getObtenerdatosEditarMedicamnetos']);

    Route::get('listar-periodos', [App\Http\Controllers\liquidaciones\LiquidacionesFacturaController::class, 'getPeriodos']);
    Route::get('show-iva-prestador/{cuit}', [App\Http\Controllers\liquidaciones\LiquidacionesFacturaController::class, 'getIvaPrestador']);

    Route::post('download-file-debito_liquidaciones', [App\Http\Controllers\liquidaciones\DebitoInternoController::class, 'getDownloadDebitoLiquidacion']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/padron-afiliado'
], function () {
    Route::get('datos-personales', [App\Http\Controllers\afiliados\PadronAfiliadoController::class, 'getBuscarAfiliadoDNI']);
    Route::get('filtrar-matriz-principal', [App\Http\Controllers\afiliados\PadronAfiliadoController::class, 'getPadronAfiliados']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/protesis'
], function () {
    Route::get('tipo-autorizacion', [App\Http\Controllers\Protesis\Services\TipoAutorizacionController::class, 'getListarVigentes']);
    Route::get('listar-matriz-diagnostico', [App\Http\Controllers\Protesis\Services\CatalogoController::class, 'getListarTipoDiagnostico']);
    Route::get('id-matriz-diagnostico', [App\Http\Controllers\Protesis\Services\CatalogoController::class, 'getTipoDiagnosticoId']);
    Route::get('listar-matriz-productos', [App\Http\Controllers\Protesis\Services\MatrizProductoController::class, 'getListarProductos']);
    Route::get('listar-categorias-productos', [App\Http\Controllers\Protesis\Services\MatrizProductoController::class, 'getListarCategoriasProductos']);
    Route::get('obtener-protesis-id', [App\Http\Controllers\Protesis\Services\ProtesisController::class, 'getBuscarId']);
    Route::get('listar-protesis', [App\Http\Controllers\Protesis\Services\ProtesisFilterController::class, 'getFiltrar']);
    Route::get('afiliado-dni', [App\Http\Controllers\Protesis\Services\ProtesisFilterController::class, 'getListarProtesisAfiliado']);
    Route::get('condicion-protesis', [App\Http\Controllers\Protesis\Services\CatalogoController::class, 'getListarCondicionProtesis']);
    Route::get('estado-solicitud', [App\Http\Controllers\Protesis\Services\CatalogoController::class, 'getListarEstadoSolicitud']);
    Route::get('origen-material', [App\Http\Controllers\Protesis\Services\CatalogoController::class, 'getListarOrigenMaterialProtesis']);
    Route::get('programa-especial', [App\Http\Controllers\Protesis\Services\CatalogoController::class, 'getListarProgramaEspecialProtesis']);
    Route::get('tipo-cobertura', [App\Http\Controllers\Protesis\Services\CatalogoController::class, 'getListarTipoCobertura']);
    Route::get('lista-participantes-licitacion', [App\Http\Controllers\Protesis\Services\DetallePrestadoreslicitacionController::class, 'getObtenerListaParticipantes']);
    Route::get('lista-participantes-licitacion-productos', [App\Http\Controllers\Protesis\Services\DetallePrestadoreslicitacionController::class, 'getListarMatrizParticipantesProductos']);
    Route::get('ver-adjunto-cotizacion', [App\Http\Controllers\Protesis\Services\DetallePrestadoreslicitacionController::class, 'getVerAdjunto']);

    Route::post('crea-update-matriz-diagnostico', [App\Http\Controllers\Protesis\Services\MatrizDiagnosticosController::class, 'getProcesar']);
    Route::post('crea-update-matriz-producto', [App\Http\Controllers\Protesis\Services\MatrizProductoController::class, 'getProcesar']);
    Route::post('procesar-protesis', [App\Http\Controllers\Protesis\Services\ProtesisController::class, 'getProcesar']);
    Route::post('procesar-participantes-licitacion', [App\Http\Controllers\Protesis\Services\DetallePrestadoreslicitacionController::class, 'getProcesarDetalle']);
    Route::post('cargar-archivo-participante-licitacion', [App\Http\Controllers\Protesis\Services\DetallePrestadoreslicitacionController::class, 'getCargarPropuesta']);
    Route::post('cargar-detalle-presupuesto-licitacion', [App\Http\Controllers\Protesis\Services\DetallePrestadoreslicitacionController::class, 'getCargarDetallePropuesta']);
    Route::post('asignar-ganador-licitacion', [App\Http\Controllers\Protesis\Services\DetallePrestadoreslicitacionController::class, 'getAsignarGanadorLicitacion']);

    Route::delete('eliminar-adjunto-cotizacion', [App\Http\Controllers\Protesis\Services\DetallePrestadoreslicitacionController::class, 'getEliminarPropuesta']);
    Route::delete('delete-matriz-diagnostico', [App\Http\Controllers\Protesis\Services\MatrizDiagnosticosController::class, 'getEliminar']);
    Route::delete('delete-matriz-producto', [App\Http\Controllers\Protesis\Services\MatrizProductoController::class, 'getEliminar']);
    Route::delete('delete-protesis', [App\Http\Controllers\Protesis\Services\ProtesisController::class, 'getEliminar']);

    Route::post('savaTipoDiagnostico', [App\Http\Controllers\Protesis\Services\CatalogoController::class, 'getsaveTipoDiagnostico']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/emails'
], function () {
    Route::post('enviar-debito-email', [App\Http\Controllers\Emails\EmailLiquidacionesDebitosController::class, 'getEnviarDebitoProveedor']);
    Route::post('enviar-opa-email', [App\Http\Controllers\Emails\EmailOpaController::class, 'sendEmailOpaProveedor']);
    Route::post('enviar-comprobante-email', [App\Http\Controllers\Emails\EmailFacturacionController::class, 'sendEmailFacturacion']);
    Route::post('enviar-pago-email', [App\Http\Controllers\Emails\EmailPagoController::class, 'sendEmailPagoProveedor']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/autorizacion'
], function () {
    Route::post('postSaveSolicitud', [AutorizacionController::class, 'saveAutorizacion']);
    Route::get('listSolicitud', [AutorizacionController::class, 'getAutorizacion']);
    Route::get('getIdSolicitud/{id}', [AutorizacionController::class, 'filterAutorizacion']);
    Route::get('getLikeSolicitud/{id}', [AutorizacionController::class, 'LikefilterAutorizacion']);
    Route::get('getSolicitudesPorUsuario', [AutorizacionController::class, 'getSolicitudesPorUsuario']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/cartilla'
], function () {
    Route::get('listCartilla', [App\Http\Controllers\CartillaController::class, 'getCartilla']);
    Route::get('getIdcartilla/{id}', [App\Http\Controllers\CartillaController::class, 'getIdCartilla']);
    Route::get('getfilterCartilla', [App\Http\Controllers\CartillaController::class, 'getfilterCartilla']);
    Route::post('saveCartilla', [App\Http\Controllers\CartillaController::class, 'postSaveCartilla']);
    Route::post('deleteCartilla', [App\Http\Controllers\CartillaController::class, 'getfilterpostdeleteCartillaCartilla']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/seguridad'
], function () {
    Route::get('listar-perfiles', [App\Http\Controllers\Seguridad\AdminstrarPerfilesController::class, 'getListarPerfiles']);
    Route::get('listar-usuarios', [App\Http\Controllers\Seguridad\AdministrarUsuariosController::class, 'getListarUsuarios']);

    Route::post('procesar-perfil', [App\Http\Controllers\Seguridad\AdminstrarPerfilesController::class, 'getProcesarPerfil']);
    Route::post('procesar-usuario', [App\Http\Controllers\Seguridad\AdministrarUsuariosController::class, 'getProcesarUsuario']);
    Route::post('cambiar-clave-usuario', [App\Http\Controllers\Seguridad\AdministrarUsuariosController::class, 'getCambiarClaveCuenta']);
    Route::post('habilitar-cuenta-usuario', [App\Http\Controllers\Seguridad\AdministrarUsuariosController::class, 'getHabilitarCuenta']);
    Route::post('deshabilitar-cuenta-usuario', [App\Http\Controllers\Seguridad\AdministrarUsuariosController::class, 'getDeshabilitarCuenta']);

    Route::delete('eliminar-perfil', [App\Http\Controllers\Seguridad\AdminstrarPerfilesController::class, 'getEliminarPerfil']);
    Route::get('listar-roles', [App\Http\Controllers\Seguridad\RolesUsuarioController::class, 'getListarRoles']);
    Route::post('save-roles', [App\Http\Controllers\Seguridad\RolesUsuarioController::class, 'postSaveRolesPermisos']);
    Route::get('obtener-roles', [App\Http\Controllers\Seguridad\RolesUsuarioController::class, 'getRolesUsuarios']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/tratamiento-prolongado'
], function () {
    Route::get('listar-tratamiento', [App\Http\Controllers\tratamientoProlongado\tratamientoProlongadoController::class, 'getTratamientoProlongado']);
    Route::post('save-tratamiento', [App\Http\Controllers\tratamientoProlongado\tratamientoProlongadoController::class, 'saveTratamiento']);
    Route::delete('eliminar-tratamiento', [App\Http\Controllers\tratamientoProlongado\tratamientoProlongadoController::class, 'deleteTratamiento']);
    Route::get('getId-tratamiento', [App\Http\Controllers\tratamientoProlongado\tratamientoProlongadoController::class, 'getTratamientoId']);
});

Route::group([
    'middleware' => ['api'],
    'prefix' => '/v1/reclamos'
], function ($router) {
    Route::get('getTipoReclamos', [App\Http\Controllers\Reclamos\TipoReclamosController::class, 'getReclamosActivos']);
    Route::get('getUserReclamos', [App\Http\Controllers\Reclamos\ReclamosController::class, 'getReclamosUser']);
    Route::get('getlistReclamos', [App\Http\Controllers\Reclamos\ReclamosController::class, 'getReclamos']);
    Route::post('saveReclamos', [App\Http\Controllers\Reclamos\ReclamosController::class, 'postSaveReclamos']);
    Route::get('getIdReclamos/{id}', [App\Http\Controllers\Reclamos\ReclamosController::class, 'getIdReclamo']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/deudores'
], function () {
    Route::get('listDeudores ', [App\Http\Controllers\deudores\DeudoresController::class, 'getLikeDeudores']);
});

Route::group([
    'middleware' => ['api'],
    'prefix' => '/v1/pmi'
], function ($router) {
    Route::get('getlistFemeninas', [App\Http\Controllers\Pmi\pmiController::class, 'srvFilterPadronFemenino']);
    Route::get('getlistEmbarazo', [App\Http\Controllers\Pmi\TipoEmbarazoController::class, 'getEmbarazo']);
    Route::get('getlistPmi', [App\Http\Controllers\Pmi\pmiController::class, 'getPmi']);
    Route::post('savePmi', [App\Http\Controllers\Pmi\pmiController::class, 'postSavePmi']);
    Route::get('getIdPmi', [App\Http\Controllers\Pmi\pmiController::class, 'getSelectPmi']);
    Route::delete('deletePmi', [App\Http\Controllers\Pmi\pmiController::class, 'postdeletePmi']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/liquidacion'
], function () {
    Route::post('postSaveLiquidacion', [App\Http\Controllers\liquidacion\ImPortLiquidacionController::class, 'saveLiquidacion']);
    Route::get('listLiquidacion', [App\Http\Controllers\liquidacion\ImPortLiquidacionController::class, 'listLiquidacion']);
    Route::get('listLikeCuilLiquidacion', [App\Http\Controllers\liquidacion\ImPortLiquidacionController::class, 'listLikeCuilLiquidacion']);
    Route::get('listLikeCuitLiquidacion', [App\Http\Controllers\liquidacion\ImPortLiquidacionController::class, 'listLikeCuitLiquidacion']);
    Route::get('getExportLiquidacion', [App\Http\Controllers\liquidacion\ImPortLiquidacionController::class, 'exportLiquidacion']);
    Route::get('getListRentabilidad', [App\Http\Controllers\Rentabilidad\RentabilidadController::class, 'getDatosRentabilidad']);
    Route::get('getExportRentabilidad', [App\Http\Controllers\Rentabilidad\RentabilidadController::class, 'exportarRentabilidad']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => 'v1/medicacion-alto-costo'
], function () {
    Route::get('tipoAutorizacion', [App\Http\Controllers\medicacion_alto_costo\TipoAutorizacionController::class, 'getTipoAutorizacion']);
    Route::get('estadoTratamiento', [App\Http\Controllers\medicacion_alto_costo\EstadoTratamientoController::class, 'getEstadoTratamiento']);
    Route::get('estadoPago', [App\Http\Controllers\medicacion_alto_costo\EstadoPagoController::class, 'getEstadoPago']);
    Route::get('modoEntrega', [App\Http\Controllers\medicacion_alto_costo\ModoEntregaController::class, 'getModoEntrega']);
    Route::get('cobertura', [App\Http\Controllers\prestadores\CoberturaController::class, 'getTipoCoberturaAfiliado']);
    Route::get('likeMedicacionAltoCosto', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoControlller::class, 'getLikeMedicacionAltoCosto']);
    Route::post('saveMedicacionAltoCosto', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoControlller::class, 'postSaveMedicacionAltoCosto']);
    Route::get('MedicacionAltoCostoById', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoControlller::class, 'getMedicacionAltoCostoById']);
    Route::get('ver-adjunto', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoControlller::class, 'getVerAdjunto']);
    Route::get('medicacion-por-afiliado', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoControlller::class, 'getAfiliadoMedicamentos']);
    Route::post('saveParticipantesLicitacion', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoPresupuestosController::class, 'postsaveParticipantesLicitacion']);
    Route::get('listaParticipantesLicitacion/{id}', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoPresupuestosController::class, 'getObtenerListaParticipantes']);
    Route::get('listaParticipantesLicitacionProdutos/{id}', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoPresupuestosController::class, 'getObtenerListaParticipantesProductos']);
    Route::post('cargarArchivoLicitacion', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoPresupuestosController::class, 'postCargarArchivoLicitacion']);
    Route::get('verAdjuntoCotizacion', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoPresupuestosController::class, 'getAdjuntoCotizacion']);
    Route::delete('eliminarAdjuntoCotizacion', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoPresupuestosController::class, 'deleteAdjuntoCotizacion']);
    Route::post('saveDetallePresupuesto', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoPresupuestosController::class, 'postSaveDetallePresupuesto']);
    Route::post('asignarGanadorLicitacion', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoPresupuestosController::class, 'postSaveGanadorLicitacion']);
    Route::get('getDataEditMedicacionAltoCosto', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoControlller::class, 'getDataEditMedicacionAltoCosto']);
    Route::delete('deleteMedicacionAltoCosto', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoControlller::class, 'deleteMedicacionAltoCosto']);
    Route::delete('eliminar-item-detalle', [App\Http\Controllers\medicacion_alto_costo\MedicacionAltoCostoControlller::class, 'deleteItemMedicacionAltoCosto']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/derivacion'
], function () {
    Route::get('tipo-sector', [App\Http\Controllers\Derivacion\Services\CatalogoDerivacionController::class, 'getListarTipoSector']);
    Route::get('tipo-paciente', [App\Http\Controllers\Derivacion\Services\CatalogoDerivacionController::class, 'getListarTipoPaciente']);
    Route::get('tipo-derivacion', [App\Http\Controllers\Derivacion\Services\CatalogoDerivacionController::class, 'getListarTipoDerivacion']);
    Route::get('tipo-motivo-traslado', [App\Http\Controllers\Derivacion\Services\CatalogoDerivacionController::class, 'getListarTipoMotivoTraslado']);
    Route::get('tipo-movil-traslado', [App\Http\Controllers\Derivacion\Services\CatalogoDerivacionController::class, 'getListarTipoMovilTraslado']);
    Route::get('tipo-egreso', [App\Http\Controllers\Derivacion\Services\CatalogoDerivacionController::class, 'getListarTipoEgreso']);
    Route::get('tipo-requistos-extras', [App\Http\Controllers\Derivacion\Services\CatalogoDerivacionController::class, 'getListarTipoRequisitosExtras']);
    Route::get('obtener-solicitudes', [App\Http\Controllers\Derivacion\Services\DerivacionesFiltersController::class, 'getListar']);
    Route::get('obtener-derivacion-id', [App\Http\Controllers\Derivacion\Services\DerivacionController::class, 'getBuscarId']);
    Route::get('lista-participantes-licitacion', [App\Http\Controllers\Derivacion\Services\SolicitarPresupuestoDerivacionService::class, 'getObtenerListaParticipantes']);
    Route::get('ver-adjunto-cotizacion', [App\Http\Controllers\Derivacion\Services\SolicitarPresupuestoDerivacionService::class, 'getVerAdjunto']);
    Route::get('buscar-derivaciones-afiliado', [App\Http\Controllers\Derivacion\Services\DerivacionesFiltersController::class, 'getDerivacionesAfiliadoDni']);

    Route::delete('eliminar-adjunto-cotizacion', [App\Http\Controllers\Derivacion\Services\SolicitarPresupuestoDerivacionService::class, 'getEliminarPropuesta']);

    Route::post('cargar-archivo-participante-licitacion', [App\Http\Controllers\Derivacion\Services\SolicitarPresupuestoDerivacionService::class, 'getCargarPropuesta']);
    Route::post('procesar', [App\Http\Controllers\Derivacion\Services\DerivacionController::class, 'getProcesar']);
    Route::post('autorizacion-derivacion', [App\Http\Controllers\Derivacion\Services\DerivacionController::class, 'getAutorizarDerivacion']);
    Route::post('update-estado', [App\Http\Controllers\Derivacion\Services\DerivacionController::class, 'getActulizarEstado']);
    Route::post('procesar-participantes-licitacion', [App\Http\Controllers\Derivacion\Services\SolicitarPresupuestoDerivacionService::class, 'getProcesarDetalle']);
    Route::post('cargar-detalle-presupuesto-licitacion', [App\Http\Controllers\Derivacion\Services\SolicitarPresupuestoDerivacionService::class, 'getCargarDetallePropuesta']);
    Route::post('asignar-ganador-licitacion', [App\Http\Controllers\Derivacion\Services\SolicitarPresupuestoDerivacionService::class, 'getAsignarGanadorLicitacion']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/internacion-domiciliaria'
], function () {
    Route::get('consultar-servicios', [App\Http\Controllers\Internaciones\Services\InternacionDomiciliariaFilterController::class, 'getListarServicios']);
    Route::get('consultar-solicitudes', [App\Http\Controllers\Internaciones\Services\InternacionDomiciliariaFilterController::class, 'getListarSolicitudes']);
    Route::get('obtener-solicitud-id', [App\Http\Controllers\Internaciones\Services\InternacionDomiciliariaFilterController::class, 'getBuscarSolicitudId']);
    Route::get('obtener-participantes-solicitud', [App\Http\Controllers\Internaciones\Services\InternacionDomiciliariaPresupuestoController::class, 'getListarParticipantes']);
    Route::get('particitantes-servicios-solicitud', [App\Http\Controllers\Internaciones\Services\InternacionDomiciliariaPresupuestoController::class, 'getListarParticipantesDetalleServicios']);
    Route::get('ver-adjunto', [App\Http\Controllers\Internaciones\Services\InternacionDomiciliariaPresupuestoController::class, 'getVerAdjunto']);
    Route::get('historial-costo-int-domic', [App\Http\Controllers\Internaciones\Services\InternacionDomiciliariaFilterController::class, 'getListarHistorialCosto']);

    Route::delete('eliminar-adjunto', [App\Http\Controllers\Internaciones\Services\InternacionDomiciliariaPresupuestoController::class, 'getEliminarPropuesta']);

    Route::post('cargar-servicios', [App\Http\Controllers\Internaciones\Services\InternacionDomiciliariaController::class, 'getAgregarServicio']);
    Route::post('cargar-solicitud', [App\Http\Controllers\Internaciones\Services\InternacionDomiciliariaController::class, 'getCargarSolicitud']);
    Route::post('solicitar-presupuesto', [App\Http\Controllers\Internaciones\Services\InternacionDomiciliariaPresupuestoController::class, 'getCargarParticipantesPresupuestos']);
    Route::post('cargar-presupuesto', [App\Http\Controllers\Internaciones\Services\InternacionDomiciliariaPresupuestoController::class, 'getCargarPresupuestos']);
    Route::post('cargar-adjunto', [App\Http\Controllers\Internaciones\Services\InternacionDomiciliariaPresupuestoController::class, 'getCargarAdjunto']);
    Route::post('asignar-ganador', [App\Http\Controllers\Internaciones\Services\InternacionDomiciliariaPresupuestoController::class, 'getAsignarGanador']);
    Route::post('finalizar-solicitud', [App\Http\Controllers\Internaciones\Services\InternacionDomiciliariaController::class, 'getFinalizar']);

    Route::get('tipoEstado', [App\Http\Controllers\filtros\FiltrosInternacionesController::class, 'listEstadoInternacion']);
});

Route::group(
    [
        'middleware' => ['jwt.verify'],
        'prefix' => '/v1/tesoreria'
    ],
    function () {
        Route::get('entidades-bancarias', [App\Http\Controllers\Tesoreria\Services\TesCuentasFilterController::class, 'getListarEntidadesBancarias']);
        Route::get('tipo-cuentas', [App\Http\Controllers\Tesoreria\Services\TesCuentasFilterController::class, 'getListarTipoCuentas']);
        Route::get('tipo-monedas', [App\Http\Controllers\Tesoreria\Services\TesCuentasFilterController::class, 'getListarTipoMonedas']);
        Route::get('buscar-cuentas-bancarias', [App\Http\Controllers\Tesoreria\Services\TesCuentasFilterController::class, 'getFiltrar']);
        Route::get('consultar-opa', [App\Http\Controllers\Tesoreria\Services\TesOrdenPagoController::class, 'getFilterOrdenPago']);
        Route::get('tipo-estados-opa', [App\Http\Controllers\Tesoreria\Services\TesOrdenPagoController::class, 'getListTipoEstado']);
        Route::get('obtener-formas-pagos', [App\Http\Controllers\Tesoreria\Services\TesPagosController::class, 'getListarTipoFormaPago']);
        Route::get('consultar-pagos', [App\Http\Controllers\Tesoreria\Services\TesPagosController::class, 'getListarPagos']);
        Route::get('ver-comprobante-pago', [App\Http\Controllers\Tesoreria\Services\TesPagosController::class, 'getVerAdjunto']);
        Route::get('movimientos-cuentas-bancarias', [App\Http\Controllers\Tesoreria\Services\TesCuentasFilterController::class, 'getListarMovimientos']);
        Route::get('tipo-transacciones', [App\Http\Controllers\Tesoreria\Services\TesCuentasFilterController::class, 'getListarTipoTransaciones']);
        Route::get('consultar-transaciones', [App\Http\Controllers\Tesoreria\Services\TesOperacionesManualesController::class, 'getListarTransaciones']);
        Route::get('cuenta-bancaria-id', [App\Http\Controllers\Tesoreria\Services\TesCuentasFilterController::class, 'findById']);
        Route::get('consultar-conciliacion-bancarias', [App\Http\Controllers\Tesoreria\Services\TesConciliacionBancariaController::class, 'getListar']);
        Route::get('consultar-extractos-bancarios', [App\Http\Controllers\Tesoreria\Services\TesExtractosBacariosController::class, 'getFiltrar']);
        Route::get('detalle-pagos-confirmados-opa', [App\Http\Controllers\Tesoreria\Services\TesPagosController::class, 'getListarDetallePago']);
        Route::get('ver-comp-ope-manual', [App\Http\Controllers\Tesoreria\Services\TesOperacionesManualesController::class, 'getVerAdjunto']);
        Route::get('filtrar-cheques', [App\Http\Controllers\Tesoreria\Services\TesChequesController::class, 'getListarCheques']);
        Route::get('ver-comp-cheque', [App\Http\Controllers\Tesoreria\Services\TesChequesController::class, 'getVerAdjunto']);
        Route::get('validar-saldo-cuenta', [App\Http\Controllers\Tesoreria\Services\TesCuentasController::class, 'getValidarSaldoCuenta']);

        Route::post('procesar-cuenta-bancaria', [App\Http\Controllers\Tesoreria\Services\TesCuentasController::class, 'getProcesarCuenta']);
        Route::post('bloquear-cuenta-bancaria', [App\Http\Controllers\Tesoreria\Services\TesCuentasBloqueoController::class, 'getBloquear']);
        Route::post('procesar-opa', [App\Http\Controllers\Tesoreria\Services\TesOrdenPagoController::class, 'getProcesar']);
        Route::post('modificar-estado-opa', [App\Http\Controllers\Tesoreria\Services\TesOrdenPagoController::class, 'getModificarEstado']);
        Route::post('generar-pago', [App\Http\Controllers\Tesoreria\Services\TesPagosController::class, 'getCrearPago']);
        Route::post('confirmar-pago', [App\Http\Controllers\Tesoreria\Services\TesPagosController::class, 'getConfirmarPago']);
        Route::post('anular-pago', [App\Http\Controllers\Tesoreria\Services\TesPagosController::class, 'getAnularPago']);
        Route::post('operacion-manual', [App\Http\Controllers\Tesoreria\Services\TesOperacionesManualesController::class, 'getNuevaOperacion']);
        Route::post('operacion-manual-enlazar-factura', [App\Http\Controllers\Tesoreria\Services\TesOperacionesManualesController::class, 'getEnalazarFacturaObraSocial']);
        Route::post('procesar-conciliacion-bancaria', [App\Http\Controllers\Tesoreria\Services\TesConciliacionBancariaController::class, 'getProcesar']);
        Route::post('previsualizar-extracto-bancario', [App\Http\Controllers\Tesoreria\Services\TesExtractosBacariosController::class, 'getPrevisualizarExtracto']);
        Route::post('guardar-conciliacion-bancaria', [App\Http\Controllers\Tesoreria\Services\TesExtractosBacariosController::class, 'getGuardarConciliacion']);
        Route::post('ejecutar-cygnus-finance-ai', [App\Http\Controllers\Tesoreria\Services\CygnusFinanceAiController::class, 'ejecutarMotorMatching']);
        Route::post('confirmar-match-extracto', [App\Http\Controllers\Tesoreria\Services\TesExtractosBacariosController::class, 'getConfirmarMatch']);
        Route::post('procesar-cheque', [App\Http\Controllers\Tesoreria\Services\TesChequesController::class, 'getProcesar']);

        Route::get('imprimir-reporte-pago-opa/{id}', [App\Http\Controllers\Tesoreria\Services\TesOrdenPagoController::class, 'printOrderPay']);

        Route::get('exportarOrdenesPago', [App\Http\Controllers\Tesoreria\Services\TesOrdenPagoController::class, 'exportOrdenesPago']);

        Route::post('enviar-email-reporte-pago-opa/{id}', [App\Http\Controllers\Tesoreria\Services\TesOrdenPagoController::class, 'printOrderPay']);

        // Rutas de Cash

        Route::post('postCrear', [App\Http\Controllers\Tesoreria\Services\TesCashEgresosController::class, 'postCrear']);  // Crear nuevo egreso
        Route::get('cash-egresos', [App\Http\Controllers\Tesoreria\Services\TesCashEgresosController::class, 'getListEgresos']);  // Listado general entre fechas
        Route::get('getImputacionesByPrestador/{id}', [App\Http\Controllers\Tesoreria\Services\TesCashEgresosController::class, 'getImputacionesByPrestador']);
        Route::get('cash-egresos/{id}', [App\Http\Controllers\Tesoreria\Services\TesCashEgresosController::class, 'getEgresoById']);  // Obtener un egreso por ID
        Route::delete('eliminarEgreso/{id}', [App\Http\Controllers\Tesoreria\Services\TesCashEgresosController::class, 'eliminarEgreso']);  // Obtener un egreso por ID
        // Route::put('cash-egresos/{id}', [CashEgresosController::class, 'update']); // Actualizar egreso
        Route::delete('anular-cheque', [App\Http\Controllers\Tesoreria\Services\TesChequesController::class, 'anularCheque']);

        // Endpoints de opa facturacion
        Route::get('getFacturasOpaId/{id}', [App\Http\Controllers\Tesoreria\Services\FacturasOpaController::class, 'getFacturasOpa']);  // Obtener un egreso por ID

        // Endpoint de detalle de pagos
        // Endpoints para TesPagoDetalleController
        Route::post('postCrearPagoDetalle', [App\Http\Controllers\Tesoreria\Services\TesPagoDetalleController::class, 'store']);  // Crear nuevo detalle de pago
        Route::put('updatePagoDetalle/{id}', [App\Http\Controllers\Tesoreria\Services\TesPagoDetalleController::class, 'update']);  // Actualizar detalle de pago por ID
        Route::delete('eliminarPagoDetalle/{id}', [App\Http\Controllers\Tesoreria\Services\TesPagoDetalleController::class, 'destroy']);  // Eliminar detalle de pago por ID
        Route::get('getPdfPagoDetalle', [App\Http\Controllers\Tesoreria\Services\TesPagoDetalleController::class, 'generarPdfPagoDetalle']);  // Obtener detalle de pago por ID
        Route::get('pagos-excel', [App\Http\Controllers\Tesoreria\Services\TesPagosController::class, 'exportarExcelPagos']);  // Exportar pagos a Excel

        // Endpoints de retenciones en pagos
        Route::get('retenciones/listar', [App\Http\Controllers\Tesoreria\Services\PagoRetencionesController::class, 'getListarRetenciones']);  // Listar retenciones con filtros
        Route::get('pago-retenciones/{idPago}', [App\Http\Controllers\Tesoreria\Services\PagoRetencionesController::class, 'listar']);  // Listar retenciones de un pago
        Route::get('pago-retencion-regla-vigente', [App\Http\Controllers\Tesoreria\Services\PagoRetencionesController::class, 'getReglaVigente']);  // Obtener regla vigente de una retención
        Route::post('pago-retencion', [App\Http\Controllers\Tesoreria\Services\PagoRetencionesController::class, 'store']);  // Crear nueva retención
        Route::put('pago-retencion/{id}', [App\Http\Controllers\Tesoreria\Services\PagoRetencionesController::class, 'update']);  // Actualizar retención
        Route::delete('pago-retencion/{id}', [App\Http\Controllers\Tesoreria\Services\PagoRetencionesController::class, 'destroy']);  // Eliminar retención

        // Endpoints de saldos - deudas pendientes
        Route::get('saldos-proveedores-prestadores', [App\Http\Controllers\Tesoreria\Services\SaldosController::class, 'getListarProveedoresPrestadoresConDeudas']);  // Lista proveedores/prestadores con deudas
        Route::get('detalle-facturas-pendientes', [App\Http\Controllers\Tesoreria\Services\SaldosController::class, 'getDetalleFacturasPendientes']);  // Detalle de facturas pendientes específicas
        Route::get('resumen-deudas', [App\Http\Controllers\Tesoreria\Services\SaldosController::class, 'getResumenDeudas']);  // Resumen general de deudas
    }
);

Route::group([
    'middleware' => ['api'],
    'prefix' => '/v1/diabetes'
], function ($router) {
    Route::get('tipos-diabetes', [App\Http\Controllers\Diabetes\Services\CatalogoDiabetesController::class, 'getListaTipoDiabetes']);
    Route::get('medicamentos-diabetes', [App\Http\Controllers\Diabetes\Services\CatalogoDiabetesController::class, 'getMedicamentos']);
    Route::get('listar-solicitud-diabetes', [App\Http\Controllers\Diabetes\Services\DiabetesController::class, 'getListarDiabetes']);
    Route::get('buscar-diabete-afi', [App\Http\Controllers\Diabetes\Services\DiabetesController::class, 'getBuscarId']);
    Route::get('cs-diabetes-afil', [App\Http\Controllers\Diabetes\Services\DiabetesController::class, 'getListar']);
    Route::get('matriz-medicamentos-diabetes', [App\Http\Controllers\Diabetes\Services\CatalogoDiabetesController::class, 'getMedicamentosMatriz']);

    Route::post('procesar-medicamento', [App\Http\Controllers\Diabetes\Services\CatalogoDiabetesController::class, 'getProcesarTipo']);
    Route::post('anular-medicamento', [App\Http\Controllers\Diabetes\Services\CatalogoDiabetesController::class, 'getAnularTipoImputacion']);
    Route::post('procesar-solicitud-diabetes', [App\Http\Controllers\Diabetes\Services\DiabetesController::class, 'getProcesar']);
    Route::post('anular-solicitud', [App\Http\Controllers\Diabetes\Services\DiabetesController::class, 'getAnularSolicitud']);

    Route::delete('eliminar-item', [App\Http\Controllers\Diabetes\Services\DiabetesController::class, 'getEliminarItemDetalle']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/utils'
], function () {
    Route::get('limpiar-cache', [App\Http\Controllers\Utils\LimpiarCacheController::class, 'getClearCache']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/plan'
], function () {
    Route::get('listPlangeneral', [App\Http\Controllers\TipoPlanController::class, 'getPlanGeneral']);
    Route::get('selectPlan/{id}', [App\Http\Controllers\TipoPlanController::class, 'filterPlan']);
    Route::post('savePlan', [App\Http\Controllers\TipoPlanController::class, 'savePlan']);
    Route::post('updateEstado', [App\Http\Controllers\TipoPlanController::class, 'updateEstado']);
});

Route::group([
    'middleware' => ['api'],
    'prefix' => '/v1/utils'
], function ($router) {
    Route::get('obtener-correlativo', [App\Http\Controllers\Utils\UtilsController::class, 'getObtenerCorrelativo']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/afiliado-programa-especial'
], function () {
    Route::get('tipo-programa', [App\Http\Controllers\afiliados\Services\ProgramaEspecialController::class, 'tipoPrograma']);
    Route::get('obtener-programa', [App\Http\Controllers\afiliados\Services\ProgramaEspecialController::class, 'programaId']);
    Route::get('consultar-programa', [App\Http\Controllers\afiliados\Services\ProgramaEspecialController::class, 'consultarPrograma']);
    Route::get('obtener-adjunto', [App\Http\Controllers\afiliados\Services\ProgramaEspecialController::class, 'verAdjunto']);

    Route::delete('delete-programa', [App\Http\Controllers\afiliados\Services\ProgramaEspecialController::class, 'eliminarProgramaId']);

    Route::post('procesar', [App\Http\Controllers\afiliados\Services\ProgramaEspecialController::class, 'procesarPrograma']);

    Route::get('selectTipoPrograma/{id}', [App\Http\Controllers\afiliados\Services\ProgramaEspecialController::class, 'filterTipoPrograma']);
    Route::post('saveTipoPrograma', [App\Http\Controllers\afiliados\Services\ProgramaEspecialController::class, 'saveTipoPrograma']);
    Route::post('updateEstadoTipoPrograma', [App\Http\Controllers\afiliados\Services\ProgramaEspecialController::class, 'updateEstado']);
    Route::get('listTipoPrograma', [App\Http\Controllers\afiliados\Services\ProgramaEspecialController::class, 'findByListarTipos']);
    Route::get('listProgramaAfiliado', [App\Http\Controllers\afiliados\Services\ProgramaEspecialController::class, 'getByfindProgramasAfiliados']);
});

Route::group([
    'middleware' => ['api'],
    'prefix' => '/v1/nube-comprabantes'
], function ($router) {
    Route::get('consutar', [App\Http\Controllers\NubeComprobantes\NubeComprobantesController::class, 'consultar']);
    Route::get('ver-documento', [App\Http\Controllers\NubeComprobantes\NubeComprobantesController::class, 'getVerAdjunto']);
    Route::post('procesar', [App\Http\Controllers\NubeComprobantes\NubeComprobantesController::class, 'procesar']);
});

Route::group(
    [
        'middleware' => ['jwt.verify'],
        'prefix' => '/v1/fiscalizacion'
    ],
    function () {
        // Rutas para tb_fisca_cuotas
        Route::get('getListCuotas', [App\Http\Controllers\Fiscalizacion\CuotaController::class, 'getListCuotas']);
        Route::post('procesar-cuota', [App\Http\Controllers\Fiscalizacion\CuotaController::class, 'postSaveCuota']);
        Route::post('postPagarCuota', [App\Http\Controllers\Fiscalizacion\CuotaController::class, 'postPagarCuota']);
        Route::get('cuota/{id}', [App\Http\Controllers\Fiscalizacion\CuotaController::class, 'getCuotaById']);
        // comprobantes de cuotas
        Route::get('getArchivosPorCuota/{id}', [App\Http\Controllers\Fiscalizacion\CuotaController::class, 'getArchivosPorCuota']);
        Route::get('getArchivoAdjuntocuota', action: [App\Http\Controllers\Fiscalizacion\CuotaController::class, 'getArchivoAdjunto']);

        // Rutas para tb_fisca_acuerdo_pago_periodo
        Route::get('acuerdos-pago-periodo', [App\Http\Controllers\Fiscalizacion\AcuerdoPagoPeriodoController::class, 'getListAcuerdosPagoPeriodo']);
        Route::get('acuerdo-pago-periodo/{id}', [App\Http\Controllers\Fiscalizacion\AcuerdoPagoPeriodoController::class, 'getAcuerdoPagoPeriodoById']);
        Route::post('procesar-acuerdo-pago-periodo', [App\Http\Controllers\Fiscalizacion\AcuerdoPagoPeriodoController::class, 'postSaveAcuerdoPagoPeriodo']);

        // Deudas de empressas
        Route::get('buscarDeudasEmpresa/{id}', [App\Http\Controllers\Fiscalizacion\DeudaAporteEmpresaController::class, 'buscarPorEmpresa']);
        Route::get('getListDeudas', [App\Http\Controllers\Fiscalizacion\DeudaAporteEmpresaController::class, 'getListDeudas']);
        Route::get('detalleDeuda', [App\Http\Controllers\Fiscalizacion\DeudaAporteEmpresaController::class, 'detalleDeuda']);
        Route::get('deuda-empresa', [App\Http\Controllers\Fiscalizacion\DeudaAporteEmpresaController::class, 'pdfDeudaEmpresa']);

        // Rutas para tb_fisca_cobranzas
        Route::get('listarCobranzas', [App\Http\Controllers\Fiscalizacion\CobranzaController::class, 'listarCobranzas']);
        Route::get('getCobranzaById/{id}', [App\Http\Controllers\Fiscalizacion\CobranzaController::class, 'getCobranzaById']);
        Route::get('buscarExpedientesEmpresaId/{id}', [App\Http\Controllers\Fiscalizacion\CobranzaController::class, 'getExpedientesById']);
        Route::get('buscarDeudasEmpresaExpediente/{id}', [App\Http\Controllers\Fiscalizacion\CobranzaController::class, 'buscarDeudasPorExpediente']);
        Route::post('procesar-cobranza', [App\Http\Controllers\Fiscalizacion\CobranzaController::class, 'postSaveCobranza']);
        Route::delete('eliminarCobranza/{id}', [App\Http\Controllers\Fiscalizacion\CobranzaController::class, 'eliminarCobranza']);
        // Comprobantes de cobranzas
        Route::get('getArchivoAdjunto', action: [App\Http\Controllers\Fiscalizacion\CobranzaController::class, 'getArchivoAdjunto']);
        Route::get('getArchivosPorCobranza/{id}', [App\Http\Controllers\Fiscalizacion\CobranzaController::class, 'getArchivosPorCobranza']);

        // Rutas para tb_fisca_acuerdo_pago
        Route::get('generarNumeroActa', [App\Http\Controllers\Fiscalizacion\AcuerdoPagoController::class, 'generarNumeroActa']);
        Route::post('crearAcuerdoPago', [App\Http\Controllers\Fiscalizacion\AcuerdoPagoController::class, 'postSaveAcuerdoPago']);
        Route::get('getListAcuerdosPago', [App\Http\Controllers\Fiscalizacion\AcuerdoPagoController::class, 'getListAcuerdosPago']);
        Route::delete('eliminarAcuerdo/{id}', [App\Http\Controllers\Fiscalizacion\AcuerdoPagoController::class, 'eliminarAcuerdo']);
        // Route::get('acuerdo-pago/{id}', [App\Http\Controllers\Fiscalizacion\AcuerdoPagoController::class, 'getAcuerdoPagoById']);

        // Rutas para tb_fisca_cobranza_periodo
        Route::get('cobranzas-periodo', [App\Http\Controllers\Fiscalizacion\CobranzaPeriodoController::class, 'getListCobranzasPeriodo']);
        Route::get('cobranza-periodo/{id}', [App\Http\Controllers\Fiscalizacion\CobranzaPeriodoController::class, 'getCobranzaPeriodoById']);
        Route::post('procesar-cobranza-periodo', [App\Http\Controllers\Fiscalizacion\CobranzaPeriodoController::class, 'postSaveCobranzaPeriodo']);

        // Rutas para tb_fisca_estado_acuerdo
        Route::get('estados-acuerdo', [App\Http\Controllers\Fiscalizacion\EstadoAcuerdoController::class, 'getListEstadosAcuerdo']);
        Route::get('estado-acuerdo/{id}', [App\Http\Controllers\Fiscalizacion\EstadoAcuerdoController::class, 'getEstadoAcuerdoById']);
        Route::post('procesar-estado-acuerdo', [App\Http\Controllers\Fiscalizacion\EstadoAcuerdoController::class, 'postSaveEstadoAcuerdo']);

        // Rutas para tb_fisca_expedientes
        Route::get('expedientes', [App\Http\Controllers\Fiscalizacion\ExpedienteController::class, 'getListExpedientes']);
        Route::get('expediente/{id}', [App\Http\Controllers\Fiscalizacion\ExpedienteController::class, 'getExpedienteById']);
        Route::get('expedienteByIdEmpresa/{id}', [App\Http\Controllers\Fiscalizacion\ExpedienteController::class, 'getExpedientesByIdEmpresa']);
        Route::get('deudaTotal/{id}', [App\Http\Controllers\Fiscalizacion\ExpedienteController::class, 'getDeudaTotalByExpedienteId']);
        Route::get('generar-expediente', [App\Http\Controllers\Fiscalizacion\ExpedienteController::class, 'generarNumeroExpediente']);
        Route::post('postCrearExpediente', [App\Http\Controllers\Fiscalizacion\ExpedienteController::class, 'postSaveExpediente']);

        // Rutas para tb_fisca_intimacion
        Route::get('buscarSeguimientoPorEmpresaFechas', [App\Http\Controllers\Fiscalizacion\IntimacionController::class, 'buscarSeguimientoIntimacion']);
        Route::post('crearIntimacion', [App\Http\Controllers\Fiscalizacion\IntimacionController::class, 'postSaveIntimacion']);
        Route::get('buscarIntimacionId', [App\Http\Controllers\Fiscalizacion\IntimacionController::class, 'getIntimacionById']);
        Route::delete('eliminarIntimacion/{id}', [App\Http\Controllers\Fiscalizacion\IntimacionController::class, 'eliminarIntimacion']);

        // Rutas para tb_fisca_movimientos
        Route::get('obtenerTipoMovimiento', [App\Http\Controllers\Fiscalizacion\MovimientoController::class, 'getListMovimientos']);
        Route::get('movimiento/{id}', [App\Http\Controllers\Fiscalizacion\MovimientoController::class, 'getMovimientoById']);
        Route::post('procesar-movimiento', [App\Http\Controllers\Fiscalizacion\MovimientoController::class, 'postSaveMovimiento']);

        // Rutas de sistema anterior
        Route::get('/seguimientos-anteriores', [App\Http\Controllers\Fiscalizacion\SeguimientoAnteriorController::class, 'getListSeguimientos']);
        Route::post('/contarIntimacionesActivas', [App\Http\Controllers\Fiscalizacion\SeguimientoAnteriorController::class, 'contarIntimacionesActivas']);

        // Rutas de cobranzas anteriores
        Route::get('/cobranzas-anteriores', [App\Http\Controllers\Fiscalizacion\CobranzaAnteriorController::class, 'getListCobranzas']);

        // Rutas de instituciones
        Route::get('instituciones', [App\Http\Controllers\Fiscalizacion\InstitucionesController::class, 'getListInstituciones']);

        // Rutas de bancos
        Route::get('getBancosCobranza', [App\Http\Controllers\Fiscalizacion\BancosCobranzaController::class, 'getListBancosCobranza']);

        // Rutas de Formas de pago
        Route::get('getFormasPago', [App\Http\Controllers\Fiscalizacion\FormasPagoController::class, 'getListFormasPago']);
    }
);

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/contabilidad'
], function ($router) {
    Route::get('cs-periodos-contables', [App\Http\Controllers\Contabilidad\Services\PeriodosContablesService::class, 'getListar']);
    Route::get('cs-periodos-anuales', [App\Http\Controllers\Contabilidad\Services\PeriodosContablesService::class, 'getListarPeriodosAnuales']);
    Route::get('cs-tipo-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\CatalogoController::class, 'getTipoPlanCuenta']);
    Route::get('cs-planes-cuentas', [App\Http\Controllers\Contabilidad\Services\PlanesCuentasController::class, 'getListar']);
    Route::get('obt-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\PlanesCuentasController::class, 'getId']);
    Route::get('cs-tipo-niveles', [App\Http\Controllers\Contabilidad\Services\CatalogoController::class, 'getTipoNiveles']);
    Route::get('cs-tipo-planes-organico', [App\Http\Controllers\Contabilidad\Services\CatalogoController::class, 'getTipoPlanOrganicoCuenta']);
    Route::get('cs-detalle-niveles', [App\Http\Controllers\Contabilidad\Services\PlanesCuentasController::class, 'getListarDetalleNiveles']);
    Route::get('cs-matriz-planes-cuentas', [App\Http\Controllers\Contabilidad\Services\PlanesCuentasController::class, 'getListarMatrizPlanesCuenta']);
    Route::get('cs-proveedor-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\ProveedorPlanesCuentaController::class, 'getListar']);
    Route::get('cs-plan-cuenta-padres', [App\Http\Controllers\Contabilidad\Services\PlanesCuentasController::class, 'getListarCuentasPrincipales']);
    Route::get('cs-forma-pago-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\FormaPagoCuentaContableController::class, 'getListar']);
    Route::get('cs-familia-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\FamiliaPlanesCuentasController::class, 'getListar']);
    Route::get('cs-impuesto-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\ImpuestoCuentaContableController::class, 'getListar']);
    Route::get('cs-cuenta-bancaria-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\BancoCuentaContableController::class, 'getListar']);
    Route::get('cs-asientos-contables', [App\Http\Controllers\Contabilidad\Services\AsientoContableController::class, 'getListar']);
    Route::get('obt-asiento-contable', [App\Http\Controllers\Contabilidad\Services\AsientoContableController::class, 'getBuscarId']);
    Route::get('cs-tipo-retencion', [App\Http\Controllers\Contabilidad\Services\CatalogoController::class, 'getTipoRetencion']);
    Route::get('cs-tipo-impuesto', [App\Http\Controllers\Contabilidad\Services\CatalogoController::class, 'getTipoImpuesto']);
    Route::get('cs-retencion-cuenta-contable', [App\Http\Controllers\Contabilidad\Services\RetencionCuentaContableController::class, 'getListar']);
    Route::get('cs-plan-cuenta-completo', [App\Http\Controllers\Contabilidad\Services\PlanesCuentasController::class, 'getListarCuentasCompleto']);
    Route::get('get-export-plan-cuentas', [App\Http\Controllers\Contabilidad\Services\PlanesCuentasController::class, 'getExportPlanCuentas']);
    Route::get('get-imputaciones-contables', [App\Http\Controllers\Contabilidad\Services\ImputacionCuentaContableController::class, 'getListarTipoImputacionContable']);
    Route::get('get-imputaciones-prestadores', [App\Http\Controllers\Contabilidad\Services\ImputacionCuentaContableController::class, 'getListarTipoImputacionContable']);
    Route::get('get-imputaciones-proveedores', [App\Http\Controllers\Contabilidad\Services\ImputacionProveedoresCuentaContableController::class, 'getListarConFiltros']);
    Route::get('editar-imputacion-proveedor/{id}', [App\Http\Controllers\Contabilidad\Services\ImputacionProveedoresCuentaContableController::class, 'getEditar']);

    Route::post('psr-periodo-contable', [App\Http\Controllers\Contabilidad\Services\PeriodosContablesService::class, 'getProcesar']);
    Route::post('toggle-activo/{id_periodo_contable}', [App\Http\Controllers\Contabilidad\Services\PeriodosContablesService::class, 'toggleActivo']);
    Route::post('toggle-vigente/{id_periodo_contable}', [App\Http\Controllers\Contabilidad\Services\PeriodosContablesService::class, 'toggleVigente']);

    Route::post('relacionar-imputacion-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\ImputacionCuentaContableController::class, 'getProcesar']);
    Route::post('relacionar-imputacion-proveedor-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\ImputacionProveedoresCuentaContableController::class, 'getProcesar']);
    Route::delete('eliminar-imputacion-proveedor', [App\Http\Controllers\Contabilidad\Services\ImputacionProveedoresCuentaContableController::class, 'delete']);
    Route::post('psr-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\PlanesCuentasController::class, 'getProcesar']);
    Route::post('psr-nuevo-nivel', [App\Http\Controllers\Contabilidad\Services\PlanesCuentasController::class, 'getAgregarNivel']);
    Route::post('psr-estructura-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\PlanesCuentasController::class, 'getAgregarItemEstructuraPlanCuenta']);
    Route::post('psr-proveedor-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\ProveedorPlanesCuentaController::class, 'getProcesar']);
    Route::post('psr-forma-pago-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\FormaPagoCuentaContableController::class, 'getProcesar']);
    Route::post('psr-familia-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\FamiliaPlanesCuentasController::class, 'getProcesar']);
    Route::post('psr-cuenta-bancaria-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\BancoCuentaContableController::class, 'getProcesar']);
    Route::post('psr-impuesto-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\ImpuestoCuentaContableController::class, 'getProcesar']);
    Route::post('psr-retencion-cuenta-contable', [App\Http\Controllers\Contabilidad\Services\RetencionCuentaContableController::class, 'getProcesar']);
    Route::post('psr-asiento-contable', [App\Http\Controllers\Contabilidad\Services\AsientoContableController::class, 'getProcesar']);
    Route::post('up-anular-asiento-contable', [App\Http\Controllers\Contabilidad\Services\AsientoContableController::class, 'getAnularAsientoContableId']);

    Route::delete('eliminar-nivel', [App\Http\Controllers\Contabilidad\Services\PlanesCuentasController::class, 'getEliminarNivel']);
    Route::delete('eliminar-item-plan-cuenta', [App\Http\Controllers\Contabilidad\Services\PlanesCuentasController::class, 'getEliminarDetalleItem']);
    Route::delete('eliminar-asiento-detalle-item', [App\Http\Controllers\Contabilidad\Services\AsientoContableController::class, 'getEliminarDetalleId']);
    // libro Diario
    Route::get('cs-resumen-libro-diario', [App\Http\Controllers\Contabilidad\Services\LibroDiarioController::class, 'getListarResumenDiario']);
    Route::get('getReporteLibroDiario', [App\Http\Controllers\Contabilidad\Services\LibroDiarioController::class, 'getReporteLibroDiario']);
    // Libro Mayor
    Route::get('getLibroMayor', [App\Http\Controllers\Contabilidad\Services\LibroMayorController::class, 'getLibroMayor']);
    Route::get('getReporteLibroMayor', [App\Http\Controllers\Contabilidad\Services\LibroMayorController::class, 'getReporteLibroMayor']);
    // Balance
    Route::get('getBalanceSaldo', [App\Http\Controllers\Contabilidad\Services\BalanceController::class, 'getBalanceSaldo']);
    Route::get('getExportarBalanceSaldo', [App\Http\Controllers\Contabilidad\Services\BalanceController::class, 'getExportarBalanceSaldo']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/coseguros'
], function () {
    Route::get('consultar', [App\Http\Controllers\Coseguros\Services\CosegurosController::class, 'consultarCoseguros']);
    Route::post('actualizar-matriz', [App\Http\Controllers\Coseguros\Services\CosegurosController::class, 'actualizarCostos']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/dashboard'
], function () {
    Route::get('/', [App\Http\Controllers\Dashboard\DashboardController::class, 'getDashboard']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/alta-temporal'
], function () {
    Route::Post('save', [App\Http\Controllers\AltaTemporal\AltaTemporalController::class, 'postSavePadron']);
    Route::get('listar', [App\Http\Controllers\AltaTemporal\AltaTemporalController::class, 'getLikePadron']);
    Route::get('obtenerDni', [App\Http\Controllers\AltaTemporal\AltaTemporalController::class, 'getDniPadron']);
    Route::post('getPrintCarnetTemporal', [App\Http\Controllers\AltaTemporal\AltaTemporalController::class, 'printCarnetPersonal']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/chequeras'
], function () {
    Route::get('tipos', [App\Http\Controllers\Tesoreria\Services\ChequerasBancariasController::class, 'tipoChequera']);
    Route::get('historial', [App\Http\Controllers\Tesoreria\Services\ChequerasBancariasController::class, 'historialChequera']);
    Route::get('consultar', [App\Http\Controllers\Tesoreria\Services\ChequerasBancariasController::class, 'listar']);
    Route::post('procesar', [App\Http\Controllers\Tesoreria\Services\ChequerasBancariasController::class, 'proceso']);
    Route::put('estado', [App\Http\Controllers\Tesoreria\Services\ChequerasBancariasController::class, 'estado']);
    Route::delete('eliminar', [App\Http\Controllers\Tesoreria\Services\ChequerasBancariasController::class, 'eliminar']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/dashboard-consumo'
], function () {
    Route::get('buscar-dashboard', [App\Http\Controllers\DashboardConsumo\Dashboard::class, 'getDashboard']);
    Route::get('detalles-consumos-afiliado', [App\Http\Controllers\DashboardConsumo\Dashboard::class, 'getDetallesConsumosAfiliado']);
});


Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/portal-prestadores'
], function () {

    Route::get('facturacion/listar', [App\Http\Controllers\PortalPrestadores\Services\FacturacionPortalController::class, 'listar']);
    Route::get('facturacion/{id}', [App\Http\Controllers\PortalPrestadores\Services\FacturacionPortalController::class, 'obtener']);
    Route::get('estado-facturacion', [App\Http\Controllers\PortalPrestadores\Services\FacturacionPortalController::class, 'listarEstados']);
    Route::get('documentacion-factura', [App\Http\Controllers\PortalPrestadores\Services\FacturacionPortalController::class, 'listarDocumentacion']);
    Route::get('ver-doc-factura', [App\Http\Controllers\PortalPrestadores\Services\FacturacionPortalController::class, 'getVerAdjunto']);

    Route::post('facturacion/crear', [App\Http\Controllers\PortalPrestadores\Services\FacturacionPortalController::class, 'crear']);
    Route::post('modificar-estado-factura', [App\Http\Controllers\PortalPrestadores\Services\FacturacionPortalController::class, 'actualizarEstado']);
    Route::post('documentacion-factura', [App\Http\Controllers\PortalPrestadores\Services\FacturacionPortalController::class, 'cargarDocumentacion']);

    Route::delete('facturacion/{id}', [App\Http\Controllers\PortalPrestadores\Services\FacturacionPortalController::class, 'eliminar']);
});

Route::group([
    'middleware' => ['jwt.verify'],
    'prefix' => '/v1/presupuesto-prestacion-medica'
], function () {
    Route::post('procesar-proveedor', [App\Http\Controllers\PrestacionesMedicas\Services\ProveedorPresupuestosController::class, 'findByProcesar']);
    Route::get('consultar-proveedor', [App\Http\Controllers\PrestacionesMedicas\Services\ProveedorPresupuestosController::class, 'findByConsultar']);

    Route::post('procesar-presupuesto', [App\Http\Controllers\PrestacionesMedicas\Services\PresupuestoPrestacionMedicaController::class, 'procesar']);
    Route::get('consultar-presupuestos', [App\Http\Controllers\PrestacionesMedicas\Services\PresupuestoPrestacionMedicaController::class, 'listar']);
    Route::get('obtener-presupuesto', [App\Http\Controllers\PrestacionesMedicas\Services\PresupuestoPrestacionMedicaController::class, 'findById']);
    Route::post('autoriza-presupuesto', [App\Http\Controllers\PrestacionesMedicas\Services\PresupuestoPrestacionMedicaController::class, 'autoriza']);
    Route::post('anular-presupuesto', [App\Http\Controllers\PrestacionesMedicas\Services\PresupuestoPrestacionMedicaController::class, 'anular']);
    Route::get('obtener-presupuesto-detalle', [App\Http\Controllers\PrestacionesMedicas\Services\PresupuestoPrestacionMedicaController::class, 'findByPresupuestoPrestacion']);
});