# LingoTutor

AI-powered English conversation practice app with a Next.js frontend and Laravel backend.

LingoTutor lets you practice speaking with an AI English teacher, receive real-time grammar tips, use voice input and TTS, and get a teacher-style end-of-session report with fluency and grammar scores.

## Features

- Natural English conversation practice by level, topic, and teacher persona
- Browser speech recognition for voice answers
- Google Cloud TTS support with browser TTS fallback
- Real-time or end-of-session correction modes
- Adjustable auto-send delay after you stop speaking
- Session summary with transcript, important corrections, key expressions, and 10-point fluency/grammar feedback
- API usage tracking for Groq and TTS requests

## Tech Stack

### Frontend

- Next.js
- TypeScript
- CSS Modules
- Lucide React icons
- Web Speech API

### Backend

- Laravel
- SQLite
- Groq chat completions API
- Google Cloud Text-to-Speech API

## Local Setup

Run the backend and frontend in separate terminals.

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Add these values to `backend/.env`:

```env
GROQ_API_KEY=your_groq_api_key
GOOGLE_TTS_API_KEY=your_google_tts_api_key_optional
```

The backend runs at `http://127.0.0.1:8000`.

### Frontend

```bash
cd frontend
npm install
npm run dev
```

The frontend runs at `http://127.0.0.1:3000`.

If Turbopack has trouble in your local environment, use:

```bash
npx next dev --webpack -H 127.0.0.1 -p 3000
```

## How To Use

1. Open `http://127.0.0.1:3000`.
2. Enter your Groq API key.
3. Optionally enter a Google Cloud TTS API key for higher-quality AI voice.
4. Choose your English level, conversation topic, teacher persona, correction style, voice, and auto-send delay.
5. Click `Start Conversation Practice`.
6. Answer by speaking into the microphone. The app auto-submits after your selected silence delay, or you can press send manually.
7. Click `End Session` when finished.
8. Review the transcript and teacher-style feedback report, including:
   - Fluency score out of 10
   - Grammar accuracy score out of 10
   - Overall score out of 10
   - Important corrected sentences
   - Key words and expressions
   - Next practice tips

## Notes

- Keep API keys in your local `.env` or browser storage only. Do not commit real keys.
- The backend uses SQLite by default.
- Voice recognition support depends on the browser.

## Developer

- ChrisToronto-dev
