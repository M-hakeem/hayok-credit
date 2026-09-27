<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $userAddressColumns = ['residential_address', 'state', 'lga'];
        $columnsToCopy = array_filter($userAddressColumns, fn (string $column) => Schema::hasColumn('users', $column));

        if ($columnsToCopy !== [] && Schema::hasTable('addresses')) {
            $users = DB::table('users')
                ->whereNull('deleted_at')
                ->where(function ($query) use ($columnsToCopy) {
                    foreach ($columnsToCopy as $column) {
                        $query->orWhereNotNull($column);
                    }
                })
                ->get(array_merge(['id'], $columnsToCopy));

            foreach ($users as $user) {
                $addressData = [];
                foreach ($columnsToCopy as $column) {
                    if ($user->{$column} !== null) {
                        $addressData[$column] = $user->{$column};
                    }
                }

                if ($addressData === []) {
                    continue;
                }

                $existingAddress = DB::table('addresses')
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at')
                    ->orderByDesc('id')
                    ->first();

                if ($existingAddress) {
                    $missingFields = array_filter($addressData, fn ($value, $column) => $existingAddress->{$column} === null || $existingAddress->{$column} === '', ARRAY_FILTER_USE_BOTH);
                    if ($missingFields !== []) {
                        DB::table('addresses')->where('id', $existingAddress->id)->update(array_merge($missingFields, ['updated_at' => now()]));
                    }
                    continue;
                }

                DB::table('addresses')->insert(array_merge($addressData, [
                    'user_id' => $user->id,
                    'verification_status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        $columnsToDrop = array_values(array_filter($userAddressColumns, fn (string $column) => Schema::hasColumn('users', $column)));

        if ($columnsToDrop !== []) {
            Schema::table('users', function (Blueprint $table) use ($columnsToDrop) {
                $table->dropColumn($columnsToDrop);
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('residential_address')->nullable();
            $table->string('state')->nullable();
            $table->string('lga')->nullable();
        });

        if (! Schema::hasTable('addresses')) {
            return;
        }

        foreach (DB::table('addresses')->whereNull('deleted_at')->get(['user_id', 'residential_address', 'state', 'lga']) as $address) {
            DB::table('users')->where('id', $address->user_id)->update([
                'residential_address' => $address->residential_address,
                'state' => $address->state,
                'lga' => $address->lga,
            ]);
        }
    }
};
