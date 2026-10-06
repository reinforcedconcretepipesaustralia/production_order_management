<?php

namespace Tests\Feature;

use App\Services\DeliveryConfirmation\DeliveryDateReplyParser;
use Tests\TestCase;

class DeliveryDateReplyParserTest extends TestCase
{
    public function test_parses_per_line_confirm_and_amend_instructions(): void
    {
        $body = <<<'TEXT'
Thanks.

LINE 101 CONFIRM
LINE 102 NEW DATE: 15/10/2026

On Mon, someone wrote:
> LINE 101 CONFIRM
> Current delivery date: 20/10/2026
TEXT;

        $result = app(DeliveryDateReplyParser::class)->parse($body, [101, 102]);

        $this->assertSame('ok', $result['status']);
        $this->assertCount(2, $result['instructions']);
        $this->assertSame('confirm', $result['instructions'][0]['action']);
        $this->assertSame(101, $result['instructions'][0]['line_unique_id']);
        $this->assertSame('amend', $result['instructions'][1]['action']);
        $this->assertSame('2026-10-15', $result['instructions'][1]['date']->toDateString());
    }

    public function test_quoted_original_dates_without_line_instructions_are_ignored(): void
    {
        $body = <<<'TEXT'
> Current delivery date: 20/10/2026
> LINE 101 CONFIRM
TEXT;

        $result = app(DeliveryDateReplyParser::class)->parse($body, [101]);

        $this->assertSame('none', $result['status']);
    }

    public function test_conflicting_instructions_for_the_same_line_are_ambiguous(): void
    {
        $body = "LINE 101 CONFIRM\nLINE 101 NEW DATE: 01/11/2026";

        $result = app(DeliveryDateReplyParser::class)->parse($body, [101]);

        $this->assertSame('ambiguous', $result['status']);
    }

    public function test_unknown_line_ids_are_ambiguous(): void
    {
        $result = app(DeliveryDateReplyParser::class)->parse('LINE 999 CONFIRM', [101]);

        $this->assertSame('ambiguous', $result['status']);
    }
}
