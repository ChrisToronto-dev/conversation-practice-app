<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\InterviewContext;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLog;
use App\Models\ApiUsageLog;
use Smalot\PdfParser\Parser;

class InterviewController extends Controller
{
    public function getContexts()
    {
        return response()->json(InterviewContext::all());
    }

    public function saveContexts(Request $request)
    {
        $request->validate([
            'resume' => 'nullable|string',
            'qna' => 'nullable|string',
            'job_posting' => 'nullable|string',
            
            // Daily English Practice context options
            'english_level' => 'nullable|string',
            'topic' => 'nullable|string',
            'teacher_persona' => 'nullable|string',
            'correction_style' => 'nullable|string',
            'teacher_voice' => 'nullable|string',
            'practice_focus' => 'nullable|string',
        ]);

        $keys = ['resume', 'qna', 'job_posting', 'english_level', 'topic', 'teacher_persona', 'correction_style', 'teacher_voice', 'practice_focus'];
        foreach ($keys as $key) {
            if ($request->has($key)) {
                InterviewContext::updateOrCreate(
                    ['type' => $key],
                    ['content' => $request->input($key) ?? '']
                );
            }
        }

        return response()->json(['success' => true]);
    }

    public function extractPdf(Request $request)
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:10240', // Max 10MB
        ]);

        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($request->file('pdf')->path());
            $text = $pdf->getText();

            // Clean up unnecessary consecutive spaces or line breaks in text
            $text = preg_replace('/\n\s*\n/', "\n\n", $text);

            return response()->json(['text' => trim($text)]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to parse PDF: ' . $e->getMessage()], 500);
        }
    }

    public function getUsage()
    {
        $geminiLimit  = (int) env('GEMINI_DAILY_LIMIT', 500);
        $ttsLimit     = (int) env('GEMINI_TTS_DAILY_LIMIT', 100);
        $googleTtsMonthlyLimit = 1_000_000; // Google TTS Neural2 free tier: 1M chars/month

        $today     = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $geminiUsed = ApiUsageLog::where('type', 'gemini')
            ->whereDate('created_at', $today)
            ->count();

        $ttsUsed = ApiUsageLog::where('type', 'tts')
            ->whereDate('created_at', $today)
            ->count();

        // Google TTS: sum of characters converted this month
        $ttsCharsThisMonth = (int) ApiUsageLog::where('type', 'tts')
            ->whereDate('created_at', '>=', $monthStart)
            ->sum('char_count');

        // Calculate remaining questions based on TTS bottleneck
        $remaining = max(0, $ttsLimit - $ttsUsed);

        return response()->json([
            'gemini' => [
                'used'      => $geminiUsed,
                'limit'     => $geminiLimit,
                'remaining' => max(0, $geminiLimit - $geminiUsed),
            ],
            'tts' => [
                'used'      => $ttsUsed,
                'limit'     => $ttsLimit,
                'remaining' => max(0, $ttsLimit - $ttsUsed),
            ],
            'google_tts' => [
                'chars_this_month'  => $ttsCharsThisMonth,
                'monthly_limit'     => $googleTtsMonthlyLimit,
                'chars_remaining'   => max(0, $googleTtsMonthlyLimit - $ttsCharsThisMonth),
            ],
            'questions_remaining' => $remaining,
            'reset_at'            => now()->endOfDay()->toIso8601String(),
        ]);
    }

    public function startSession(Request $request)
    {
        $topic = InterviewContext::where('type', 'topic')->first()?->content ?? 'Free talking';
        $englishLevel = InterviewContext::where('type', 'english_level')->first()?->content ?? 'Intermediate';
        $practiceFocus = InterviewContext::where('type', 'practice_focus')->first()?->content ?? '';
        $targetExpressions = $this->generateTargetExpressions($englishLevel, $topic, $practiceFocus);

        $session = InterviewSession::create([
            'title' => 'Session ' . now()->format('Y-m-d H:i:s'),
            'user_key_hash' => $this->userKeyHash($request),
            'topic' => $topic,
            'practice_focus' => $practiceFocus,
            'target_expressions' => $targetExpressions,
        ]);

        $reply = $this->callGroq([], $session);
        ApiUsageLog::create(['type' => 'groq']);

        $session->logs()->create([
            'role' => 'assistant',
            'content' => $reply
        ]);

        return response()->json([
            'session_id' => $session->id,
            'reply' => $reply,
            'target_expressions' => $targetExpressions,
        ]);
    }

    public function history(Request $request)
    {
        $userKeyHash = $this->userKeyHash($request);

        $baseQuery = InterviewSession::query()
            ->whereNotNull('feedback_generated_at');

        if ($userKeyHash) {
            $baseQuery->where('user_key_hash', $userKeyHash);
        }

        $averages = (clone $baseQuery)
            ->selectRaw('AVG(fluency_score) as fluency, AVG(grammar_score) as grammar, AVG(overall_score) as overall, COUNT(*) as sessions')
            ->first();

        $sessions = (clone $baseQuery)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (InterviewSession $session) => [
                'id' => $session->id,
                'title' => $session->title,
                'topic' => $session->topic,
                'fluency_score' => $session->fluency_score,
                'grammar_score' => $session->grammar_score,
                'overall_score' => $session->overall_score,
                'feedback_generated_at' => $session->feedback_generated_at?->toIso8601String(),
                'created_at' => $session->created_at?->toIso8601String(),
            ]);

        $topics = (clone $baseQuery)
            ->whereNotNull('topic')
            ->orderByDesc('created_at')
            ->limit(12)
            ->pluck('topic')
            ->unique()
            ->values();

        return response()->json([
            'averages' => [
                'sessions' => (int) ($averages->sessions ?? 0),
                'fluency' => $averages->fluency !== null ? round((float) $averages->fluency, 1) : null,
                'grammar' => $averages->grammar !== null ? round((float) $averages->grammar, 1) : null,
                'overall' => $averages->overall !== null ? round((float) $averages->overall, 1) : null,
            ],
            'sessions' => $sessions,
            'recent_topics' => $topics,
        ]);
    }

    public function chat(Request $request, $sessionId)
    {
        $request->validate([
            'message' => 'required|string'
        ]);

        $session = InterviewSession::findOrFail($sessionId);

        $session->logs()->create([
            'role' => 'user',
            'content' => $request->message
        ]);

        $reply = $this->callGroq($session->logs()->get(), $session);
        ApiUsageLog::create(['type' => 'groq']);

        $session->logs()->create([
            'role' => 'assistant',
            'content' => $reply
        ]);

        return response()->json([
            'reply' => $reply,
        ]);
    }

    public function feedback(Request $request, $sessionId)
    {
        $session = InterviewSession::findOrFail($sessionId);
        $logs = $session->logs()->orderBy('id')->get();

        $apiKey = request()->header('X-Groq-Api-Key') ?? env('GROQ_API_KEY');
        $url = "https://api.groq.com/openai/v1/chat/completions";

        $englishLevel = InterviewContext::where('type', 'english_level')->first()?->content ?? 'Intermediate';
        $topic = InterviewContext::where('type', 'topic')->first()?->content ?? 'Free talking';

        $systemInstruction = "You are a real English teacher giving end-of-session feedback after a spoken English practice session. Review the transcript between an AI tutor and a student. "
            . "Evaluate only the student's English. Write like a thoughtful human teacher: specific, honest, encouraging, and practical. Provide the full report in Korean, but keep corrected English sentences and example expressions in English.\n\n"
            . "The student's target level is: {$englishLevel}\n"
            . "The conversation topic was: {$topic}\n\n"
            . "The report MUST follow this exact Markdown structure:\n\n"
            . "# 영어 회화 세션 피드백\n\n"
            . "## 10점 만점 종합 평가\n"
            . "- **유창성 (Fluency)**: [0-10]/10 - [one concise Korean reason]\n"
            . "- **문법 정확성 (Grammar Accuracy)**: [0-10]/10 - [one concise Korean reason]\n"
            . "- **전체 점수 (Overall)**: [0-10]/10 - [one concise Korean reason]\n\n"
            . "## 선생님 총평\n"
            . "[Give a natural teacher-style Korean evaluation of the whole conversation: what felt smooth, what interrupted communication, and what the student should focus on next.]\n\n"
            . "## 꼭 고쳐야 할 중요 문장\n"
            . "Correct the student's most important incorrect or unnatural sentences. Prioritize mistakes that affect meaning, grammar accuracy, or high-frequency daily conversation. If the student made no meaningful mistakes, say so and provide 1-2 natural upgrade suggestions instead. Use this table:\n"
            . "| 내가 한 말 | 더 자연스러운 표현 | 왜 이렇게 말하면 좋은지 |\n"
            . "| :--- | :--- | :--- |\n"
            . "| [Student's original sentence] | [Correct and natural English sentence] | [Brief explanation in Korean] |\n\n"
            . "Limit this section to the 3-6 highest-value corrections. Do not invent mistakes that are not present in the transcript.\n\n"
            . "## 중요 단어와 표현 정리\n"
            . "Choose 4-6 useful words, chunks, or expressions directly related to the student's conversation and level. Include Korean meaning and a short English example sentence.\n"
            . "- **[English word/expression]**: [Korean meaning]\n"
            . "  - 예문: [Short English example]\n\n"
            . "## 다음 세션에서 바로 연습할 것\n"
            . "[Give 2-3 specific Korean practice tips based on the student's actual errors and fluency issues.]";

        $transcript = "";
        foreach ($logs as $log) {
            $role = $log->role === 'assistant' ? 'Tutor' : 'Student';
            $transcript .= "{$role}: {$log->content}\n\n";
        }

        $payload = [
            'model' => 'llama-3.3-70b-versatile',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemInstruction
                ],
                [
                    'role' => 'user',
                    'content' => "Here is the conversation transcript:\n\n" . $transcript
                ]
            ],
            'temperature' => 0.7,
        ];

        $response = Http::withToken($apiKey)->timeout(60)->post($url, $payload);
        
        if ($response->successful()) {
            ApiUsageLog::create(['type' => 'groq']);
            $json = $response->json();
            $feedbackText = $json['choices'][0]['message']['content'] ?? "Failed to generate feedback.";
            $scores = $this->parseFeedbackScores($feedbackText);

            $session->update([
                'user_key_hash' => $session->user_key_hash ?: $this->userKeyHash($request),
                'topic' => $session->topic ?: $topic,
                'fluency_score' => $scores['fluency'],
                'grammar_score' => $scores['grammar'],
                'overall_score' => $scores['overall'],
                'feedback' => $feedbackText,
                'feedback_generated_at' => now(),
            ]);

            return response()->json(['feedback' => $feedbackText]);
        }

        return response()->json(['error' => 'Failed to fetch feedback from AI'], 500);
    }

    private function callGroq($logs, ?InterviewSession $session = null)
    {
        $apiKey = request()->header('X-Groq-Api-Key');
        if (empty($apiKey)) {
            $apiKey = env('GROQ_API_KEY');
            \Illuminate\Support\Facades\Log::info("Header missing, using env key: " . substr($apiKey, 0, 10) . '...');
        } else {
            \Illuminate\Support\Facades\Log::info("Header present, using header key: " . substr($apiKey, 0, 10) . '...');
        }
        $url = "https://api.groq.com/openai/v1/chat/completions";
        
        $englishLevel = InterviewContext::where('type', 'english_level')->first()?->content ?? 'Intermediate';
        $topic = InterviewContext::where('type', 'topic')->first()?->content ?? 'Free talking';
        $teacherPersona = InterviewContext::where('type', 'teacher_persona')->first()?->content ?? 'Friendly & Encouraging';
        $correctionStyle = trim(InterviewContext::where('type', 'correction_style')->first()?->content ?? 'realtime');
        $targetExpressions = collect($session?->target_expressions ?? []);
        $targetExpressionsText = $targetExpressions->isNotEmpty()
            ? "Today's target expressions: " . $targetExpressions
                ->map(fn ($item) => ($item['expression'] ?? '') . " = " . ($item['meaning'] ?? '') . " / example: " . ($item['example'] ?? ''))
                ->filter()
                ->join('; ') . ". Use these expressions naturally during the session and encourage the student to try them.\n"
            : "";
        $previousTopics = $this->previousTopicsForCurrentUser($session, $topic);
        $previousTopicsText = $previousTopics->isNotEmpty()
            ? "Previously practiced topics for this user: " . $previousTopics->join(', ') . ". Avoid repeating these exact topics or conversation angles unless the student clearly asks for one of them first.\n"
            : "";

        $systemInstruction = "You are a professional, natural English conversation teacher and friendly chat partner. Your goal is to help the user practice speaking English through natural dialogue.\n\n"
            . "Student English Level: {$englishLevel}\n"
            . "Conversation Topic/Scenario: {$topic}\n"
            . "Your Persona: {$teacherPersona}\n"
            . "Correction Style: {$correctionStyle}\n\n"
            . $previousTopicsText
            . $targetExpressionsText
            . "INSTRUCTIONS:\n"
            . "1. Actively adopt your Persona in your speech style. If 'Friendly & Encouraging', praise the user's efforts, highlight positive aspects, and be warm. If 'Strict & Detail-oriented', pay close attention to grammar and pinpoint errors. If 'Enthusiastic', show high energy, use expressions like 'Awesome!' or 'Wow!', and be very expressive. If 'Calm & Patient', speak in a gentle, slow, and supportive manner.\n"
            . "2. Adjust your vocabulary and sentence structure to match the Student's English Level. If Beginner, use simple vocabulary, extremely basic grammar, and short, clear sentences. If Intermediate, speak naturally with standard grammar, everyday phrasal verbs, and moderate speed. If Advanced, speak like a native speaker with rich vocabulary, standard idioms, and complex thoughts.\n"
            . "3. Stick to the chosen Topic/Scenario. If it's a roleplay (e.g., ordering coffee, travel check-in), play your role naturally and guide the user through the scenario.\n"
            . "4. Sound like a real one-on-one teacher, not a quiz bot. Briefly acknowledge what the student said, naturally reuse or rephrase one useful phrase from their answer, then ask one follow-up question that helps them say a little more.\n"
            . "5. If today's target expressions are provided, center the session around them. Model one expression early, ask the student to use it, and revisit the expressions naturally across the conversation. If none are provided, quietly choose 2-3 useful target expressions for this session based on the topic and level.\n"
            . "6. When the student says something important but awkward, you may ask them to try again with a natural model sentence, e.g. 'Nice idea. Try saying it this way: ... Now, can you say that again?' Use this sparingly, about once every few turns, so the conversation still feels smooth.\n"
            . "7. Keep your conversational response VERY CONCISE: usually 1-2 sentences, 3 only when a short correction or retry prompt needs context. Ask only ONE natural question at a time to keep the conversation flowing. Never write lists, bullet points, or markdown. It must be easy to read and synthesize for TTS.\n"
            . "8. Do not abruptly change topics. Remember details the student has already shared and refer back to them when it feels natural.\n"
            . "9. SPEAK EXCLUSIVELY IN ENGLISH in the conversational response itself. Do not translate the conversation to Korean.\n\n"
            . "CORRECTION RULE:\n";

        if ($correctionStyle === 'realtime') {
            $systemInstruction .= "This correction rule OVERRIDES your friendly persona and flow instructions.\n"
                . "For EVERY non-empty student answer, you MUST prepend a feedback section starting exactly with 'Feedback: '. Never skip the Feedback section in realtime mode.\n"
                . "For the student's FIRST answer in the session, you MUST correct exactly 2 high-value sentence/phrase issues before responding. For later answers, correct exactly 2 issues when possible; if there is only one real issue, give 1 correction plus 1 natural upgrade suggestion.\n"
                . "Each correction must include: (a) the student's wrong or awkward phrase, (b) a natural corrected English version, and (c) a brief Korean explanation. Keep the feedback concise.\n"
                . "The Korean explanation MUST be written only in Korean. Do not use Japanese, Chinese, Spanish, or mixed-language words. English is allowed only for the quoted wrong/corrected phrases.\n"
                . "Prioritize repeated/high-frequency mistakes, tense errors, missing articles/prepositions, word choice problems, pronunciation-transcription mistakes, and meaning-changing errors.\n"
                . "Then, start a new line and write 'Response: ' followed by your natural English spoken tutor response. The Response must be entirely in English and should naturally include ONE corrected version in a conversational way, such as 'You could say...' or 'I'd say it like this...'. Do not repeat multiple correction phrases in the Response. Do not read or translate the Korean Feedback in the Response.\n"
                . "Required format:\n"
                . "Feedback: 1. \"I don't have any happy/hubby\" -> \"I don't really have any hobbies.\" hobby는 취미라는 뜻이고, 복수로 말할 때는 hobbies를 씁니다.\n"
                . "2. \"make it the time useful\" -> \"make my free time useful.\" time 앞에는 the보다 my free time이 자연스럽습니다.\n"
                . "Response: I see what you mean. You could say, \"I don't really have any hobbies, but I try to make my free time useful.\" What do you usually do to make your free time feel valuable?";
        } elseif ($correctionStyle === 'flow') {
            $systemInstruction .= "Prioritize conversation flow. Do NOT prepend a Korean 'Feedback:' section. If the student's sentence is awkward but understandable, naturally recast it once inside your English response, then continue the conversation.\n"
                . "Example:\n"
                . "Response: Nice, you could say, 'I watched the match last night.' What was the most exciting moment?\n\n"
                . "Only correct if the mistake blocks meaning or the target expression is being practiced. Always start with 'Response: '.";
        } else {
            $systemInstruction .= "End-of-session correction mode. Do NOT correct grammar or phrasing during chat. Do NOT recast the student's sentence. Keep the conversation 100% natural and save all corrections for the final feedback report. Always format your output starting with 'Response: ' followed by your response.\n"
                . "Example format:\n"
                . "Response: That sounds like a fun day! What did you buy at the market?";
        }

        $messages = [];
        $messages[] = [
            'role' => 'system',
            'content' => $systemInstruction
        ];

        if (count($logs) === 0) {
            $messages[] = [
                'role' => 'user',
                'content' => "Hello teacher, let's start our conversation practice. Please greet me first based on our selected topic: {$topic}. If there are previously practiced topics listed in your instruction, choose a fresh angle unless I explicitly request an old topic."
            ];
        } else {
            foreach ($logs as $log) {
                $messages[] = [
                    'role' => $log->role === 'assistant' ? 'assistant' : 'user',
                    'content' => $log->content
                ];
            }
        }

        $payload = [
            'model' => 'llama-3.3-70b-versatile',
            'messages' => $messages,
            'max_tokens' => 200,
            'temperature' => 0.8,
        ];

        // Try up to 2 times for transient errors
        $response = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = Http::withToken($apiKey)->timeout(45)->post($url, $payload);
            if ($response->successful()) break;
            if ($response->status() < 500) break;
            if ($attempt < 2) sleep(1);
        }

        if ($response->successful()) {
            $json = $response->json();
            $content = $json['choices'][0]['message']['content'] ?? "Failed to generate text.";

            return $correctionStyle === 'realtime'
                ? $this->ensureRealtimeCorrectionInResponse($content)
                : $content;
        }

        $status = $response->status();
        return "API Error ({$status}): Please try again.";
    }

    private function ensureRealtimeCorrectionInResponse(string $content): string
    {
        if (!preg_match('/^Feedback\s*:\s*([\s\S]*?)\n+Response\s*:\s*([\s\S]*)$/i', trim($content), $sections)) {
            return $content;
        }

        $feedback = trim($sections[1]);
        $response = trim($sections[2]);
        $corrected = null;

        if (preg_match('/\b(a more natural way|you can say|you could say|i would say|i\'d say|try saying)\b/i', $response)) {
            return $content;
        }

        if (preg_match('/(?:->|→)\s*[“"]([^”"]+)[”"]/u', $feedback, $matches)) {
            $corrected = trim($matches[1]);
        } elseif (preg_match('/(?:->|→)\s*([^\n.]+[.?!])/u', $feedback, $matches)) {
            $corrected = trim($matches[1]);
        }

        if (!$corrected || str_contains(strtolower($response), strtolower($corrected))) {
            return $content;
        }

        $corrected = trim($corrected);
        $spokenCorrection = preg_match('/[.?!]$/', $corrected)
            ? "You could say, \"{$corrected}\""
            : "You could say, \"{$corrected}.\"";
        $response = $spokenCorrection . ' ' . $response;

        return "Feedback: {$feedback}\nResponse: {$response}";
    }

    private function userKeyHash(?Request $request = null): ?string
    {
        $request ??= request();
        $key = $request->header('X-Groq-Api-Key')
            ?: $request->header('X-Google-TTS-Key')
            ?: env('GROQ_API_KEY')
            ?: env('GOOGLE_TTS_API_KEY');

        return $key ? hash('sha256', $key) : null;
    }

    private function previousTopicsForCurrentUser(?InterviewSession $session, string $currentTopic)
    {
        $userKeyHash = $session?->user_key_hash ?: $this->userKeyHash();

        if (!$userKeyHash) {
            return collect();
        }

        return InterviewSession::query()
            ->where('user_key_hash', $userKeyHash)
            ->whereNotNull('topic')
            ->when($session, fn ($query) => $query->where('id', '!=', $session->id))
            ->where('topic', '!=', $currentTopic)
            ->orderByDesc('created_at')
            ->limit(10)
            ->pluck('topic')
            ->unique()
            ->values();
    }

    private function parseFeedbackScores(string $feedbackText): array
    {
        return [
            'fluency' => $this->extractScore($feedbackText, ['유창성', 'Fluency']),
            'grammar' => $this->extractScore($feedbackText, ['문법 정확성', 'Grammar Accuracy']),
            'overall' => $this->extractScore($feedbackText, ['전체 점수', 'Overall']),
        ];
    }

    private function extractScore(string $feedbackText, array $labels): ?float
    {
        foreach ($labels as $label) {
            $pattern = '/(?:\*\*)?' . preg_quote($label, '/') . '(?:\*\*)?[^0-9]{0,60}([0-9]+(?:\.[0-9]+)?)\s*\/\s*10/iu';
            if (preg_match($pattern, $feedbackText, $matches)) {
                return min(10, max(0, (float) $matches[1]));
            }
        }

        return null;
    }

    private function generateTargetExpressions(string $englishLevel, string $topic, ?string $practiceFocus = null): array
    {
        $apiKey = request()->header('X-Groq-Api-Key') ?: env('GROQ_API_KEY');
        $focus = trim((string) $practiceFocus);
        $focusDescription = $focus !== '' ? $focus : $topic;

        if (!$apiKey) {
            return $this->fallbackTargetExpressions($focusDescription);
        }

        $payload = [
            'model' => 'llama-3.3-70b-versatile',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You create practical English speaking lesson targets. Return ONLY valid JSON. No markdown."
                ],
                [
                    'role' => 'user',
                    'content' => "Create 5 useful target expressions for an English conversation practice session.\n"
                        . "Student level: {$englishLevel}\n"
                        . "Main topic/scenario: {$topic}\n"
                        . "Specific focus requested by the student: {$focusDescription}\n\n"
                        . "Return a JSON array. Each item must have these keys: expression, meaning, example.\n"
                        . "expression: natural English phrase or sentence pattern.\n"
                        . "meaning: Korean meaning/explanation.\n"
                        . "example: one short English example sentence related to the focus.\n\n"
                        . "For example, if the focus is soccer/sports, include practical expressions like score, win/lose, who scored, match result, and close game."
                ],
            ],
            'max_tokens' => 700,
            'temperature' => 0.4,
        ];

        try {
            $response = Http::withToken($apiKey)->timeout(30)->post("https://api.groq.com/openai/v1/chat/completions", $payload);

            if (!$response->successful()) {
                return $this->fallbackTargetExpressions($focusDescription);
            }

            ApiUsageLog::create(['type' => 'groq']);
            $content = $response->json('choices.0.message.content') ?? '';
            $expressions = $this->decodeTargetExpressionJson($content);

            return count($expressions) > 0
                ? array_slice($expressions, 0, 6)
                : $this->fallbackTargetExpressions($focusDescription);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to generate target expressions: ' . $e->getMessage());
            return $this->fallbackTargetExpressions($focusDescription);
        }
    }

    private function decodeTargetExpressionJson(string $content): array
    {
        $clean = trim($content);

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $clean, $matches)) {
            $clean = trim($matches[1]);
        }

        $decoded = json_decode($clean, true);

        if (!is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->filter(fn ($item) => is_array($item))
            ->map(fn ($item) => [
                'expression' => trim((string) ($item['expression'] ?? '')),
                'meaning' => trim((string) ($item['meaning'] ?? '')),
                'example' => trim((string) ($item['example'] ?? '')),
            ])
            ->filter(fn ($item) => $item['expression'] !== '' && $item['example'] !== '')
            ->values()
            ->all();
    }

    private function fallbackTargetExpressions(string $focus): array
    {
        return [
            [
                'expression' => 'How did it go?',
                'meaning' => '어떻게 됐어? / 결과가 어땠어?',
                'example' => 'How did the game go yesterday?',
            ],
            [
                'expression' => 'It was a close game.',
                'meaning' => '박빙의 경기였어.',
                'example' => 'It was a close game, but our team won.',
            ],
            [
                'expression' => 'Who scored?',
                'meaning' => '누가 득점했어?',
                'example' => 'Who scored the winning goal?',
            ],
            [
                'expression' => 'They won 1-0.',
                'meaning' => '그들이 1대0으로 이겼어.',
                'example' => 'Korea won 1-0 in the final match.',
            ],
            [
                'expression' => 'I am trying to talk about ' . $focus . ' more naturally.',
                'meaning' => '이 주제에 대해 더 자연스럽게 말해보고 싶어.',
                'example' => 'I am trying to talk about sports more naturally.',
            ],
        ];
    }

    private function callGoogleTTS(string $text, string $voice = 'female'): ?array
    {
        $apiKey = request()->header('X-Google-TTS-Key') ?? env('GOOGLE_TTS_API_KEY');
        if (!$apiKey) {
            \Illuminate\Support\Facades\Log::warning('Google TTS API key not configured');
            return null;
        }

        $url = "https://texttospeech.googleapis.com/v1/text:synthesize?key={$apiKey}";

        // Map voice parameter to Neural2 voice names
        $voiceName = ($voice === 'male') ? 'en-US-Neural2-J' : 'en-US-Neural2-F';

        $payload = [
            'input'       => ['text' => $text],
            'voice'       => [
                'languageCode' => 'en-US',
                'name'         => $voiceName,
            ],
            'audioConfig' => [
                'audioEncoding' => 'MP3',
                'speakingRate'  => 1.0,
                'pitch'         => 0.0,
            ],
        ];

        $response = Http::timeout(30)->post($url, $payload);

        if (!$response->successful()) {
            \Illuminate\Support\Facades\Log::warning('Google TTS failed', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);
            return null;
        }

        $json = $response->json();
        $audioContent = $json['audioContent'] ?? null;
        if (!$audioContent) return null;

        return [
            'base64' => $audioContent,
            'mime_type' => 'audio/mpeg'
        ];
    }
    
    public function tts(Request $request)
    {
        $request->validate([
            'text' => 'required|string|max:2000',
            'voice' => 'nullable|string'
        ]);

        $voice = $request->input('voice');
        if (empty($voice)) {
            $voice = InterviewContext::where('type', 'teacher_voice')->first()?->content ?? 'female';
        }

        $audioData = $this->callGoogleTTS($request->text, $voice);

        if (!$audioData) {
            return response()->json(['error' => 'TTS generation failed on Google Cloud. Please configure Google TTS API keys.'], 500);
        }

        ApiUsageLog::create([
            'type'       => 'tts',
            'char_count' => mb_strlen($request->text), // Track characters for Google TTS usage
        ]);

        return response()->json([
            'audio_base64' => $audioData['base64'],
            'mime_type'    => $audioData['mime_type'],
        ]);
    }
}
