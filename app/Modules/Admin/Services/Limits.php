<?php

namespace App\Modules\Admin\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Hạn mức chỉnh từ trang quản trị; ghi đè config('gehihi.*') sau khi boot và mỗi lần worker nhận job. */
class Limits
{
    /** key config => [min, max] */
    public const FIELDS = [
        'daily_runs' => [1, 100000], 'daily_tokens' => [1000, 100000000], 'concurrency' => [1, 50],
        'chat_tokens' => [1000, 2000000], 'research_tokens' => [1000, 4000000], 'guest_days' => [1, 365],
        'research.quick.queries' => [1, 20], 'research.quick.pages' => [0, 40], 'research.quick.rounds' => [1, 5], 'research.quick.seconds' => [10, 600],
        'research.deep.queries' => [1, 50], 'research.deep.pages' => [0, 100], 'research.deep.rounds' => [1, 10], 'research.deep.seconds' => [30, 1800],
    ];

    private static ?array $defaults = null;

    public static function overrides(): array
    {
        try {
            return json_decode(Cache::rememberForever('gehihi.limits', fn () => DB::table('app_settings')->where('key', 'limits')->value('value') ?: '{}'), true) ?: [];
        } catch (\Throwable) {
            return []; // bảng chưa migrate
        }
    }

    public static function apply(): void
    {
        self::$defaults ??= self::current();
        foreach ([...self::$defaults, ...array_intersect_key(self::overrides(), self::FIELDS)] as $key => $value) {
            config(['gehihi.'.$key => (int) $value]);
        }
    }

    public static function current(): array
    {
        return array_combine(array_keys(self::FIELDS), array_map(fn ($key) => (int) config('gehihi.'.$key), array_keys(self::FIELDS)));
    }

    public static function defaults(): array
    {
        return self::$defaults ?? self::current();
    }

    public static function save(array $values): array
    {
        $values = array_map('intval', array_intersect_key($values, self::FIELDS));
        DB::table('app_settings')->updateOrInsert(['key' => 'limits'], ['value' => json_encode($values), 'updated_at' => now(), 'created_at' => now()]);
        Cache::forget('gehihi.limits');
        self::apply();

        return self::current();
    }
}
