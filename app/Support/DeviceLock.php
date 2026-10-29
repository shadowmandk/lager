<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Låseskærmen foran tavle, log og statistik: en fælles 6-cifret kode pr. enhed.
 * Låser efter 30 minutter uden aktivitet, når skærmen slukkes (styres i browseren), eller når koden skiftes.
 */
final class DeviceLock
{
    public const IDLE_MINUTES = 30;

    public const CODE_LENGTH = 6;

    private const SESSION = 'unlocked';

    private const HASH = 'access.code_hash';

    private const VERSION = 'access.version';

    /** Forkerte forsøg før første ventetid. Ventetiden fordobles for hver ny runde. */
    private const TRIES = 5;

    public static function hasCode(): bool
    {
        return (string) Setting::get(self::HASH) !== '';
    }

    public static function isValidCode(string $code): bool
    {
        return preg_match('/^\d{'.self::CODE_LENGTH.'}$/', $code) === 1;
    }

    /** Ny kode. Alle enheder låses, fordi versionen skifter. */
    public static function setCode(string $code): void
    {
        if (! self::isValidCode($code)) {
            throw new \InvalidArgumentException('Koden skal være '.self::CODE_LENGTH.' cifre.');
        }

        Setting::put(self::HASH, Hash::make($code));
        Setting::put(self::VERSION, (string) (self::version() + 1));
    }

    public static function isUnlocked(): bool
    {
        $state = session()->get(self::SESSION);

        return is_array($state)
            && ($state['v'] ?? null) === self::version()
            && now()->getTimestamp() - (int) ($state['seen'] ?? 0) <= self::IDLE_MINUTES * 60;
    }

    /** Aktivitet skubber låsen. Tavlens automatiske opdatering tæller ikke. */
    public static function touch(): void
    {
        session()->put(self::SESSION.'.seen', now()->getTimestamp());
    }

    /** @return array{ok: bool, wait: int} wait er sekunder, før man må prøve igen */
    public static function attempt(string $code, string $ip): array
    {
        $key = 'unlock:'.$ip;

        if ($wait = self::waiting($key)) {
            return ['ok' => false, 'wait' => $wait];
        }

        if (self::hasCode() && self::isValidCode($code) && Hash::check($code, (string) Setting::get(self::HASH))) {
            Cache::forget($key.':fails');
            session()->regenerate();
            session()->forget('operator_id');
            session()->put(self::SESSION, ['v' => self::version(), 'seen' => now()->getTimestamp()]);

            return ['ok' => true, 'wait' => 0];
        }

        $fails = (int) Cache::get($key.':fails', 0) + 1;
        Cache::put($key.':fails', $fails, now()->addHours(6));

        if ($fails % self::TRIES === 0) {
            $seconds = min(3600, 60 * 2 ** (intdiv($fails, self::TRIES) - 1));
            Cache::put($key.':until', now()->getTimestamp() + $seconds, $seconds);

            return ['ok' => false, 'wait' => $seconds];
        }

        return ['ok' => false, 'wait' => 0];
    }

    /** Lås enheden. Medarbejdervalget glemmes også, så man vælger sig selv igen efter oplåsning. */
    public static function lock(): void
    {
        session()->forget([self::SESSION, 'operator_id']);
    }

    private static function version(): int
    {
        return (int) Setting::get(self::VERSION);
    }

    private static function waiting(string $key): int
    {
        return max(0, (int) Cache::get($key.':until', 0) - now()->getTimestamp());
    }
}
