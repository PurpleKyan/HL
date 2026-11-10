<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trail;
use Illuminate\Http\Request;

/**
 * Score = (0.40 x Preference Match) + (0.35 x Budget Fit) + (0.25 x Rating Score)
 */
class RecommendationController extends Controller
{
    public function recommend(Request $request)
    {
        $data = $request->validate([
            'budget' => 'required|numeric|min:1',          // total budget of the whole group
            'group_size' => 'required|integer|min:1|max:60',
            'location' => 'nullable|string|max:255',
            'category' => 'nullable|in:Mountain,Waterfall,Eco-Park',
            'difficulty' => 'nullable|in:Easy,Moderate,Hard',
            'trip_type' => 'nullable|in:Day Hike,Overnight',
            'features' => 'nullable|array',
            'features.*' => 'string|max:50',
        ]);

        $budget = (float) $data['budget'];
        $group = (int) $data['group_size'];

        // 1) Remove inactive / unverified trails and those outside the selected location or category
        $q = Trail::where('status', 'verified')->where('is_active', true);
        if (! empty($data['location'])) {
            $q->where('location', $data['location']);
        }
        if (! empty($data['category'])) {
            $q->where('category', $data['category']);
        }

        $results = [];

        foreach ($q->get() as $trail) {
            // 2) Over-budget trails are completely excluded
            $cost = ($trail->entrance_fee * $group) + $trail->guide_fee;
            if ($cost > $budget) {
                continue;
            }

            // 3) Preference match = share of the selected preferences the trail satisfies
            $criteria = 0;
            $matched = 0;
            if (! empty($data['difficulty'])) {
                $criteria++;
                if ($trail->difficulty === $data['difficulty']) {
                    $matched++;
                }
            }
            if (! empty($data['trip_type'])) {
                $criteria++;
                if ($trail->trip_type === $data['trip_type']) {
                    $matched++;
                }
            }
            $trailFeatures = $trail->features ?? [];
            foreach (($data['features'] ?? []) as $feature) {
                $criteria++;
                if (in_array($feature, $trailFeatures, true)) {
                    $matched++;
                }
            }
            $preference = $criteria > 0 ? $matched / $criteria : 1.0;

            // 4) Budget fit and rating score
            $budgetFit = 1 - ($cost / $budget);
            $ratingScore = $trail->avg_rating / 5;

            $score = (0.40 * $preference) + (0.35 * $budgetFit) + (0.25 * $ratingScore);

            $results[] = [
                'trail' => $trail,
                'estimated_cost' => round($cost, 2),
                'score' => round($score * 100, 1),
                'breakdown' => [
                    'preference_match' => round($preference * 100, 1),
                    'budget_fit' => round($budgetFit * 100, 1),
                    'rating_score' => round($ratingScore * 100, 1),
                ],
            ];
        }

        // 5) Highest score first
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return response()->json($results);
    }
}
