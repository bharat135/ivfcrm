<?php

namespace App\Services\Telephony\Tata;

use App\Models\CallLog;
use App\Support\SettingsRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns a call transcript into the one-line note offered to the agent in the
 * disposition modal.
 *
 * With an Anthropic API key configured the note is written by Claude; without
 * one a keyword-weighted extractive summary is used so the feature degrades
 * rather than disappears. Either way the result is a single line of at most
 * 200 characters, and the agent edits it before saving — transcripts are not
 * reliable enough to commit unreviewed.
 */
class CallSummaryService
{
    public const MAX_LENGTH = 200;

    private const KEYWORDS = [
        'appointment', 'visit', 'consult', 'doctor', 'ivf', 'iui', 'icsi', 'egg', 'embryo', 'scan',
        'report', 'price', 'cost', 'package', 'emi', 'insurance', 'call back', 'callback', 'later',
        'not interested', 'busy', 'whatsapp', 'tomorrow', 'today', 'monday', 'tuesday', 'wednesday',
        'thursday', 'friday', 'saturday', 'sunday', 'morning', 'evening', 'confirm', 'book', 'centre', 'center',
    ];

    public function __construct(private SettingsRepository $settings) {}

    /**
     * @return array{summary:string, source:'ai'|'heuristic'}
     */
    public function summarize(string $transcript, ?CallLog $call = null): array
    {
        $transcript = trim(preg_replace('/\s+/', ' ', $transcript));

        if ($transcript === '') {
            return ['summary' => '', 'source' => 'heuristic'];
        }

        if ($this->aiEnabled()) {
            try {
                $summary = $this->withClaude($transcript, $call);

                if ($summary !== '') {
                    return ['summary' => $this->oneLine($summary), 'source' => 'ai'];
                }
            } catch (\Throwable $e) {
                Log::warning('Call summary via Claude failed; using heuristic', ['error' => $e->getMessage()]);
            }
        }

        return ['summary' => $this->heuristic($transcript), 'source' => 'heuristic'];
    }

    public function aiEnabled(): bool
    {
        return (bool) $this->apiKey() && $this->settings->get('telephony', 'ai_summary_enabled', true);
    }

    public function apiKey(): ?string
    {
        return $this->settings->get('ai', 'anthropic_api_key') ?: config('services.anthropic.key');
    }

    /**
     * One request, one short answer. No tools, no thinking budget to manage —
     * Claude Opus 5 runs adaptive thinking by default and a note this short
     * needs none of it.
     */
    private function withClaude(string $transcript, ?CallLog $call): string
    {
        $client = new \Anthropic\Client(apiKey: $this->apiKey());

        $context = $call
            ? sprintf(
                'Direction: %s. Contact: %s. Talk time: %s.',
                $call->direction,
                $call->contactName(),
                $call->duration_formatted,
            )
            : '';

        $response = $client->messages->create(
            model: config('services.anthropic.model', 'claude-opus-5'),
            maxTokens: 256,
            system: implode(' ', [
                'You write call notes for telecallers at an IVF fertility clinic CRM.',
                'The transcript comes from browser speech recognition and may be garbled or one-sided.',
                'Reply with exactly one line of plain text, at most ' . self::MAX_LENGTH . ' characters, no quotes, no bullet points, no preamble.',
                'State the outcome and any commitment (appointment, callback time, objection, next step). Use English.',
                'If the transcript has too little content to summarise, reply with: Call connected; no clear outcome captured.',
            ]),
            messages: [[
                'role'    => 'user',
                'content' => trim($context . "\n\nTranscript:\n" . Str::limit($transcript, 12000, '…')),
            ]],
        );

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                return trim($block->text);
            }
        }

        return '';
    }

    /**
     * Keyword-weighted extractive summary: the sentence that says the most about
     * the outcome, trimmed to one line.
     */
    public function heuristic(string $transcript): string
    {
        $sentences = preg_split('/(?<=[.!?।])\s+|\s{2,}/u', $transcript, -1, PREG_SPLIT_NO_EMPTY) ?: [$transcript];

        $best = null;
        $bestScore = -1;

        foreach ($sentences as $index => $sentence) {
            $sentence = trim($sentence);
            $lower = mb_strtolower($sentence);
            $score = 0;

            foreach (self::KEYWORDS as $keyword) {
                if (str_contains($lower, $keyword)) {
                    $score += 2;
                }
            }

            // Later sentences usually carry the conclusion.
            $score += $index / max(count($sentences), 1);

            $words = str_word_count($sentence);
            if ($words < 4) {
                $score -= 2;
            }

            if ($score > $bestScore) {
                $best = $sentence;
                $bestScore = $score;
            }
        }

        $summary = $best ?: $transcript;

        if ($bestScore < 2 && str_word_count($transcript) > 12) {
            // Nothing outcome-like; fall back to the closing words.
            $summary = Str::limit(trim(Str::substr($transcript, -self::MAX_LENGTH)), self::MAX_LENGTH, '');
            $summary = 'Discussed: ' . ltrim(Str::after($summary, ' '));
        }

        return $this->oneLine($summary);
    }

    /** Enforce the single-line, bounded-length contract of a call note. */
    public function oneLine(string $text): string
    {
        $text = trim(preg_replace('/[\r\n\t]+/', ' ', $text));
        $text = preg_replace('/\s{2,}/', ' ', $text);
        $text = trim($text, " \"'“”");

        return Str::limit($text, self::MAX_LENGTH, '…');
    }
}
