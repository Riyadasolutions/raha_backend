<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reservation has at most one ledger line of each type (RESERVATION, COMMITMENT_CONVERSION, COMMITMENT_REVERSAL, RESERVATION_RELEASE,
 * DEDUCTION...). Database-level guarantee that a commitment can never be deducted twice, whatever the calling code does.
 * Rows without a reservation (opening balances, funding, adjustments) are unaffected: NULLs never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_ledger', function (Blueprint $table) {
            $table->unique(['reservation_id', 'type'], 'wallet_ledger_reservation_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_ledger', function (Blueprint $table) {
            $table->dropUnique('wallet_ledger_reservation_type_unique');
        });
    }
};
