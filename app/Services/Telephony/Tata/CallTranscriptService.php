<?php

namespace App\Services\Telephony\Tata;

use App\Models\CallLog;
use App\Models\CallTranscript;
use Illuminate\Support\Str;

/**
 * Collects live transcript segments from the agent's browser while a call is
 * up, then closes the transcript and produces the suggested note.
 */
class CallTranscriptService
{
    public function __construct(private CallSummaryService $summaries) {}

    /**
     * Append (or replace, by client id) segments sent from the browser.
     *
     * @param  list<array{id?:string, t?:int|float, speaker?:string, text:string, final?:bool}>  $segments
     */
    public function append(CallLog $call, array $segments, string $provider = 'browser', ?string $language = null): CallTranscript
    {
        $transcript = CallTranscript::firstOrCreate(
            ['call_log_id' => $call->id],
            ['provider' => $provider, 'language' => $language, 'status' => 'live', 'started_at' => now(), 'segments' => []],
        );

        if ($transcript->status === 'final') {
            return $transcript;
        }

        $existing = collect($transcript->segments ?? [])->keyBy(fn ($s, $i) => $s['id'] ?? ('i' . $i));

        foreach ($segments as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $id = (string) ($segment['id'] ?? Str::uuid());

            $existing->put($id, [
                'id'      => $id,
                't'       => isset($segment['t']) ? (float) $segment['t'] : null,
                'speaker' => isset($segment['speaker']) ? Str::limit((string) $segment['speaker'], 20, '') : null,
                'text'    => Str::limit($text, 2000, ''),
                'final'   => (bool) ($segment['final'] ?? true),
            ]);
        }

        // Cap runaway transcripts at a generous size.
        $segmentsOut = $existing->sortBy(fn ($s) => $s['t'] ?? 0)->values()->take(-2000)->values()->all();

        $transcript->fill([
            'segments'   => $segmentsOut,
            'language'   => $language ?: $transcript->language,
            'transcript' => $this->render($segmentsOut),
        ])->save();

        return $transcript;
    }

    /**
     * Close the transcript and generate the suggested one-line note.
     */
    public function finalize(CallLog $call, array $segments = [], ?string $language = null): CallTranscript
    {
        $transcript = $segments
            ? $this->append($call, $segments, 'browser', $language)
            : CallTranscript::firstOrCreate(['call_log_id' => $call->id], ['provider' => 'browser', 'status' => 'live', 'segments' => []]);

        if ($transcript->status === 'final' && $transcript->summary !== null) {
            return $transcript;
        }

        $text = $transcript->transcript ?: $this->render($transcript->segments ?? []);

        ['summary' => $summary, 'source' => $source] = $this->summaries->summarize($text, $call);

        $transcript->fill([
            'transcript'     => $text,
            'summary'        => $summary !== '' ? $summary : null,
            'summary_source' => $summary !== '' ? $source : null,
            'status'         => 'final',
            'ended_at'       => now(),
        ])->save();

        return $transcript;
    }

    private function render(array $segments): string
    {
        $lines = [];

        foreach ($segments as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));

            if ($text === '' || empty($segment['final'])) {
                continue;
            }

            $lines[] = (! empty($segment['speaker']) ? ucfirst($segment['speaker']) . ': ' : '') . $text;
        }

        return implode("\n", $lines);
    }
}
