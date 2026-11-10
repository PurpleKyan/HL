<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserSpamAuditLog;
use Illuminate\Support\Str;

class SpamProtection
{
    public static function checkRestriction(User $user, string $role, ?string $requestHash = null): ?array
    {
        if (self::isRestricted($user)) {
            $expiresAt = $user->spam_restriction_expires_at;

            return [
                'message' => self::restrictionMessage($user, $role),
                'restricted' => true,
                'restricted_until' => $expiresAt?->utc()->toIso8601String(),
            ];
        }

        if ($requestHash !== null && $user->spam_last_request_hash === $requestHash) {
            return self::flagViolation($user, $role, 'Duplicate submission detected for the same request.');
        }

        if ($user->spam_last_submission_at && $user->spam_last_submission_at->diffInSeconds(now()) < 30) {
            return self::flagViolation($user, $role, 'Repeat requests detected within a short interval.');
        }

        $user->forceFill([
            'spam_last_submission_at' => now(),
            'spam_last_request_hash' => $requestHash ?? Str::random(32),
        ])->save();

        return null;
    }

    public static function isRestricted(User $user): bool
    {
        $expiresAt = $user->spam_restriction_expires_at;
        if ($expiresAt && ! $expiresAt->isFuture()) {
            $isAdminFlagged = $user->spam_validation_status === 'flagged';
            $expiredRestriction = [
                'spam_restriction_expires_at' => null,
                'spam_last_submission_at' => null,
                'spam_last_request_hash' => null,
            ];

            if (! $isAdminFlagged) {
                $expiredRestriction['spam_validation_status'] = 'clear';
                $expiredRestriction['spam_violation_count'] = 0;
            }
            $user->forceFill($expiredRestriction)->save();

            return $isAdminFlagged;
        }

        return $expiresAt !== null || $user->spam_validation_status === 'flagged';
    }

    public static function restrictionMessage(User $user, string $role): string
    {
        $expiresAt = $user->spam_restriction_expires_at;

        if ($user->spam_validation_status === 'flagged') {
            $action = $role === 'guide' ? 'trail submissions' : 'booking requests';

            return "An administrator has flagged your account for spam. Your {$action} are paused until an administrator clears the restriction.";
        }

        $action = $role === 'guide' ? 'trail submissions' : 'booking requests';

        return "Repeated submissions paused your {$action} for one hour. You can try again after {$expiresAt?->utc()->format('Y-m-d H:i:s')} UTC.";
    }

    private static function flagViolation(User $user, string $role, string $reason): array
    {
        $threshold = 2;
        $count = (int) $user->spam_violation_count + 1;
        $user->forceFill([
            'spam_violation_count' => $count,
            'spam_last_submission_at' => now(),
            'spam_validation_status' => 'review',
        ]);

        if ($count >= $threshold) {
            $user->spam_restriction_expires_at = now()->addHour();
        }

        $user->save();

        UserSpamAuditLog::create([
            'user_id' => $user->id,
            'role' => $role,
            'reason' => $reason,
            'status' => $count >= $threshold ? 'restricted' : 'warning',
            'metadata' => [
                'threshold' => $threshold,
                'violation_count' => $count,
                'restriction_minutes' => $count >= $threshold ? 60 : null,
            ],
        ]);

        $expiresAt = $user->spam_restriction_expires_at;
        $restricted = $count >= $threshold;

        return [
            'message' => $restricted
                ? self::restrictionMessage($user, $role)
                : 'This looks like a duplicate or rapid repeat request. Please wait before trying again. A second spam violation pauses submissions for one hour and sends the account for admin review.',
            'restricted' => $restricted,
            'restricted_until' => $expiresAt?->utc()->toIso8601String(),
        ];
    }
}
