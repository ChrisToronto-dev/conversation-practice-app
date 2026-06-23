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
        ]);

        $keys = ['resume', 'qna', 'job_posting', 'english_level', 'topic', 'teacher_persona', 'correction_style', 'teacher_voice'];
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

        $session = InterviewSession::create([
            'title' => 'Session ' . now()->format('Y-m-d H:i:s'),
            'user_key_hash' => $this->userKeyHash($request),
            'topic' => $topic,
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
        $correctionStyle = InterviewContext::where('type', 'correction_style')->first()?->content ?? 'realtime';
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
            . "INSTRUCTIONS:\n"
            . "1. Actively adopt your Persona in your speech style. If 'Friendly & Encouraging', praise the user's efforts, highlight positive aspects, and be warm. If 'Strict & Detail-oriented', pay close attention to grammar and pinpoint errors. If 'Enthusiastic', show high energy, use expressions like 'Awesome!' or 'Wow!', and be very expressive. If 'Calm & Patient', speak in a gentle, slow, and supportive manner.\n"
            . "2. Adjust your vocabulary and sentence structure to match the Student's English Level. If Beginner, use simple vocabulary, extremely basic grammar, and short, clear sentences. If Intermediate, speak naturally with standard grammar, everyday phrasal verbs, and moderate speed. If Advanced, speak like a native speaker with rich vocabulary, standard idioms, and complex thoughts.\n"
            . "3. Stick to the chosen Topic/Scenario. If it's a roleplay (e.g., ordering coffee, travel check-in), play your role naturally and guide the user through the scenario.\n"
            . "4. Sound like a real one-on-one teacher, not a quiz bot. Briefly acknowledge what the student said, naturally reuse or rephrase one useful phrase from their answer, then ask one follow-up question that helps them say a little more.\n"
            . "5. Quietly choose 2-3 useful target expressions for this session based on the topic and the student's level. Work them into the conversation naturally. Occasionally invite the student to try one of them, but do not present a list during chat.\n"
            . "6. When the student says something important but awkward, you may ask them to try again with a natural model sentence, e.g. 'Nice idea. Try saying it this way: ... Now, can you say that again?' Use this sparingly, about once every few turns, so the conversation still feels smooth.\n"
            . "7. Keep your conversational response VERY CONCISE: usually 1-2 sentences, 3 only when a short correction or retry prompt needs context. Ask only ONE natural question at a time to keep the conversation flowing. Never write lists, bullet points, or markdown. It must be easy to read and synthesize for TTS.\n"
            . "8. Do not abruptly change topics. Remember details the student has already shared and refer back to them when it feels natural.\n"
            . "9. SPEAK EXCLUSIVELY IN ENGLISH in the conversational response itself. Do not translate the conversation to Korean.\n\n"
            . "CORRECTION RULE:\n";

        if ($correctionStyle === 'realtime') {
            $systemInstruction .= "If the student's message has an important grammatical error, spelling issue, or unnatural phrase that affects meaning or is very useful to fix, prepend your response with a feedback section starting with 'Feedback: ' followed by a brief, friendly correction and explanation in Korean.\n"
                . "Do NOT correct every small issue. Ignore minor imperfections when correcting them would interrupt the conversation. Prioritize high-frequency mistakes, tense errors, missing articles/prepositions, word choice problems, and sentences the student is likely to reuse.\n"
                . "Then, start a new line and write 'Response: ' followed by your natural English conversational response.\n"
                . "Example format with correction:\n"
                . "Feedback: \"I go to market yesterday\"는 과거형인 \"I went to the market yesterday\"로 쓰는 것이 자연스럽습니다. 과거 시제에 주의해 보세요!\n"
                . "Response: That sounds like a fun day! What did you buy at the market?\n\n"
                . "If the student's sentence is correct enough for smooth conversation, DO NOT include the 'Feedback:' line. Simply start with 'Response: ' followed by your conversational response.\n"
                . "Example format when correct:\n"
                . "Response: That sounds like a fun day! What did you buy at the market?";
        } else {
            $systemInstruction .= "Do NOT include any grammatical corrections in your chat responses. Keep the conversation 100% natural. Always format your output starting with 'Response: ' followed by your response.\n"
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
            return $json['choices'][0]['message']['content'] ?? "Failed to generate text.";
        }

        $status = $response->status();
        return "API Error ({$status}): Please try again.";
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
