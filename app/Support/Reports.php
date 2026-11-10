<?php

namespace App\Support;

use Illuminate\Support\Collection;

class Reports
{
    /** Build visitor-volume and booking-summary data from a collection of bookings (with ->trail loaded). */
    public static function summary(Collection $bookings): array
    {
        $counts = ['Pending' => 0, 'Approved' => 0, 'Completed' => 0, 'Cancelled' => 0];
        foreach ($bookings as $b) {
            $counts[$b->status] = ($counts[$b->status] ?? 0) + 1;
        }

        $valid = $bookings->filter(fn ($b) => in_array($b->status, ['Approved', 'Completed'], true));

        $byTrail = $bookings->groupBy('trail_id')->map(function ($group) {
            $ok = $group->filter(fn ($b) => in_array($b->status, ['Approved', 'Completed'], true));

            return [
                'trail' => optional($group->first()->trail)->name ?? 'Unknown trail',
                'bookings' => $group->count(),
                'visitors' => (int) $ok->sum('group_size'),
            ];
        })->sortByDesc('visitors')->values();

        $byMonth = $valid->groupBy(fn ($b) => $b->trek_date->format('Y-m'))->map(function ($group, $month) {
            return [
                'month' => $month,
                'bookings' => $group->count(),
                'visitors' => (int) $group->sum('group_size'),
            ];
        })->sortKeys()->values();

        return [
            'total_bookings' => $bookings->count(),
            'total_visitors' => (int) $valid->sum('group_size'),
            'completed_revenue' => (float) $bookings->where('status', 'Completed')->sum('total_cost'),
            'status_counts' => $counts,
            'by_trail' => $byTrail,
            'by_month' => $byMonth,
        ];
    }
}
