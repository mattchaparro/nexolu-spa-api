<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // De lo que se le pagó, cuánto fueron propinas (van dentro de
        // `bonus_total`): para mostrarlas aparte, sin comisión.
        Schema::table('payroll_settlements', function (Blueprint $table) {
            $table->decimal('tip_total', 12, 2)->default(0)->after('bonus_total');
        });

        DB::statement(<<<'SQL'
            UPDATE payroll_settlements s
            SET tip_total = COALESCE((
                SELECT SUM(a.amount) FROM payroll_adjustments a
                WHERE a.settlement_id = s.id AND a.category = 'propina'
            ), 0)
        SQL);
    }

    public function down(): void
    {
        Schema::table('payroll_settlements', function (Blueprint $table) {
            $table->dropColumn('tip_total');
        });
    }
};
