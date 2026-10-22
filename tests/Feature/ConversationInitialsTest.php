<?php

namespace Tests\Feature;

use App\Models\Conversation;
use Tests\TestCase;

class ConversationInitialsTest extends TestCase
{
    private function initials(?string $name): string
    {
        return (new Conversation(['contact_name' => $name, 'channel' => 'whatsapp', 'contact_identifier' => '919800000000']))->initials();
    }

    public function test_initials_skip_emoji_and_stay_valid_utf8(): void
    {
        // This contact name broke the /chat inbox JSON: substr cut the first emoji in half.
        $this->assertSame('SM', $this->initials("🪷🌸🌺Sanjay Mohan🌺🌸🪷"));
        $this->assertSame('PS', $this->initials('priya sharma'));
        $this->assertTrue(mb_check_encoding($this->initials('सुनीता पाटील'), 'UTF-8'));
        $this->assertSame('#', $this->initials('🌸🌸'));
    }
}
