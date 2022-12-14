<?php

namespace App\Http\Controllers;

use App\Pagos;
use App\Plan;
use App\Subscripciones;
use \Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Console\Output\ConsoleOutput;
use App\Notifications\VerificationRequestResponded;
use App\Usuario;
use App\Verificaciones;
use App\SolicitudesVerificacion;

class GlobalController extends Controller
{

    public static function log($msj) {
        $output = new ConsoleOutput();
        $output->writeln("<info>$msj</info>");
    }

    public static function edad($fechaInicio) {
        if (!$fechaInicio || is_null($fechaInicio)) {
            return "Fecha de nacimiento no indicada.";
        }

        $diaActual = date("j");
        $mesActual = date("n");
        $anioActual = date("Y");

        $partes = explode('-', $fechaInicio);

        $diaInicio = $partes[2];
        $mesInicio = $partes[1];
        $anioInicio = $partes[0];

        $b = 0;
        $mes = $mesInicio - 1;

        if ($mes == 2) {
            if (($anioActual % 4 == 0 && $anioActual % 100 != 0) || $anioActual % 400 == 0) {
                $b = 29;
            } else {
                $b = 28;
            }
        } else if ($mes <= 7) {
            if ($mes == 0) {
                $b = 31;
            } else if ($mes % 2 == 0) {
                $b = 30;
            } else {
                $b = 31;
            }
        } else if ($mes > 7) {
            if ($mes % 2 == 0) {
                $b = 31;
            } else {
                $b = 30;
            }
        }
        if ($mesInicio <= $mesActual) {
            $anios = $anioActual - $anioInicio;

            if ($diaInicio <= $diaActual) {
                $meses = $mesActual - $mesInicio;
                $dies = $diaActual - $diaInicio;
            } else {
                if ($mesActual == $mesInicio) {
                    $anios = $anios - 1;
                }

                $meses = ($mesActual - $mesInicio - 1 + 12) % 12;
                $dies = $b - ($diaInicio - $diaActual);
            }
        } else {
            $anios = $anioActual - $anioInicio - 1;

            if ($diaInicio > $diaActual) {
                $meses = $mesActual - $mesInicio - 1 + 12;
                $dies = $b - ($diaInicio - $diaActual);
            } else {
                $meses = $mesActual - $mesInicio + 12;
                $dies = $diaActual - $diaInicio;
            }
        }

        $edadFinal = $anios . " a??os, " . $meses . " meses y " . $dies . " dias.";
        return $edadFinal;
    }

    public static function edad_anios($fecha) {
        $tz  = new \DateTimeZone('America/Santiago');

        return \DateTime::createFromFormat('Y-m-d', $fecha, $tz)
            ->diff(new \DateTime('now', $tz))
            ->y;
    }

    public function search(Request $request, $keyword) {

//        DB::enableQueryLog();

        DB::listen(function ($sql) {
            GlobalController::log($sql->sql);
        });

        $usuario = Auth::user();

        $consulta = "
            select u.*
            , coalesce(v.titulo_habilitante_legal, nullif(u.titulo_segun_usuario, ''), 'Sin especificar') as titulo
            , coalesce(v.especialidad, nullif(u.especialidad_segun_usuario, ''), 'Sin especificar') as especialidad
            , s.alias_adulto
            , s.alias_infantil
            , case
                when coalesce(v.habilitado, false) is true then
                    'verified'
                when exists(select 1 from solicitud_verificacion sv where sv.id_usuario = u.id and sv.estado = 0) then
                    'waiting'
                else 'question'
            end as icon
            from usuarios u
            join sexos s
              on s.id = u.id_sexo
            left join verificaciones v 
              on v.id_usuario = u.id 
              and v.habilitado is true
            where (
                translate(u.nombres, '????????????????????????', 'aeiouAEIOUNn') ilike '%' || translate(:keyword, '????????????????????????', 'aeiouAEIOUNn') || '%'
                or translate(u.apellidos, '????????????????????????', 'aeiouAEIOUNn') ilike '%' || translate(:keyword, '????????????????????????', 'aeiouAEIOUNn') || '%'
                or translate(split_part(u.email, '@', 1), '????????????????????????', 'aeiouAEIOUNn') ilike '%' || translate(:keyword, '????????????????????????', 'aeiouAEIOUNn') || '%'
                or (
                    v.id is not null and (
                        translate(v.titulo_habilitante_legal, '????????????????????????', 'aeiouAEIOUNn') ilike '%' || translate(:keyword, '????????????????????????', 'aeiouAEIOUNn') || '%'
                        or translate(v.especialidad, '????????????????????????', 'aeiouAEIOUNn') ilike '%' || translate(:keyword, '????????????????????????', 'aeiouAEIOUNn') || '%'
                    )
                )
                or (
                    v.id is null and (
                        translate(u.titulo_segun_usuario, '????????????????????????', 'aeiouAEIOUNn') ilike '%' || translate(:keyword, '????????????????????????', 'aeiouAEIOUNn') || '%'
                        or translate(u.especialidad_segun_usuario, '????????????????????????', 'aeiouAEIOUNn') ilike '%' || translate(:keyword, '????????????????????????', 'aeiouAEIOUNn') || '%'
                    )
                ) 
                or (
                    u.id_privacidad_identificador = 1
                    and translate(u.identificador, '????????????????????????', 'aeiouAEIOUNn') ilike '%' || translate(:keyword, '????????????????????????', 'aeiouAEIOUNn') || '%'
                )
            )
            and u.id <> :id
            and u.id_tipo_usuario = :tipo
            
            order by translate(u.nombres, '????????????????????????', 'aeiouAEIOUNn'), translate(u.apellidos, '????????????????????????', 'aeiouAEIOUNn')
        ";

        $resultsDocs = DB::select($consulta, [ "keyword" => $keyword, "id" => $usuario->id . "", "tipo" => 2 ]); //doctores
        $resultsPat = DB::select($consulta, [ "keyword" => $keyword, "id" => $usuario->id . "", "tipo" => 3 ]); //pacientes

        $results = [
            "d" => [
                "count" => count($resultsDocs),
                "results" => $resultsDocs,
            ],
            "p" => [
                "count" => count($resultsPat),
                "results" => $resultsPat,
            ]
        ];

        return view('search', [
            "usuario" => $usuario,
            "results" => $results,
            "keyword" => $keyword,
//            "log" => DB::getQueryLog(),
        ]);
    }

    public function getNotifications() {
        return view('layouts.partials.all_notifications', [
            "unreadNotifCount" => count(Auth::user()->unreadNotifications),
        ]);
    }

    public function validations() {

        $consulta = "
            select sv.id
            , sv.id_usuario
            , sv.estado
            , sv.comentario
            , to_char(sv.updated_at, 'dd-mm-yyyy HH24:mi:ss') as updated_at
            , cast(extract(epoch from sv.updated_at::timestamp without time zone) as integer) as tstamp
            , concat_ws(' ', u.nombres, u.apellidos) as nombre_completo
            from solicitud_verificacion sv
            join usuarios u
              on u.id = sv.id_usuario
            order by sv.updated_at desc
        ";

        $validations = json_encode(DB::select($consulta));

        return view('admin.validations', [
            "usuario" => Auth::user(),
            "validations" => $validations,
        ]);
    }

    public function subs(Request $request, $tipo = 0) {

        $filtroTipo = "1=1";

        if (intval($tipo) === 1) { //Subscripciones activas
            $filtroTipo = "now() between s.inicio_subscripcion and s.termino_subscripcion";
        }
        else if (intval($tipo) === 2) { //Subscripciones no activas
            $filtroTipo = "now() not between s.inicio_subscripcion and s.termino_subscripcion";
        }

        $consulta = "
            select s.id as id_sub
            , s.id_usuario
            , s.id_plan
            , to_char(s.inicio_subscripcion, 'dd-mm-yyyy') as inicio_subscripcion 
            , to_char(s.termino_subscripcion, 'dd-mm-yyyy') as termino_subscripcion 
            , cast(extract(epoch from s.inicio_subscripcion::timestamp without time zone) as integer) as tstamp_inicio_sub
            , cast(extract(epoch from s.termino_subscripcion::timestamp without time zone) as integer) as tstamp_termino_sub
            , to_char(s.updated_at, 'dd-mm-yyyy HH24:mi:ss') as updated_at
            , cast(extract(epoch from s.updated_at::timestamp without time zone) as integer) as tstamp
            , concat_ws(' ', u.nombres, u.apellidos) as usuario_nombre_completo
            , pl.nombre as nombre_plan
            , pl.precio_mensual::int as precio_mensual_plan
            , sum(pa.total)::int as total_pagos
            , case
                when count(pa) > 0 then
                    json_agg((
                        select to_json(a) from (
                            select pa.total::int
                            , pa.estado
                            , to_char(pa.updated_at, 'dd-mm-yyyy HH24:mi:ss') as updated_at
                        ) a                        
                    ) order by pa.updated_at desc)
                else '[]'
            end as pagos
            from subscripciones s 
            join usuarios u
              on u.id = s.id_usuario
            join planes pl
              on pl.id = s.id_plan
            join pagos pa 
              on pa.id_subscripcion = s.id
            where {$filtroTipo}
            group by s.id, u.id, pl.id
        ";

        $subs = json_encode(DB::select($consulta));

        $planes = [];

        $consulta = "
            select p.id
            , p.nombre
            , p.precio_mensual
            , p.activo
            from planes p
        ";

        if ($rp = DB::select($consulta)) {
            $planes = $rp;
        }

        return view('admin.subs', [
            "usuario" => Auth::user(),
            "subs" => $subs,
            "tipo" => $tipo,
            "planes" => $planes,
        ]);
    }

    public function getSubbableUSers() {
        $datos = [
            "error" => false,
            "usuarios" => [],
            "planes" => [],
        ];

        $consulta = "
            select u.id as usuario_id
            , concat_ws(' ', u.nombres, u.apellidos) as usuario_nombre_completo
            , coalesce(v.antecedente_titulo , u.antecedente_titulo_segun_usuario, 'Sin especificar') as antecedente_titulo_segun_usuario
            , coalesce(v.especialidad , u.especialidad_segun_usuario, 'Sin especificar') as especialidad_segun_usuario
            , coalesce(v.fecha_registro , u.fecha_registro_segun_usuario, 'Sin especificar') as fecha_registro_segun_usuario
            , coalesce(v.institucion_habilitante , u.institucion_habilitante_segun_usuario, 'Sin especificar') as institucion_habilitante_segun_usuario
            , coalesce(v.nregistro , u.nregistro_segun_usuario, 'Sin especificar') as nregistro_segun_usuario
            , coalesce(v.titulo_habilitante_legal , u.titulo_segun_usuario, 'Sin especificar') as titulo_segun_usuario
            , (v.habilitado is true) as verificado
            from usuarios u
            left join verificaciones v
              on v.id_usuario = u.id and v.habilitado is true
            left join subscripciones s 
              on s.id_usuario = u.id and now() between s.inicio_subscripcion and s.termino_subscripcion
            where u.id_tipo_usuario = 2
            and s.id is null
            order by usuario_nombre_completo asc
        ";

        if ($ru = DB::select($consulta)) {
            $datos["usuarios"] = $ru;

            //planes

            $consulta = "
                select p.id
                , p.nombre
                , p.precio_mensual
                from planes p 
                where p.activo is true
            ";

            if ($rp = DB::select($consulta)) {
                $datos["planes"] = $rp;
            }
            else {
                $datos["error"] = true;
            }
        }
        else {
            $datos["error"] = true;
        }

        return response()->json($datos);
    }

    public function saveSub(Request $request) {
        $datos = [
            "error" => false,
        ];

        $this->validate($request, [
            "id_usuario" => "required|exists:usuarios,id",
            "id_plan" => "required|exists:planes,id",
            "fecha_desde" => 'required|max:10|date_format:"d-m-Y"',
            "nmeses" => "required|numeric|min:1|max:24"
        ], [], [
            "id_usuario" => "Usuario",
            "id_plan" => "Plan",
            "fecha_desde" => "Inicio subscripci??n",
            "nmeses" => "Duraci??n"
        ]);

        DB::beginTransaction();

        //Agregar subscripci??n
        $sub = new Subscripciones();
        $sub->id_usuario = $request["id_usuario"];
        $sub->id_plan = $request["id_plan"];
        $sub->inicio_subscripcion = implode('-', array_reverse(explode('-', $request["fecha_desde"]))) . " 00:00:00";
        $sub->termino_subscripcion = date('Y-m-d', strtotime(date('d-m-Y', strtotime($request["fecha_desde"])) . " +{$request["nmeses"]} month")) . " 00:00:00";

        if (!$sub->save()) {
            $datos["error"] = true;

            DB::rollBack();
        }
        else {
            //Agregar pago
            $pago = new Pagos();
            $pago->id_usuario = $request["id_usuario"];
            $pago->id_subscripcion = $sub->id;
            $pago->estado = 0;
            $pago->total = (new Plan())::find($request["id_plan"])->precio_mensual * intval($request["nmeses"]);

            if (!$pago->save()) {
                $datos["error"] = true;

                DB::rollBack();
            }
            else {
                DB::commit();
            }
        }

        return response()->json($datos);
    }

    public function getDoctorInfo(Request $request) {
        $datos = [
            "error" => false,
            "doctor" => [],
        ];

        $consulta = "
            select u.nombres
            , u.apellidos
            , to_char(u.created_at, 'dd-mm-yyyy HH24:mi:ss') as fecha_registro
            , to_char(u.updated_at, 'dd-mm-yyyy HH24:mi:ss') as ultima_actualizacion
            , u.email
            , to_char(u.fecha_nacimiento, 'dd-mm-yyyy') as fecha_nacimiento
            , s.nombre as sexo
            , ti.nombre as tipo_identificador
            , u.identificador
            , coalesce(u.antecedente_titulo_segun_usuario, 'Sin especificar') as antecedente_titulo_segun_usuario
            , coalesce(u.especialidad_segun_usuario, 'Sin especificar') as especialidad_segun_usuario
            , coalesce(u.fecha_registro_segun_usuario, 'Sin especificar') as fecha_registro_segun_usuario
            , coalesce(u.institucion_habilitante_segun_usuario, 'Sin especificar') as institucion_habilitante_segun_usuario
            , coalesce(u.nregistro_segun_usuario, 'Sin especificar') as nregistro_segun_usuario
            , coalesce(u.titulo_segun_usuario, 'Sin especificar') as titulo_segun_usuario
            from usuarios u
            join sexos s
              on s.id = u.id_sexo
            join tipos_identificador ti
              on ti.id = u.id_tipo_identificador
            where u.id = {$request["id"]}
        ";

        if ($r = DB::select($consulta)) {
            $datos["doctor"] = $r[0];
        }
        else {
            $datos["error"] = true;
        }

        return response()->json($datos);
    }

    public function getVerificacionesSolicitud(Request $request) {
        $datos = [
            "error" => false,
            "solicitud" => [],
        ];

        $consulta = "
            select s.id_usuario
            , u.identificador
            , u.id_tipo_identificador
            , s.estado
            , coalesce(s.comentario, '') as comentario
            , to_char(s.created_at, 'dd-mm-yyyy HH24:mi:ss') as fecha_creacion
            , to_char(s.updated_at, 'dd-mm-yyyy HH24:mi:ss') as ultima_actualizacion
            , case
                when count(v) > 0 then
                    json_agg((
                        select to_json(a)
                        from (
                            select v.id,
                            v.habilitado,
                            coalesce(v.titulo_habilitante_legal, '') as titulo,
                            coalesce(v.institucion_habilitante, '') as institucion,
                            coalesce(v.especialidad, '') as especialidad,
                            coalesce(v.nregistro, '') as nregistro,
                            coalesce(v.fecha_registro, '') as fregistro,
                            coalesce(v.antecedente_titulo, '') as antecedente,
                            to_char(v.created_at, 'dd-mm-yyyy HH24:mi:ss') as fecha_creacion,
                            to_char(v.updated_at, 'dd-mm-yyyy HH24:mi:ss') as ultima_actualizacion,
                            concat_ws(' ', uv.nombres, uv.apellidos) as nombre_verificante,
                            uv.id as id_verificante,
                            0 as estado
                        ) a
                    ) order by v.updated_at desc)
                else '[]'
            end as verificaciones
            from solicitud_verificacion s
            join usuarios u
              on u.id = s.id_usuario
            left join verificaciones v
              on s.id = v.id_solicitud
            left join usuarios uv
              on uv.id = v.id_usuario_verificante
            where s.id = {$request["id"]}
            group by s.id, u.id
        ";

        if ($r = DB::select($consulta)) {
            $datos["solicitud"] = $r[0];
        }
        else {
            $datos["error"] = true;
        }

        return response()->json($datos);
    }

    public function verifyExternal(Request $request) {
        $datos = [
            "error" => false,
            "content" => "",
        ];
//
//        $client = new Client();
        $baseUrl = "http://webhosting.superdesalud.gob.cl";
//
//        $res = $client->request('GET', "$baseUrl/prestadoresindividuales.nsf/(searchAll2)/Search?SearchView&Query=(FIELD%20rut_pres={$request["rut"]})&Start=1&count=10");
//
//        var_dump($res->getBody());
//
//        $datos["verificacion"] = $res;

//        return response()->json($datos);

        $step = intval($request["step"]);

        switch ($step) {
            case 1:
                $datos["content"] = (file_get_contents("$baseUrl/bases/prestadoresindividuales.nsf/(searchAll2)/Search?SearchView&Query=(FIELD%20rut_pres={$request["data"]})&Start=1&count=10"));
                break;
            case 2:
                $datos["content"] = utf8_encode(file_get_contents("$baseUrl{$request["data"]}"));
                break;
            case 3:
                $datos["content"] = utf8_encode(file_get_contents("$baseUrl/bases/prestadoresindividuales.nsf/(AntecRegxRut2)/{$request["data"]}?open"));
                break;
        }

        return response()->json($datos);
    }

    public function saveVerification(Request $request) {
        $datos = [
            "error" => false,
            "mensaje" => "",
        ];

        $solicitud = SolicitudesVerificacion::find($request["id_solicitud"]);

        $update = $solicitud->update([
            "estado" => intval($request["estado"]),
            "comentario" => $request["comentario"],
        ]);

        if ($update && $request->exists("verificaciones")) {

            foreach ($request["verificaciones"] as $ver) {
                if (intval($ver["estado"]) === 1) {

                    $verificacion = new Verificaciones();

                    $verificacion->habilitado = $ver["habilitado"] === "true";
                    $verificacion->titulo_habilitante_legal = $ver["titulo"];
                    $verificacion->institucion_habilitante = $ver["institucion"];
                    $verificacion->especialidad = $ver["especialidad"];
                    $verificacion->id_usuario = $solicitud->id_usuario;
                    $verificacion->nregistro = $ver["nregistro"];
                    $verificacion->fecha_registro = $ver["fregistro"];
                    $verificacion->antecedente_titulo = $ver["antecedente"];
                    $verificacion->id_solicitud = $solicitud->id;
                    $verificacion->id_usuario_verificante = Auth::user()->id;

                    $verificacion->save();

                    Usuario::find($solicitud->id_usuario)->notify(new VerificationRequestResponded($solicitud));
                }
                else if (intval($ver["estado"]) === 2) {
                    DB::table('verificaciones')->where('id', $ver["id"])->delete();
                }
            }
        }
        else {
            $datos["error"] = false;
        }

        return response()->json($datos);
    }

    public function extendSub(Request $request) {
        $datos = [
            "error" => false,
        ];

        $this->validate($request, [
            "id_sub" => "required|exists:subscripciones,id",
            "nmeses" => "required|numeric|min:1|max:24",
        ], [], [
            "id_sub" => "Subscripci??n",
            "nmeses" => "Meses a extender",
        ]);

        DB::beginTransaction();

        $sub = Subscripciones::find($request["id_sub"]);
        $endBefore = $sub->termino_subscripcion;

        $sub->termino_subscripcion = date('Y-m-d', strtotime(date('d-m-Y', strtotime(explode(' ', $endBefore)[0])) . " +{$request["nmeses"]} month")) . " 00:00:00";

        if (!$sub->save()) {
            $datos["error"] = true;

            DB::rollBack();
        }
        else {
            //Agregar pago
            $pago = new Pagos();
            $pago->id_usuario = $sub->id_usuario;
            $pago->id_subscripcion = $sub->id;
            $pago->estado = 0;
            $pago->total = (new Plan())::find($sub->id_plan)->precio_mensual * intval($request["nmeses"]);

            if (!$pago->save()) {
                $datos["error"] = true;

                DB::rollBack();
            }
            else {
                DB::commit();
            }
        }

        return response()->json($datos);
    }

    public function subPagos(Request $request) {
        $datos = [
            "error" => false,
            "pagos" => [],
        ];

        $consulta = "
            select p.id
            , case
                when p.estado = 0 then 'Normal'
                when p.estado = 1 then 'Anulado'
                else 'Eliminado'
            end as estado
            , to_char(p.created_at, 'dd-mm-yyyy HH24:mi:ss') as fecha_creacion
            , p.total
            from pagos p
            where p.id_subscripcion = ?
            order by p.created_at asc
        ";

        if ($r = DB::select($consulta, [$request["id_sub"]])) {
            $datos["pagos"] = $r;
        }
        else {
            $datos["error"] = true;
        }

        return response()->json($datos);
    }

    public function savePlan(Request $request) {
        $datos = [
            "error" => false,
            "mensaje" => "",
        ];

        $action = $request["action"];
        $p = $request["plan"];

        if ($action === "add") {
            $plan = new Plan();

            $plan->nombre = $p["nombre"];
            $plan->precio_mensual = $p["precio"];
            $plan->activo = $p["activo"] === true || $p["activo"] === "true";

            if (!$plan->save()) {
                $datos["error"] = true;
            }
        }
        else if ($action === 'edit') {
            $plan = Plan::find($p["id"]);

            $plan->nombre = $p["nombre"];
            $plan->precio_mensual = $p["precio"];
            $plan->activo = $p["activo"] === true || $p["activo"] === "true";

            if (!$plan->save()) {
                $datos["error"] = true;
            }
        }
        else {
            $datos["error"] = true;
            $datos["mensaje"] = "Acci??n incorrecta.";
        }

        return response()->json($datos);
    }

    public function checkPlanSubs(Request $request) {
        $datos = [
            "error" => false,
            "nsubs" => 1,
        ];

        $consulta = "
            select count(s.*)
            from subscripciones s
            where id_plan = ?
        ";

        if ($r = DB::select($consulta, [$request["id_plan"]])) {
            $datos["nsubs"] = intval($r[0]->count);
        }
        else {
            $datos["error"] = true;
        }

        return response()->json($datos);
    }

    public function deletePlan(Request $request) {
        $datos = [
            "error" => false,
        ];

        if (!Plan::destroy($request["id_plan"])) {
            $datos["error"] = true;
        }

        return response()->json($datos);
    }

    public function checkSub(Request $request) {
        $datos = [
            "error" => false,
            "isActive" => true,
        ];

        $consulta = "
            select count(s.*)
            from subscripciones s
            where id = ?
            and now() between s.inicio_subscripcion and s.termino_subscripcion
        ";

        if ($r = DB::select($consulta, [$request["id_sub"]])) {
            $datos["isActive"] = intval($r[0]->count) > 0;
        }
        else {
            $datos["error"] = true;
        }

        return response()->json($datos);
    }

    public function deleteSub(Request $request) {
        $datos = [
            "error" => false,
        ];

        DB::beginTransaction();

        $update = DB::table('pagos')
            ->where('id_subscripcion', '=', $request["id_sub"])
            ->update([
                "estado" => 2,
            ]);

        if ($update) {
            if (!Subscripciones::destroy($request["id_sub"])) {
                $datos["error"] = true;
                DB::rollback();
            }
            else {
                DB::commit();
            }
        }
        else {
            $datos["error"] = true;
            DB::rollback();
        }

        return response()->json($datos);
    }
}
