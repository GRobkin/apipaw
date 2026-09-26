<?php

declare(strict_types=1);

namespace PawLife\Resources;

/**
 * Los seis recursos de PawLife, con los mismos campos que los modelos de
 * Flutter (lib/models/pawlife_models.dart). Si se toca un campo aqui, hay que
 * tocarlo alli tambien.
 *
 * Colecciones operativas en Firestore (todas con userId):
 *
 *   users/{uid}  (perfil)
 *   mascotas/{id}
 *   vacunas/{id}, medicamentos/{id}, alimentaciones/{id}
 *   pesos/{id}, paseos/{id}, recordatorios/{id}, dispositivos/{id}
 *
 * Las entidades de cuidado también llevan mascotaId. Las subcolecciones
 * antiguas se conservan temporalmente para verificar la migración.
 */
final class Catalog
{
    public static function perfil(): Schema
    {
        return new Schema('perfil', [
            Field::string('email'),
            Field::string('nombre'),
            Field::string('fotoUrl'),
            Field::bool('premium', default: false),
            Field::timestamp('premiumHasta'),
            Field::string('zonaHoraria'),
        ]);
    }

    // El cliente nunca puede escribir email ni privilegios de suscripcion.
    public static function perfilEditable(): Schema
    {
        return new Schema('perfil', [
            Field::string('nombre'),
            Field::string('fotoUrl'),
            Field::string('zonaHoraria'),
        ]);
    }

    public static function dispositivos(): Schema
    {
        return new Schema('dispositivo', [
            Field::string('token', required: true, nullable: false),
            Field::choice('plataforma', ['android', 'ios', 'web']),
        ], defaultOrderBy: 'actualizadoEn desc');
    }

    public static function mascotas(): Schema
    {
        return new Schema('mascota', [
            Field::string('nombre', required: true, nullable: false),
            Field::string('especie', required: true, nullable: false),
            Field::string('raza'),
            // Opcional: hay gente que adopta y no sabe la fecha exacta. La app
            // calcula la edad a partir de esto, y si falta no la muestra.
            Field::timestamp('fechaNacimiento'),
            // Foto pequena enviada por la app como data URL, sin Storage.
            Field::string('fotoUrl'),
            Field::string('notas'),
        ], defaultOrderBy: 'nombre');
    }

    public static function vacunas(): Schema
    {
        return new Schema('vacuna', [
            Field::string('nombre', required: true, nullable: false),
            Field::timestamp('fechaAplicacion', required: true, nullable: false),
            Field::timestamp('proximaFecha', required: true, nullable: false),
            // Cuantos dias antes de proximaFecha hay que avisar.
            Field::int('anticipacionDias', default: 3),
        ], defaultOrderBy: 'proximaFecha');
    }

    public static function medicamentos(): Schema
    {
        return new Schema('medicamento', [
            Field::string('nombre', required: true, nullable: false),
            Field::string('dosis', required: true, nullable: false),
            // Horas del dia en formato "HH:mm".
            Field::stringList('horarios', required: true),
            Field::timestamp('fechaInicio', required: true, nullable: false),
            Field::timestamp('fechaFin'),
            Field::bool('activo', default: true),
        ], defaultOrderBy: 'fechaInicio desc');
    }

    public static function pesos(): Schema
    {
        return new Schema('registro de peso', [
            Field::timestamp('fecha', required: true, nullable: false),
            Field::double('valorKg', required: true),
        ], defaultOrderBy: 'fecha desc');
    }

    public static function alimentaciones(): Schema
    {
        return new Schema('alimentacion', [
            Field::string('tipoAlimento', required: true, nullable: false),
            Field::double('cantidadGramos', required: true),
            Field::timestamp('fechaHora', required: true, nullable: false),
            Field::string('notas'),
        ], defaultOrderBy: 'fechaHora desc');
    }

    public static function paseos(): Schema
    {
        return new Schema('paseo', [
            Field::timestamp('fechaInicio', required: true, nullable: false),
            Field::timestamp('fechaFin', required: true, nullable: false),
            Field::int('duracionSegundos', required: true),
            Field::double('distanciaMetros', required: true),
            // Velocidad puntual mas alta del paseo, no la media: se calcula
            // tramo a tramo en el ViewModel de Flutter.
            Field::double('velocidadMaximaKmh', required: true),
            // Un solo campo con lat, lng y hora de cada punto. La version
            // anterior partia esto en "ruta" (GeoPoint, sin tiempo) y
            // "rutaDetallada"; se unifico porque la ruta sin tiempos no servia
            // para nada que no hiciera ya la detallada.
            Field::route('ruta', required: true),
        ], defaultOrderBy: 'fechaInicio desc');
    }

    public static function recordatorios(): Schema
    {
        return new Schema('recordatorio', [
            // "vacuna", "medicamento", "paseo", ...
            Field::string('tipo', required: true, nullable: false),
            Field::string('mascotaId', required: true, nullable: false),
            Field::string('entidadId'),
            Field::timestamp('fecha', required: true, nullable: false),
            Field::string('mensaje', required: true, nullable: false),
            Field::bool('completado', default: false),
        ], defaultOrderBy: 'fecha');
    }
}
