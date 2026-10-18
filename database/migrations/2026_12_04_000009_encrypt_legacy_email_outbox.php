<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('email_outbox')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                $raw = (string) $row->body_text;
                $wasEncrypted = false;
                try {
                    $body = decrypt($raw, false);
                    $wasEncrypted = true;
                } catch (Throwable) {
                    $body = $raw;
                }

                $failed = false;
                if ($row->event_type === 'owner_onboarding') {
                    $body = preg_replace_callback(
                        '#(https?://[^\s?]+/onboarding/set-password)(?:\?token=|/)([A-Za-z0-9_-]{40,})[^\s]*#',
                        function (array $match) use ($row, &$failed): string {
                            $hash = preg_match('/\A[a-f0-9]{64}\z/i', $match[2]) ? strtolower($match[2]) : hash('sha256', $match[2]);
                            $tokenExists = DB::table('owner_onboarding_tokens')
                                ->where('token_hash', $hash)
                                ->where('tenant_id', $row->tenant_id)
                                ->where('user_id', $row->user_id)
                                ->exists();
                            if (! $tokenExists) {
                                $failed = true;

                                return '[onboarding link removed; resend required]';
                            }

                            return $match[1].'/'.$hash;
                        },
                        $body,
                    ) ?? $body;

                    if (preg_match('/(?:[?&]token=|set-password\/)([A-Za-z0-9_-]{40,})/', $body)) {
                        $body = '[legacy onboarding link removed; resend required]';
                        $failed = true;
                    }
                }

                DB::table('email_outbox')->where('id', $row->id)->update([
                    'body_text' => Crypt::encryptString($body),
                    'status' => $failed && $row->status === 'pending' ? 'failed' : $row->status,
                    'last_error' => $failed ? 'legacy_onboarding_token_unverifiable' : $row->last_error,
                    'next_attempt_at' => $failed ? null : $row->next_attempt_at,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Irreversible by design: plaintext onboarding credentials are never restored.
    }
};
