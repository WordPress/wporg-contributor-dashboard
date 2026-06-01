<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LaddersEncodingTest extends TestCase
{
    private function sample(): array
    {
        return [
            'connect' => [
                'title'        => 'Connect',
                'requirements' => [
                    ['event_type' => 'forum_reply_create', 'min' => 3],
                    ['event_type' => 'wordcamp_attendee_add', 'min' => 1],
                ],
            ],
            'contribute' => [
                'title'        => 'Contribute',
                'requirements' => [
                    ['event_type' => 'blog_post_create', 'min' => 1],
                ],
            ],
        ];
    }

    public function test_encode_decode_round_trip(): void
    {
        $input   = $this->sample();
        $encoded = wporgcd_encode_ladders($input);

        // base64url alphabet only (no +, /, or padding).
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);

        $decoded = wporgcd_decode_ladders($encoded);
        $this->assertSame($input, $decoded);

        $validated = wporgcd_validate_ladders($decoded);
        $this->assertSame($input, $validated);
    }

    public function test_decode_rejects_garbage(): void
    {
        $this->assertNull(wporgcd_decode_ladders(''));
        $this->assertNull(wporgcd_decode_ladders('not base64!!!'));
        // Valid base64 of the JSON string `"hello"` (a string, not an object) — decode-shape failure.
        $this->assertNull(wporgcd_decode_ladders(rtrim(strtr(base64_encode('"hello"'), '+/', '-_'), '=')));
    }

    public function test_decode_rejects_oversized_payload(): void
    {
        $tooBig = str_repeat('A', WPORGCD_LADDER_MAX_PAYLOAD_BYTES + 1);
        $this->assertNull(wporgcd_decode_ladders($tooBig));
    }

    public function test_validate_rejects_empty_or_too_many_steps(): void
    {
        $this->assertNull(wporgcd_validate_ladders([]));

        $tooMany = [];
        for ($i = 0; $i <= WPORGCD_LADDER_MAX_STEPS; $i++) {
            $tooMany['step' . $i] = [
                'title'        => 'Step ' . $i,
                'requirements' => [],
            ];
        }
        $this->assertNull(wporgcd_validate_ladders($tooMany));
    }

    public function test_validate_drops_unknown_event_types_and_bad_mins(): void
    {
        $input = [
            'mixed' => [
                'title'        => 'Mixed',
                'requirements' => [
                    ['event_type' => 'forum_reply_create', 'min' => 3],
                    ['event_type' => 'definitely_not_a_real_event', 'min' => 5],
                    ['event_type' => 'forum_topic_create', 'min' => 0], // min < 1
                    ['event_type' => 'forum_topic_create', 'min' => WPORGCD_LADDER_MAX_MIN_VALUE + 1], // too big
                    ['event_type' => 'updated_profile', 'min' => 1], // excluded type
                    ['event_type' => 'blog_post_create', 'min' => 2],
                ],
            ],
        ];

        $validated = wporgcd_validate_ladders($input);
        $this->assertNotNull($validated);
        $this->assertSame(
            [
                ['event_type' => 'forum_reply_create', 'min' => 3],
                ['event_type' => 'blog_post_create', 'min' => 2],
            ],
            $validated['mixed']['requirements']
        );
    }

    public function test_validate_rejects_step_without_title(): void
    {
        $this->assertNull(
            wporgcd_validate_ladders([
                'no_title' => ['requirements' => []],
            ])
        );
        $this->assertNull(
            wporgcd_validate_ladders([
                'blank' => ['title' => '   ', 'requirements' => []],
            ])
        );
    }

    public function test_validate_uniquifies_colliding_step_ids(): void
    {
        $input = [
            'step!' => ['title' => 'A', 'requirements' => []],
            'step?' => ['title' => 'B', 'requirements' => []],
        ];
        $validated = wporgcd_validate_ladders($input);
        $this->assertNotNull($validated);
        $this->assertSame(['step', 'step-2'], array_keys($validated));
    }

    public function test_validate_truncates_overlong_titles(): void
    {
        $long  = str_repeat('a', WPORGCD_LADDER_MAX_TITLE_LEN + 25);
        $input = [
            's' => ['title' => $long, 'requirements' => []],
        ];
        $validated = wporgcd_validate_ladders($input);
        $this->assertNotNull($validated);
        $this->assertSame(WPORGCD_LADDER_MAX_TITLE_LEN, strlen($validated['s']['title']));
    }

    public function test_validate_caps_requirements_per_step(): void
    {
        $reqs = [];
        for ($i = 0; $i < WPORGCD_LADDER_MAX_REQS_PER_STEP + 10; $i++) {
            $reqs[] = ['event_type' => 'forum_reply_create', 'min' => 1];
        }
        $validated = wporgcd_validate_ladders([
            's' => ['title' => 'Step', 'requirements' => $reqs],
        ]);
        $this->assertNotNull($validated);
        $this->assertCount(
            WPORGCD_LADDER_MAX_REQS_PER_STEP,
            $validated['s']['requirements']
        );
    }
}
