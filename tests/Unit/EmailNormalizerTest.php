<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\EmailNormalizer;
use Tests\TestCase;

class EmailNormalizerTest extends TestCase
{
    public function test_it_trims_leading_and_trailing_whitespace(): void
    {
        $input = "  user@example.com \t\n";
        $normalized = EmailNormalizer::normalize($input);

        $this->assertSame('user@example.com', $normalized);
    }

    public function test_it_converts_both_local_part_and_domain_to_lowercase_utf8(): void
    {
        $input = 'John.Doe+STORE01@EXAMPLE.COM';
        $normalized = EmailNormalizer::normalize($input);

        $this->assertSame('john.doe+store01@example.com', $normalized);
    }

    public function test_it_preserves_dots_and_plus_addressing_tags(): void
    {
        $input = 'owner.name+branch01@gmail.com';
        $normalized = EmailNormalizer::normalize($input);

        $this->assertSame('owner.name+branch01@gmail.com', $normalized);
    }

    public function test_it_converts_international_domain_names_to_ascii_punycode(): void
    {
        // münchen.de -> xn--mnchen-3ya.de
        $input = 'chef@münchen.de';
        $normalized = EmailNormalizer::normalize($input);

        $this->assertSame('chef@xn--mnchen-3ya.de', $normalized);
    }

    public function test_it_normalizes_unicode_to_nfc(): void
    {
        // Decomposed e + combining acute accent (\u{0065}\u{0301}) -> precomposed é (\u{00e9})
        $decomposed = 'caf'."\u{0065}\u{0301}".'@example.com';
        $precomposed = 'café@example.com';

        $normalizedDecomposed = EmailNormalizer::normalize($decomposed);
        $normalizedPrecomposed = EmailNormalizer::normalize($precomposed);

        $this->assertSame('café@example.com', $normalizedDecomposed);
        $this->assertSame($normalizedPrecomposed, $normalizedDecomposed);
    }

    public function test_it_handles_null_and_empty_strings_gracefully(): void
    {
        $this->assertSame('', EmailNormalizer::normalize(null));
        $this->assertSame('', EmailNormalizer::normalize('   '));
    }

    public function test_it_handles_malformed_emails_without_crashing(): void
    {
        $this->assertSame('plainaddress', EmailNormalizer::normalize(' PlainAddress '));
        $this->assertSame('@domain.com', EmailNormalizer::normalize('@domain.com'));
        $this->assertSame('user@', EmailNormalizer::normalize('user@'));
    }

    public function test_user_model_automatically_synchronizes_email_normalized(): void
    {
        $user = new User;
        $user->email = '  Owner.POS+HQ@Domain.COM  ';

        $this->assertSame('owner.pos+hq@domain.com', $user->email_normalized);
    }

    public function test_user_model_automatically_synchronizes_pending_email_normalized(): void
    {
        $user = new User;
        $user->pending_email = '  New.Email@Apeiron.Internal ';

        $this->assertSame('new.email@apeiron.internal', $user->pending_email_normalized);
    }
}
