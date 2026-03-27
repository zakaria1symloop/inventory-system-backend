<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class Otp extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'email',
        'otp',
        'type',
        'expires_at',
        'used',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used' => 'boolean',
        ];
    }

    public function isValid(): bool
    {
        return !$this->used && $this->expires_at->isFuture();
    }

    /**
     * Generate a new OTP for the given email and type.
     * Returns the plain OTP code (6 digits).
     */
    public static function generate(string $email, string $type): string
    {
        // Delete old OTPs for same email + type
        static::where('email', $email)->where('type', $type)->delete();

        $plainOtp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        static::create([
            'email' => $email,
            'otp' => Hash::make($plainOtp),
            'type' => $type,
            'expires_at' => now()->addMinutes(10),
        ]);

        return $plainOtp;
    }

    /**
     * Verify an OTP. Returns true if valid, false otherwise.
     * Marks as used on success.
     */
    public static function verify(string $email, string $plainOtp, string $type): bool
    {
        $otp = static::where('email', $email)
            ->where('type', $type)
            ->where('used', false)
            ->latest()
            ->first();

        if (!$otp || !$otp->isValid()) {
            return false;
        }

        if (!Hash::check($plainOtp, $otp->otp)) {
            return false;
        }

        $otp->update(['used' => true]);

        return true;
    }
}
