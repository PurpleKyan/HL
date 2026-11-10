<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordResetCode;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AuthController extends Controller
{
    private const RESET_CODE_MESSAGE = 'If an account exists for that email, a password reset code has been sent.';

    private const ROLE_LABELS = [
        'hiker' => 'Hiker',
        'guide' => 'Guide / Tourism Officer',
        'admin' => 'Administrator',
    ];

    public function register(Request $request)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[^0-9]+$/'],
            'email' => [
                'required',
                'email',
                'max:255',
                'ends_with:@gmail.com',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (User::query()->whereRaw('LOWER(email) = ?', [Str::lower(trim((string) $value))])->exists()) {
                        $fail('This email is already registered. Please log in or use a different email address.');
                    }
                },
            ],
            'phone' => ['nullable', 'string', 'regex:/^09\d{9}$/'],
            'role' => 'required|in:hiker,guide', // admins are never self-registered
            'password' => 'required|string|size:8|confirmed',
            'qualification_photo' => ['required_if:role,guide', 'prohibited_unless:role,guide', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $qualificationPath = null;
        if ($data['role'] === 'guide') {
            $qualificationPath = $request->file('qualification_photo')->store('guide-qualifications', 'local');
            if (! is_string($qualificationPath)) {
                return response()->json(['message' => 'The qualification photo could not be saved. Please try again.'], 500);
            }

            $data['account_status'] = 'pending';
            $data['is_active'] = false;
            $data['guide_visible'] = false;
            $data['guide_qualification_path'] = $qualificationPath;
        } else {
            $data['account_status'] = 'approved';
        }

        unset($data['qualification_photo']);
        try {
            $user = User::create($data); // password is hashed automatically (model cast)
        } catch (Throwable $exception) {
            if ($qualificationPath) {
                Storage::disk('local')->delete($qualificationPath);
            }
            throw $exception;
        }

        if ($user->role === 'guide') {
            return response()->json([
                'user' => $user,
                'account_status' => 'pending',
                'message' => 'Your guide account was created and is waiting for administrator approval.',
            ], 201);
        }

        $token = $user->createToken('hikevista')->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token], 201);
    }

    public function checkRegistrationEmail(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'ends_with:@gmail.com', 'regex:/^[^A-Z]+$/'],
        ]);
        $exists = User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->exists();

        return response()->json([
            'available' => ! $exists,
            'message' => $exists
                ? 'This email is already registered. Please log in or use a different email address.'
                : null,
        ]);
    }

    public function login(Request $request)
    {
        $request->merge(['email' => trim((string) $request->input('email'))]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'ends_with:@gmail.com', 'regex:/^[^A-Z]+$/'],
            'password' => 'required|string',
            'role' => 'required|in:hiker,guide,admin',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        if ($user->role !== $data['role']) {
            return response()->json([
                'message' => 'This account is not registered as '.self::ROLE_LABELS[$data['role']].'. Please choose the correct login option.',
            ], 403);
        }

        if ($user->role === 'guide' && $user->account_status === 'pending') {
            return response()->json(['message' => 'Your guide account is waiting for administrator approval.'], 403);
        }

        if ($user->role === 'guide' && $user->account_status === 'rejected') {
            return response()->json(['message' => 'Your guide account application was rejected by the administrator.'], 403);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Your account has been deactivated. Please contact the administrator.'], 403);
        }

        $token = $user->createToken('hikevista')->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token]);
    }

    public function sendPasswordResetCode(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'ends_with:@gmail.com'],
        ]);

        if (
            config('mail.default') === 'smtp'
            && (
                blank(config('mail.from.address'))
                || blank(config('mail.mailers.smtp.username'))
                || blank(config('mail.mailers.smtp.password'))
            )
        ) {
            Log::error('Password reset email is unavailable because Gmail SMTP sender settings are incomplete.');

            return response()->json([
                'message' => 'We could not send a reset code to that email right now. Please try again later.',
            ], 503);
        }

        $user = User::where('email', $data['email'])->first();
        if ($user) {
            $existingReset = DB::table('password_reset_tokens')->where('email', $data['email'])->first();
            if (
                ! $existingReset
                || ! $existingReset->created_at
                || now()->subSeconds(60)->greaterThan($existingReset->created_at)
            ) {
                $code = (string) random_int(100000, 999999);
                $tokenHash = Hash::make($code);

                DB::table('password_reset_tokens')->updateOrInsert(
                    ['email' => $data['email']],
                    ['token' => $tokenHash, 'created_at' => now()],
                );

                try {
                    $user->notify(new PasswordResetCode($code));
                } catch (Throwable $exception) {
                    DB::table('password_reset_tokens')
                        ->where('email', $data['email'])
                        ->where('token', $tokenHash)
                        ->delete();

                    throw $exception;
                }
            }
        }

        return response()->json(['message' => self::RESET_CODE_MESSAGE]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'ends_with:@gmail.com'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $attemptKey = 'password-reset-code:'.hash('sha256', $data['email']);
        if (RateLimiter::tooManyAttempts($attemptKey, 5)) {
            return response()->json(['message' => 'Too many verification attempts. Please try again later.'], 429);
        }

        RateLimiter::hit($attemptKey, 900);

        $user = DB::transaction(function () use ($data): ?User {
            $reset = DB::table('password_reset_tokens')
                ->where('email', $data['email'])
                ->lockForUpdate()
                ->first();

            if (
                ! $reset
                || ! $reset->created_at
                || Carbon::parse($reset->created_at)->isBefore(now()->subMinutes(10))
                || ! Hash::check($data['code'], $reset->token)
            ) {
                return null;
            }

            $user = User::where('email', $data['email'])->first();
            if (! $user) {
                return null;
            }

            $user->forceFill([
                'password' => $data['password'],
                'remember_token' => Str::random(60),
            ])->save();
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

            return $user;
        });

        if (! $user) {
            return response()->json(['message' => 'This verification code is invalid or expired.'], 422);
        }

        RateLimiter::clear($attemptKey);
        event(new PasswordReset($user));

        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
