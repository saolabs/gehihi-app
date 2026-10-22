<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Định dạng số/thời gian cho trang quản trị. Chạy ở server nên SSR và JSON điều hướng SPA in ra giống hệt nhau. */
class Format
{
    public static function num(int|float $n): string
    {
        return number_format($n, 0, ',', '.');
    }

    public static function compact(int|float $n): string
    {
        foreach ([1e9 => 'B', 1e6 => 'M', 1e3 => 'K'] as $size => $suffix) {
            if ($n >= $size) {
                return rtrim(rtrim(number_format($n / $size, 1, ',', ''), '0'), ',').$suffix;
            }
        }

        return (string) $n;
    }

    public static function when(?CarbonInterface $at): string
    {
        return $at ? $at->timezone(config('app.timezone'))->format('H:i d/m/Y') : '—';
    }

    public static function percent(int $part, int $total): string
    {
        return $total ? rtrim(rtrim(number_format($part / $total * 100, 1, ',', ''), '0'), ',').'%' : '0%';
    }

    /**
     * Khóa @foreach theo nội dung hàng. @key giữ nguyên DOM của hàng khi khóa không đổi và không vẽ lại
     * nội dung, nên khóa theo id làm hàng vừa sửa vẫn hiện dữ liệu cũ sau khi setState.
     */
    public static function rowKey(array $row): string
    {
        return substr(md5(json_encode($row)), 0, 12);
    }
}
