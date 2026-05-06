<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LadderRequirementsTest extends TestCase
{
    public function test_returns_first_matching_requirement_any_of_semantics(): void
    {
        $ladder = [
            'requirements' => [
                ['event_type' => 'forum_reply_create', 'min' => 3],
                ['event_type' => 'forum_topic_create', 'min' => 1],
            ],
        ];
        $counts = [
            'forum_reply_create' => 5,
            'forum_topic_create' => 2,
        ];

        $this->assertSame(
            [
                'event_type' => 'forum_reply_create',
                'min'        => 3,
                'achieved'   => 5,
            ],
            wporgcd_check_ladder_requirements($ladder, $counts)
        );
    }
}
