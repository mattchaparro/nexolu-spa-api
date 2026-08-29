<?php

namespace App\Support;

/**
 * Traduce el codigo de accion guardado en log_actions.action (ver
 * AuditLogger::log()) a un texto legible para la pantalla de Auditoria del
 * negocio. Una accion sin entrada aca cae al fallback (des-snakecase +
 * mayuscula inicial) en vez de romper - no hace falta mantener esta lista
 * perfectamente sincronizada con cada AuditLogger::log() nuevo.
 */
class AuditActionDictionary
{
    public static function label(string $action): string
    {
        return self::all()[$action] ?? self::fallback($action);
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'sale.created' => 'Venta creada (administrador)',
            'sale.created.employee' => 'Venta creada (empleado)',
            'sale.reversed' => 'Venta anulada',
            'tab.opened' => 'Cuenta abierta / mesa',
            'tab.items_added' => 'Items agregados a la cuenta',
            'tab.items_synced' => 'Cuenta actualizada (items sincronizados)',
            'tab.partial_payment' => 'Abono parcial a la cuenta',
            'tab.closed' => 'Cuenta cerrada y cobrada',
            'tab.cancelled' => 'Cuenta cancelada',
            'receivable.collected' => 'Fiado cobrado',
            'cash_shift.opened' => 'Turno de caja abierto',
            'cash_shift.closed' => 'Turno de caja cerrado',
            'cash_shift.updated' => 'Turno de caja corregido',
            'cash_shift.deleted' => 'Turno de caja eliminado',
            'cash_closing.created' => 'Cierre de caja registrado',
            'cash_closing.created.employee' => 'Cierre de caja registrado (empleado)',
            'cash_closing.updated' => 'Cierre de caja actualizado',
            'cash_closing.undone' => 'Cierre de caja deshecho',
            'expense.created' => 'Gasto registrado',
            'expense.updated' => 'Gasto actualizado',
            'expense.deleted' => 'Gasto eliminado',
            'product.created' => 'Producto creado',
            'product.updated' => 'Producto actualizado',
            'product.duplicated' => 'Producto duplicado',
            'product.deleted' => 'Producto eliminado',
            'accounting.month_closed' => 'Cierre contable mensual',
        ];
    }

    private static function fallback(string $action): string
    {
        $normalized = str_replace(['.', '_'], ' ', trim($action));
        $normalized = preg_replace('/\s+/', ' ', (string) $normalized);

        return ucfirst($normalized ?: 'Accion del sistema');
    }
}
