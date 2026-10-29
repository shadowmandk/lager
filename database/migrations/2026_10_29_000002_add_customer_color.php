<?php

use App\Support\CustomerPalette;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Indeks i CustomerPalette::COLORS.
            $table->unsignedTinyInteger('color')->nullable()->after('name');
        });

        // Eksisterende kunder fordeles over paletten, så så få som muligt deler farve.
        $count = count(CustomerPalette::COLORS);

        foreach (DB::table('customers')->orderBy('id')->pluck('id')->values() as $n => $id) {
            DB::table('customers')->where('id', $id)->update(['color' => $n % $count]);
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
