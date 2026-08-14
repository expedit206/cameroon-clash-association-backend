<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = ['key', 'value', 'description'];

    /**
     * Récupère la valeur d'un paramètre par clé, avec une valeur par défaut.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    /**
     * Définit ou crée un paramètre.
     */
    public static function set(string $key, string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    // ─── Helpers Clash Bet ───────────────────────────────────────────────────

    public static function clashBetCommission(): float
    {
        return (float) static::get('clash_bet_commission_percentage', 10);
    }

    public static function clashBetCloseOffset(): int
    {
        return (int) static::get('clash_bet_close_offset_minutes', 5);
    }

    public static function clashBetMinAmount(): int
    {
        return (int) static::get('clash_bet_min_amount', 100);
    }

    public static function clashBetMaxAmount(): int
    {
        return (int) static::get('clash_bet_max_amount', 50000);
    }

    public static function clashBetFixedOdds(): float
    {
        return (float) static::get('clash_bet_fixed_odds', 2.00);
    }

    public static function clashBetWithdrawalFee(): float
    {
        return (float) static::get('clash_bet_withdrawal_fee_percentage', 10);
    }

    public static function clashBetPublicEnabled(): bool
    {
        return filter_var(static::get('clash_bet_public_enabled', '0'), FILTER_VALIDATE_BOOLEAN);
    }
}
