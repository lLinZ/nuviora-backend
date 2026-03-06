<?php

namespace App\Constants;

/**
 * Centralized Order Status constants to avoid hardcoded strings across the system.
 */
class OrderStatus
{
    public const NUEVO                       = 'Nuevo';
    public const ASIGNADO_VENDEDOR           = 'Asignado a vendedor';
    public const LLAMADO_1                   = 'Llamado 1';
    public const LLAMADO_2                   = 'Llamado 2';
    public const LLAMADO_3                   = 'Llamado 3';
    public const ESPERANDO_UBICACION         = 'Esperando Ubicacion';
    public const ASIGNAR_A_AGENCIA           = 'Asignar a agencia';
    public const ASIGNADO_A_REPARTIDOR       = 'Asignado a repartidor';
    public const ASIGNAR_REPARTIDOR          = 'Asignar repartidor';
    public const EN_RUTA                     = 'En ruta';
    public const ENTREGADO                   = 'Entregado';
    public const NOVEDADES                   = 'Novedades';
    public const NOVEDAD_SOLUCIONADA         = 'Novedad Solucionada';
    public const CANCELADO                   = 'Cancelado';
    public const RECHAZADO                   = 'Rechazado';
    public const SIN_STOCK                   = 'Sin Stock';
    public const PROGRAMADO_MAS_TARDE        = 'Programado para mas tarde';
    public const PROGRAMADO_OTRO_DIA         = 'Programado para otro dia';
    public const REPROGRAMADO_HOY            = 'Reprogramado para hoy';
    public const CAMBIO_UBICACION            = 'Cambio de ubicacion';
    public const POR_APROBAR_UBICACION       = 'Por aprobar cambio de ubicacion';
    public const POR_APROBAR_RECHAZO         = 'Por aprobar rechazo';

    /**
     * Terminating statuses where the order is considered "finished" or "stopped".
     */
    public const TERMINAL_STATUSES = [
        self::ENTREGADO,
        self::CANCELADO,
        self::RECHAZADO,
        self::EN_RUTA, // Transitioning to deliverer
        self::NOVEDADES,
        self::NOVEDAD_SOLUCIONADA,
        self::ASIGNAR_A_AGENCIA,
    ];

    /**
     * Statuses that trigger inventory deduction.
     */
    public const DEDUCTION_STATUSES = [
        self::ASIGNAR_A_AGENCIA,
        self::EN_RUTA,
        self::ENTREGADO,
    ];

    /**
     * Statuses that return inventory if previously deducted.
     */
    public const RETURN_STATUSES = [
        self::NUEVO,
        self::CANCELADO,
        self::RECHAZADO,
        self::ASIGNADO_VENDEDOR,
        self::LLAMADO_1,
        self::LLAMADO_2,
        self::LLAMADO_3,
        self::ESPERANDO_UBICACION,
        self::PROGRAMADO_MAS_TARDE,
        self::PROGRAMADO_OTRO_DIA,
        self::REPROGRAMADO_HOY,
        self::CAMBIO_UBICACION,
        self::POR_APROBAR_UBICACION,
        self::POR_APROBAR_RECHAZO,
    ];
}
