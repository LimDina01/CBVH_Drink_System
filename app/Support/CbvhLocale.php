<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Lang;

/**
 * Translates dynamic catalog entities (drinks, rooms, floors, options) and datetimes
 * cleanly into the active application locale without mixing languages.
 */
class CbvhLocale
{
    private const KHMER_MONTHS = [
        1 => 'មករា',
        2 => 'កុម្ភៈ',
        3 => 'មីនា',
        4 => 'មេសា',
        5 => 'ឧសភា',
        6 => 'មិថុនា',
        7 => 'កក្កដា',
        8 => 'សីហា',
        9 => 'កញ្ញា',
        10 => 'តុលា',
        11 => 'វិច្ឆិកា',
        12 => 'ធ្នូ',
    ];

    public static function map(string $group, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (app()->getLocale() !== 'km') {
            return $value;
        }

        $dictionary = Lang::get("cbvh.{$group}");
        if (is_array($dictionary) && array_key_exists($value, $dictionary)) {
            return (string) $dictionary[$value];
        }

        return $value;
    }

    public static function drink(?string $name): string
    {
        return self::map('drinks', $name);
    }

    public static function toBaseDrinkName(?string $name): string
    {
        if ($name === null || $name === '') {
            return '';
        }

        $trimmed = trim($name);

        if (preg_match('/green\s*tea/i', $trimmed)) {
            return 'Green Tea';
        }

        if (preg_match('/orange\s*juice/i', $trimmed)) {
            return 'Orange Juice';
        }

        // Remove prefixes like Hot, Iced, Warm, Cold, Fresh Squeezed, Fresh
        $cleaned = preg_replace('/^(Hot|Iced|Warm|Cold|Fresh\s+Squeezed|Fresh)\s+/i', '', $trimmed);

        return trim((string) $cleaned);
    }

    public static function baseDrink(?string $name): string
    {
        $baseName = self::toBaseDrinkName($name);

        if (app()->getLocale() !== 'km') {
            return $baseName;
        }

        $dictionary = Lang::get('cbvh.base_drinks');
        if (is_array($dictionary) && array_key_exists($baseName, $dictionary)) {
            return (string) $dictionary[$baseName];
        }

        return $baseName;
    }

    public static function drinkDescription(?string $description): string
    {
        return self::map('drink_descriptions', $description);
    }

    public static function option(?string $value): string
    {
        return self::map('options', $value);
    }

    public static function optionKey(string $key): string
    {
        return match ($key) {
            'sugar' => __('cbvh.opt_sugar'),
            'coffee' => __('cbvh.opt_coffee'),
            'ice' => __('cbvh.opt_ice'),
            'milk' => __('cbvh.opt_milk'),
            default => ucfirst($key),
        };
    }

    public static function room(?string $name): string
    {
        return self::map('rooms', $name);
    }

    public static function branch(?string $name): string
    {
        return self::map('branches', $name);
    }

    public static function floor(?string $floor): string
    {
        if ($floor === null || $floor === '') {
            return '';
        }

        if (app()->getLocale() !== 'km') {
            return $floor;
        }

        $mapped = self::map('floors', $floor);
        if ($mapped !== $floor) {
            return $mapped;
        }

        if (preg_match('/^Floor\s+(\d+)$/i', trim($floor), $m)) {
            return 'ជាន់ទី ' . $m[1];
        }

        return $floor;
    }

    public static function formatTime(CarbonInterface $dt): string
    {
        if (app()->getLocale() !== 'km') {
            return $dt->format('h:i A');
        }

        return $dt->format('h:i') . ' ' . self::khmerDayPeriod($dt);
    }

    public static function formatDate(CarbonInterface $dt): string
    {
        if (app()->getLocale() !== 'km') {
            return $dt->format('d/M/Y');
        }

        $month = self::KHMER_MONTHS[$dt->month] ?? $dt->format('M');

        return $dt->format('d') . '/' . $month . '/' . $dt->year;
    }

    public static function formatDateTime(CarbonInterface $dt): string
    {
        if (app()->getLocale() !== 'km') {
            return $dt->format('d/M/Y, h:i A');
        }

        return self::formatDate($dt) . ' · ' . self::formatTime($dt);
    }

    private static function khmerDayPeriod(CarbonInterface $dt): string
    {
        $hour = $dt->hour;
        if ($hour < 12) {
            return 'ព្រឹក';
        }
        if ($hour === 12) {
            return 'ថ្ងៃត្រង់';
        }
        if ($hour < 18) {
            return 'រសៀល';
        }

        return 'ល្ងាច';
    }
}

