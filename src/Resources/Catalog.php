<?php

declare(strict_types=1);

namespace PawLife\Resources;

/**
 * Los seis recursos de PawLife, con los mismos campos que los modelos de
 * Flutter (lib/models/pawlife_models.dart). Si se toca un campo aqui, hay que
 * tocarlo alli tambien.
 *
 * Como queda el arbol en Firestore:
 *
 *   users/{uid}/mascotas/{mascotaId}
 *   users/{uid}/mascotas/{mascotaId}/vacunas/{id}
 *   users/{uid}/mascotas/{mascotaId}/medicamentos/{id}
 *   users/{uid}/mascotas/{mascotaId}/pesos/{id}
 *   users/{uid}/mascotas/{mascotaId}/paseos/{id}
 *   users/{uid}/recordatorios/{id}
 *
 * Los recordatorios cuelgan del usuario y no de la mascota porque la agenda
 * del home los mezcla todos; cada uno lleva dentro su mascotaId.
 */
final class Catalog
{
    public static function mascotas(): Schema
    {
        return new Schema('mascota', [
            Field::string('nombre', required: true, nullable: false),
            Field::string('especie', required: true, nullable: false),
            Field::string('raza'),
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
            Field::timestamp('fecha', required: true, nullable: false),
            Field::string('mensaje', required: true, nullable: false),
            Field::bool('completado', default: false),
        ], defaultOrderBy: 'fecha');
    }
}
