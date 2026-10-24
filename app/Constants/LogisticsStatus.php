<?php

namespace App\Constants;

/**
 * Estados logísticos de una orden que pasa por un centro (logística propia, §20.1). Son internos del operador: la orden
 * sigue con sus estados de siempre ("Asignar a agencia", "En ruta", "Entregado"…) y el cliente recibe los mismos
 * mensajes (Fran, R3).
 */
class LogisticsStatus
{
    public const PENDING_WINDOW  = 'pendiente_ventana';   // centro cerrado, sin operador o después del cutoff (§4.2)
    public const SEARCHING       = 'buscando_rider';      // SLA corriendo, rondas activas (§8)
    public const RIDER_CONFIRMED = 'rider_confirmado';    // el primer rider que confirmó la ganó (§8.2)
    public const PREPARING       = 'preparando';          // picking y packing (§9.2)
    public const READY           = 'listo_despacho';      // paquete listo, esperando al rider
    public const EN_ROUTE        = 'en_via';              // "Confirmar salida": ruta bloqueada (§11)
    public const INCIDENT        = 'novedad';             // novedad logística con el operador (§13)
    public const AGENCY          = 'asignado_agencia';    // overflow a la agencia de respaldo (§19)
    public const DELIVERED       = 'entregado';
    public const RETURN_PENDING  = 'retorno_pendiente';   // la pieza sigue fuera (§16.1)
    public const IN_REVIEW       = 'en_revision';
    public const CLOSED          = 'cerrada';             // salió del centro sin entregarse (cancelada, devuelta a ventas…)

    public const LABELS = [
        self::PENDING_WINDOW  => 'Pendiente de ventana',
        self::SEARCHING       => 'Buscando rider',
        self::RIDER_CONFIRMED => 'Rider confirmado',
        self::PREPARING       => 'Preparando',
        self::READY           => 'Listo para despacho',
        self::EN_ROUTE        => 'En vía',
        self::INCIDENT        => 'Novedad',
        self::AGENCY          => 'Asignado a agencia',
        self::DELIVERED       => 'Entregado',
        self::RETURN_PENDING  => 'Retorno pendiente',
        self::IN_REVIEW       => 'En revisión',
        self::CLOSED          => 'Cerrada',
    ];

    /** Todavía en el centro, sin rider ni agencia que la tenga. */
    public const UNASSIGNED = [self::PENDING_WINDOW, self::SEARCHING];

    /** Ya no se mueve en la torre de control. */
    public const FINISHED = [self::DELIVERED, self::CLOSED];

    /** La pieza salió del centro con el rider y no se entregó (con `departed_at`): sigue bajo su custodia (§16.2.1). */
    public const WITH_RIDER = [self::EN_ROUTE, self::INCIDENT, self::RETURN_PENDING];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }
}
