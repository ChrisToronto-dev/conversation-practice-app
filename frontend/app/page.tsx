"use client";

import { useState, useEffect, useRef } from 'react';
import { Mic, MicOff, Send, Loader2, Volume2, VolumeX, Download, Settings, X } from 'lucide-react';
import styles from './page.module.css';
import { fetchApi } from './lib/api';

type AppState = 'LOGIN' | 'SETUP' | 'INTERVIEW' | 'SUMMARY';

type ProgressSession = {
  id: number;
  title: string;
  topic: string | null;
  fluency_score: number | null;
  grammar_score: number | null;
  overall_score: number | null;
  feedback_generated_at: string | null;
  created_at: string | null;
};

type ProgressData = {
  averages: {
    sessions: number;
    fluency: number | null;
    grammar: number | null;
    overall: number | null;
  };
  sessions: ProgressSession[];
  recent_topics: string[];
};

function cleanTeacherResponse(rawContent: string) {
  let feedback = '';
  let response = rawContent;

  const feedbackMatch = rawContent.match(/^Feedback:\s*([\s\S]*?)\n+Response:\s*([\s\S]*)$/i);
  const responseOnlyMatch = rawContent.match(/^Response:\s*([\s\S]*)$/i);

  if (feedbackMatch) {
    feedback = feedbackMatch[1].trim();
    response = feedbackMatch[2].trim();
  } else if (responseOnlyMatch) {
    response = responseOnlyMatch[1].trim();
  }

  return { feedback, response };
}

function MarkdownRenderer({ text }: { text: string }) {
  if (!text) return null;

  const lines = text.split('\n');
  let inList = false;
  let listItems: React.ReactNode[] = [];
  const elements: React.ReactNode[] = [];
  
  let inTable = false;
  let tableRows: string[][] = [];
  let tableHeaders: string[] = [];

  const flushList = (key: number) => {
    if (listItems.length > 0) {
      elements.push(
        <ul key={`ul-${key}`} style={{ marginLeft: '1.5rem', marginBottom: '1rem', listStyleType: 'disc' }}>
          {listItems}
        </ul>
      );
      listItems = [];
      inList = false;
    }
  };

  const flushTable = (key: number) => {
    if (tableRows.length > 0 || tableHeaders.length > 0) {
      elements.push(
        <div key={`table-wrapper-${key}`} style={{ overflowX: 'auto', marginBottom: '1.2rem', width: '100%' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.85rem', border: '1px solid var(--border-color)' }}>
            {tableHeaders.length > 0 && (
              <thead>
                <tr style={{ borderBottom: '2px solid var(--border-color)', background: 'rgba(255, 255, 255, 0.05)' }}>
                  {tableHeaders.map((h, i) => (
                    <th key={`th-${i}`} style={{ padding: '10px 8px', textAlign: 'left', fontWeight: '600' }}>{h}</th>
                  ))}
                </tr>
              </thead>
            )}
            <tbody>
              {tableRows.map((row, rowIndex) => (
                <tr key={`tr-${rowIndex}`} style={{ borderBottom: '1px solid rgba(255, 255, 255, 0.08)', background: rowIndex % 2 === 1 ? 'rgba(255, 255, 255, 0.02)' : 'none' }}>
                  {row.map((cell, cellIndex) => (
                    <td key={`td-${cellIndex}`} style={{ padding: '10px 8px' }}>{cell}</td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      );
      tableRows = [];
      tableHeaders = [];
      inTable = false;
    }
  };

  const renderInline = (str: string) => {
    const parts = str.split(/\*\*([\s\S]*?)\*\*/g);
    return parts.map((part, index) => {
      if (index % 2 === 1) {
        return <strong key={index} style={{ color: '#a78bfa', fontWeight: '600' }}>{part}</strong>;
      }
      return part;
    });
  };

  lines.forEach((line, index) => {
    const trimmed = line.trim();

    // Check if table row
    if (trimmed.startsWith('|')) {
      flushList(index);
      const cells = trimmed
        .split('|')
        .map(c => c.trim())
        .filter((c, i, arr) => i > 0 && i < arr.length - 1); 
      
      const isDivider = cells.every(c => c.match(/^:-*-*|-*-*-:|:-*-*-:|-+$/));
      if (isDivider) {
        inTable = true;
      } else {
        if (!inTable) {
          tableHeaders = cells;
          inTable = true;
        } else {
          tableRows.push(cells);
        }
      }
      return;
    } else {
      if (inTable) {
        flushTable(index);
      }
    }

    // Headers
    if (trimmed.startsWith('###')) {
      flushList(index);
      elements.push(
        <h4 key={index} style={{ fontSize: '1rem', fontWeight: '600', marginTop: '1.2rem', marginBottom: '0.6rem', color: '#a78bfa' }}>
          {renderInline(trimmed.replace(/^###\s*/, ''))}
        </h4>
      );
    } else if (trimmed.startsWith('##')) {
      flushList(index);
      elements.push(
        <h3 key={index} style={{ fontSize: '1.15rem', fontWeight: '600', marginTop: '1.5rem', marginBottom: '0.8rem', color: 'var(--accent-primary)' }}>
          {renderInline(trimmed.replace(/^##\s*/, ''))}
        </h3>
      );
    } else if (trimmed.startsWith('#')) {
      flushList(index);
      elements.push(
        <h2 key={index} style={{ fontSize: '1.35rem', fontWeight: '600', marginTop: '1.8rem', marginBottom: '1rem', borderBottom: '1px solid var(--border-color)', paddingBottom: '0.3rem', color: '#fafafa' }}>
          {renderInline(trimmed.replace(/^#\s*/, ''))}
        </h2>
      );
    }
    // List items
    else if (trimmed.startsWith('-') || trimmed.startsWith('*')) {
      inList = true;
      listItems.push(
        <li key={index} style={{ marginBottom: '0.4rem', lineHeight: '1.5' }}>
          {renderInline(trimmed.replace(/^[-*]\s*/, ''))}
        </li>
      );
    }
    // Empty line
    else if (trimmed === '') {
      flushList(index);
      elements.push(<div key={`space-${index}`} style={{ height: '0.5rem' }} />);
    }
    // Standard paragraph
    else {
      flushList(index);
      elements.push(
        <p key={index} style={{ marginBottom: '0.8rem', lineHeight: '1.6', fontSize: '0.92rem' }}>
          {renderInline(line)}
        </p>
      );
    }
  });

  flushList(lines.length);
  flushTable(lines.length);

  return <div>{elements}</div>;
}

export default function Home() {
  const [appState, setAppState] = useState<AppState>('LOGIN');
  const [apiKey, setApiKey] = useState('');
  const [googleTtsKey, setGoogleTtsKey] = useState('');
  const [errorMsg, setErrorMsg] = useState('');
  const [loading, setLoading] = useState(false);

  // Key Validity State
  const [groqValid, setGroqValid] = useState(false);
  const [ttsValid, setTtsValid] = useState(false);
  const [showSettings, setShowSettings] = useState(false);
  const [showProgress, setShowProgress] = useState(false);

  // Setup state (English Practice options)
  const [englishLevel, setEnglishLevel] = useState('Intermediate');
  const [topic, setTopic] = useState('Free talking');
  const [customTopic, setCustomTopic] = useState('');
  const [teacherPersona, setTeacherPersona] = useState('Friendly & Encouraging');
  const [correctionStyle, setCorrectionStyle] = useState('realtime');
  const [teacherVoice, setTeacherVoice] = useState('female');
  const [autoSendDelay, setAutoSendDelay] = useState(3);

  // Interview state
  const [sessionId, setSessionId] = useState<number | null>(null);
  const [messages, setMessages] = useState<{role: string, content: string}[]>([]);
  
  // Summary state
  const [feedback, setFeedback] = useState('');
  const [feedbackLoading, setFeedbackLoading] = useState(false);
  const [progress, setProgress] = useState<ProgressData | null>(null);
  const [progressLoading, setProgressLoading] = useState(false);
  
  // Speech State
  const [isRecording, setIsRecording] = useState(false);
  const [transcript, setTranscript] = useState('');
  const recognitionRef = useRef<any>(null);
  const finalTranscriptRef = useRef<string>(''); // For accumulating finalized text
  const isRecordingRef = useRef(false); // Track latest isRecording for onend handler

  // TTS
  const [isSpeaking, setIsSpeaking] = useState(false);
  const [isMuted, setIsMuted] = useState(false);
  const [isLoadingAudio, setIsLoadingAudio] = useState(false);
  const [ttsError, setTtsError] = useState(false); // TTS failure indicator
  const isMutedRef = useRef(false); // Track latest state with ref
  const audioRef = useRef<HTMLAudioElement | null>(null);

  // Submission state (prevents double-submit)
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Auto-mode: silence detection for auto-submit
  const silenceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const countdownIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const [silenceCountdown, setSilenceCountdown] = useState<number | null>(null);
  const autoSendDelayRef = useRef(3);

  // Auto-start mic after AI stops speaking
  const [pendingMicStart, setPendingMicStart] = useState(false);

  // Sync refs when state changes
  useEffect(() => { isMutedRef.current = isMuted; }, [isMuted]);
  useEffect(() => { isRecordingRef.current = isRecording; }, [isRecording]);
  useEffect(() => { autoSendDelayRef.current = autoSendDelay; }, [autoSendDelay]);

  // API Usage
  const [usageInfo, setUsageInfo] = useState<{
    questions_remaining: number;
    tts: { used: number; limit: number };
    groq: { used: number; limit: number };
    google_tts: { chars_this_month: number; monthly_limit: number; chars_remaining: number };
  } | null>(null);

  // Auto scroll
  const chatEndRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    chatEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  // Function to play base64 audio (MP3 or WAV)
  const playAudioBase64 = async (audioBase64: string, mimeType: string = 'audio/mpeg') => {
    if (isMutedRef.current) return;
    // Stop previous audio
    if (audioRef.current) {
      audioRef.current.pause();
      audioRef.current = null;
    }
    setIsSpeaking(true);
    try {
      const byteChars = atob(audioBase64);
      const byteArr = new Uint8Array(byteChars.length);
      for (let i = 0; i < byteChars.length; i++) byteArr[i] = byteChars.charCodeAt(i);
      const blob = new Blob([byteArr], { type: mimeType });
      const url = URL.createObjectURL(blob);
      const audio = new Audio(url);
      audioRef.current = audio;
      audio.onended = () => {
        setIsSpeaking(false);
        URL.revokeObjectURL(url);
        // Signal auto-start mic (handled in useEffect to access latest state)
        if (!isMutedRef.current) setPendingMicStart(true);
      };
      audio.onerror = () => setIsSpeaking(false);
      await audio.play();
    } catch (e) {
      console.error('TTS playback error:', e);
      setIsSpeaking(false);
    }
  };

  // Browser-native TTS fallback (used when Gemini TTS quota is exhausted)
  const speakWithBrowserTTS = (text: string) => {
    if (!('speechSynthesis' in window)) return;
    window.speechSynthesis.cancel(); // Stop any ongoing speech
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = 'en-US';
    utterance.rate = 1.0;
    utterance.pitch = 1.0;
    setIsSpeaking(true);
    utterance.onend = () => {
      setIsSpeaking(false);
      if (!isMutedRef.current) setPendingMicStart(true);
    };
    utterance.onerror = () => setIsSpeaking(false);
    window.speechSynthesis.speak(utterance);
  };

  const fetchAndPlayTTS = async (text: string) => {
    if (isMutedRef.current) return;
    setIsLoadingAudio(true);
    setTtsError(false);
    try {
      const data = await fetchApi('/tts', {
        method: 'POST',
        body: JSON.stringify({ text, voice: teacherVoice }),
      });
      if (data.audio_base64) {
        await playAudioBase64(data.audio_base64, data.mime_type ?? 'audio/mpeg');
      }
    } catch (e: any) {
      console.warn('Google TTS failed, using browser TTS fallback:', e.message);
      speakWithBrowserTTS(text); // Seamless fallback — conversation continues
    } finally {
      setIsLoadingAudio(false);
    }
  };

  useEffect(() => {
    // Restore cached keys
    const cachedKey = localStorage.getItem('groq_api_key');
    const cachedTtsKey = localStorage.getItem('google_tts_api_key') || '';
    const cachedAutoSendDelay = Number(localStorage.getItem('auto_send_delay_seconds') || 3);
    if ([2, 3, 5].includes(cachedAutoSendDelay)) {
      setAutoSendDelay(cachedAutoSendDelay);
      autoSendDelayRef.current = cachedAutoSendDelay;
    }
    if (cachedTtsKey) setGoogleTtsKey(cachedTtsKey);
    if (cachedKey) {
      setApiKey(cachedKey);
      verifyApiKey(cachedKey, cachedTtsKey, true);
    }
    
    // Init speech
    if (typeof window !== 'undefined') {
      const SpeechRecognition = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
      if (SpeechRecognition) {
        recognitionRef.current = new SpeechRecognition();
        recognitionRef.current.continuous = true;
        recognitionRef.current.interimResults = true;
        recognitionRef.current.lang = 'en-US';

        recognitionRef.current.onresult = (event: any) => {
          let interimTranscript = '';
          for (let i = event.resultIndex; i < event.results.length; i++) {
            const t = event.results[i][0].transcript;
            if (event.results[i].isFinal) {
              // Accumulate finalized text in ref
              finalTranscriptRef.current += t;
            } else {
              // Interim results temporarily
              interimTranscript += t;
            }
          }
          // Total = Finalized + Currently speaking
          const total = finalTranscriptRef.current + interimTranscript;
          setTranscript(total);

          // Reset silence auto-submit timer whenever user speaks
          if (total.trim()) {
            // Clear existing timer
            if (silenceTimerRef.current) clearTimeout(silenceTimerRef.current);
            if (countdownIntervalRef.current) clearInterval(countdownIntervalRef.current);

            // Start countdown display
            const delaySeconds = autoSendDelayRef.current;
            let remaining = delaySeconds;
            setSilenceCountdown(remaining);
            countdownIntervalRef.current = setInterval(() => {
              remaining -= 1;
              if (remaining <= 0) {
                clearInterval(countdownIntervalRef.current!);
                countdownIntervalRef.current = null;
                setSilenceCountdown(null);
              } else {
                setSilenceCountdown(remaining);
              }
            }, 1000);

            // Auto-submit after silence
            silenceTimerRef.current = setTimeout(() => {
              silenceTimerRef.current = null;
              setSilenceCountdown(null);
              // Use a custom event to trigger submit from outside the stale closure
              window.dispatchEvent(new CustomEvent('auto-submit-answer'));
            }, delaySeconds * 1000);
          }
        };

        // Auto-restart when browser stops recognition (happens after ~30-60s silence or network hiccup)
        recognitionRef.current.onend = () => {
          if (isRecordingRef.current) {
            try {
              recognitionRef.current?.start();
            } catch (e) {
              // Ignore "already started" errors
            }
          }
        };

        recognitionRef.current.onerror = (event: any) => {
          const ignoredErrors = ['no-speech', 'audio-capture', 'aborted'];
          if (ignoredErrors.includes(event.error)) {
            // Recoverable — browser's onend will restart if still recording, or we manually aborted it
            return;
          }
          if (event.error === 'network') {
            // Transient network error — let onend handle restart
            console.warn('Speech recognition network error, will retry...');
            return;
          }
          // Non-recoverable errors (e.g., 'not-allowed', 'service-not-allowed')
          console.error('Speech recognition error:', event.error);
          if (event.error === 'not-allowed') {
            alert('Microphone access was denied. Please allow microphone access in your browser settings (click the lock icon in the URL bar) and try again.');
          }
          setIsRecording(false);
          isRecordingRef.current = false;
        };
      }
    }
  }, []);

  const fetchUsage = async () => {
    try {
      const data = await fetchApi('/usage');
      setUsageInfo(data);
    } catch (e) {
      console.error('Failed to fetch usage:', e);
    }
  };

  const fetchProgress = async () => {
    setProgressLoading(true);
    try {
      const data = await fetchApi('/interviews/history');
      setProgress(data);
    } catch (e) {
      console.error('Failed to fetch progress:', e);
    } finally {
      setProgressLoading(false);
    }
  };

  const verifyApiKey = async (key: string, ttsKey: string = '', silent = false) => {
    if(!silent) setLoading(true);
    const trimmedKey = key.trim();
    const trimmedTtsKey = ttsKey.trim();
    try {
      localStorage.setItem('groq_api_key', trimmedKey);
      setApiKey(trimmedKey);
      // Save Google TTS key (even if empty — clears old value)
      if (trimmedTtsKey) {
        localStorage.setItem('google_tts_api_key', trimmedTtsKey);
        setGoogleTtsKey(trimmedTtsKey);
      } else {
        localStorage.removeItem('google_tts_api_key');
        setGoogleTtsKey('');
      }
      const res = await fetchApi('/auth/verify', {
        method: 'POST',
        body: JSON.stringify({ api_key: trimmedKey, tts_key: trimmedTtsKey })
      });
      setGroqValid(res.groq_valid);
      setTtsValid(res.tts_valid);

      if (appState === 'LOGIN') {
        setAppState('SETUP');
        loadContexts();
      }
      fetchProgress();
      setShowSettings(false);
      if(!silent) setErrorMsg('');
    } catch (e: unknown) {
      const message = e instanceof Error && e.message !== 'UNAUTHORIZED'
        ? e.message
        : 'Invalid Groq API Key. Please check that it starts with gsk_ and has no extra characters.';
      if(!silent) setErrorMsg(message);
      localStorage.removeItem('groq_api_key');
      setGroqValid(false);
    } finally {
      if(!silent) setLoading(false);
    }
  };

  const loadContexts = async () => {
    try {
      const data = await fetchApi('/contexts');
      const el = data.find((d: any) => d.type === 'english_level');
      const t = data.find((d: any) => d.type === 'topic');
      const tp = data.find((d: any) => d.type === 'teacher_persona');
      const cs = data.find((d: any) => d.type === 'correction_style');
      const tv = data.find((d: any) => d.type === 'teacher_voice');

      if (el) setEnglishLevel(el.content);
      if (t) {
        const standardTopics = ['Free talking', 'Cafe Roleplay', 'Travel English', 'Business & Interview', 'Doctor Appointment'];
        if (standardTopics.includes(t.content)) {
          setTopic(t.content);
        } else {
          setTopic('Custom');
          setCustomTopic(t.content);
        }
      }
      if (tp) setTeacherPersona(tp.content);
      if (cs) setCorrectionStyle(cs.content);
      if (tv) setTeacherVoice(tv.content);
    } catch (e) {
      console.error(e);
    }
  };

  // Auto-speak first question after session starts
  const saveContextsAndStart = async () => {
    setLoading(true);
    try {
      const activeTopic = topic === 'Custom' ? customTopic : topic;
      localStorage.setItem('auto_send_delay_seconds', String(autoSendDelay));
      await fetchApi('/contexts', {
        method: 'POST',
        body: JSON.stringify({
          english_level: englishLevel,
          topic: activeTopic,
          teacher_persona: teacherPersona,
          correction_style: correctionStyle,
          teacher_voice: teacherVoice
        })
      });
      const data = await fetchApi('/interviews', { method: 'POST' });
      setSessionId(data.session_id);
      setMessages([{ role: 'assistant', content: data.reply }]);
      setAppState('INTERVIEW');
      fetchUsage();
      fetchProgress();
      
      const cleaned = cleanTeacherResponse(data.reply);
      fetchAndPlayTTS(cleaned.response);
    } catch(e: any) {
      alert("Error: " + e.message);
    } finally {
      setLoading(false);
    }
  };

  const clearSilenceTimer = () => {
    if (silenceTimerRef.current) { clearTimeout(silenceTimerRef.current); silenceTimerRef.current = null; }
    if (countdownIntervalRef.current) { clearInterval(countdownIntervalRef.current); countdownIntervalRef.current = null; }
    setSilenceCountdown(null);
  };

  const startRecording = async () => {
    clearSilenceTimer();
    setTranscript('');
    finalTranscriptRef.current = '';
    
    // Explicitly request microphone permission to trigger browser popup
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      // Stop the explicit stream immediately so SpeechRecognition can take over
      stream.getTracks().forEach(track => track.stop());
    } catch (err) {
      console.error('Microphone permission denied explicitly:', err);
      alert('Microphone access was denied. Please allow microphone access in your browser settings (click the lock icon in the URL bar) and try again.');
      return;
    }

    setIsRecording(true);
    isRecordingRef.current = true;
    try {
      recognitionRef.current?.start();
    } catch (e) {
      // Already started — ignore
    }
  };

  const stopRecording = () => {
    clearSilenceTimer();
    setIsRecording(false);
    isRecordingRef.current = false;
    recognitionRef.current?.stop();
  };

  // Listen for auto-submit event (fired from onresult closure to avoid stale state)
  useEffect(() => {
    const handler = () => submitAnswer();
    window.addEventListener('auto-submit-answer', handler);
    return () => window.removeEventListener('auto-submit-answer', handler);
  });

  // Auto-start mic when AI finishes speaking
  useEffect(() => {
    if (pendingMicStart && !isSpeaking && !isSubmitting && !loading) {
      setPendingMicStart(false);
      startRecording();
    }
  }, [pendingMicStart, isSpeaking, isSubmitting, loading]);

  const submitAnswer = async () => {
    if (!transcript.trim() || !sessionId || isSubmitting) return;
    
    clearSilenceTimer();
    // Stop AI voice playback
    if (audioRef.current) { audioRef.current.pause(); audioRef.current = null; }
    setIsSpeaking(false);
    stopRecording();
    const userMessage = transcript;
    setTranscript('');
    finalTranscriptRef.current = '';
    setMessages(prev => [...prev, { role: 'user', content: userMessage }]);
    setLoading(true);
    setIsSubmitting(true);
    setTtsError(false);

    try {
      // Step 1: Quickly receive text only and display immediately
      const data = await fetchApi(`/interviews/${sessionId}/chat`, {
        method: 'POST',
        body: JSON.stringify({ message: userMessage })
      });
      setMessages(prev => [...prev, { role: 'assistant', content: data.reply }]);
      setLoading(false); // Disable loading immediately upon text display
      fetchUsage();

      // Step 2: Request TTS asynchronously (screen already updated)
      const cleaned = cleanTeacherResponse(data.reply);
      if (data.audio_base64) {
        playAudioBase64(data.audio_base64);
      } else {
        fetchAndPlayTTS(cleaned.response);
      }
    } catch(e: any) {
      alert("Error: " + e.message);
      setLoading(false);
    } finally {
      setIsSubmitting(false);
    }
  };

  const downloadScript = () => {
    if (messages.length === 0) return;
    
    let textContent = "English Conversation Practice Script\n====================================\n\n";
    messages.forEach(msg => {
      if (msg.role === 'assistant') {
        const { feedback: grammarTip, response } = cleanTeacherResponse(msg.content);
        textContent += `[AI Tutor]\n${response}\n`;
        if (grammarTip) {
          textContent += `(Grammar Tip: ${grammarTip})\n`;
        }
        textContent += `\n`;
      } else {
        textContent += `[My Answer]\n${msg.content}\n\n`;
      }
    });

    const blob = new Blob([textContent], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `english_practice_script_${new Date().toISOString().slice(0,10)}.txt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };

  const fetchFeedback = async () => {
    if (!sessionId) return;
    setFeedbackLoading(true);
    setFeedback('');
    try {
      const data = await fetchApi(`/interviews/${sessionId}/feedback`, {
        method: 'POST',
        timeoutMs: 75_000
      });
      setFeedback(data.feedback);
      fetchProgress();
    } catch (e: unknown) {
      setFeedback('Error: ' + (e instanceof Error ? e.message : 'Failed to generate feedback.'));
    } finally {
      setFeedbackLoading(false);
    }
  };

  const endSession = () => {
    if (audioRef.current) { audioRef.current.pause(); audioRef.current = null; }
    setIsSpeaking(false);
    stopRecording();
    setAppState('SUMMARY');
    fetchFeedback();
  };

  if (appState === 'LOGIN') {
    return (
      <main className={styles.container}>
        <div className={styles.authBox}>
          <h1 className={styles.header}>LingoTutor</h1>
          <p className={styles.subtitle}>Enter your API keys to access</p>

          <div className={styles.inputGroup}>
            <label style={{ fontSize: '0.85rem', opacity: 0.7, marginBottom: '0.4rem', display: 'block' }}>
              Groq API Key <span style={{ color: 'var(--error-color)' }}>*</span>
            </label>
            <input
              type="password"
              placeholder="gsk_..."
              value={apiKey}
              onChange={e => setApiKey(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && verifyApiKey(apiKey, googleTtsKey)}
            />
          </div>

          <div className={styles.inputGroup}>
            <label style={{ fontSize: '0.85rem', opacity: 0.7, marginBottom: '0.4rem', display: 'block' }}>
              Google Cloud Console TTS API Key <span style={{ opacity: 0.5, fontSize: '0.8rem' }}>(optional — for AI voice)</span>
            </label>
            <input
              type="password"
              placeholder="AIza..."
              value={googleTtsKey}
              onChange={e => setGoogleTtsKey(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && verifyApiKey(apiKey, googleTtsKey)}
            />
            <p style={{ fontSize: '0.75rem', opacity: 0.45, marginTop: '0.4rem' }}>
              Without this key, browser built-in voice will be used instead.
            </p>
          </div>

          {errorMsg && <p style={{color: 'var(--error-color)', fontSize: '0.9rem'}}>{errorMsg}</p>}
          <button className={styles.btnPrimary} onClick={() => verifyApiKey(apiKey, googleTtsKey)} disabled={loading}>
            {loading ? <Loader2 className="animate-spin" /> : 'Start'}
          </button>
        </div>
      </main>
    );
  }

  const renderApiKeyWidget = () => (
    <div className={styles.apiKeyStatusWidget} onClick={() => setShowSettings(true)}>
      <div className={styles.statusItem}>
        <span className={`${styles.statusDot} ${groqValid ? styles.valid : styles.invalid}`}></span>
        Groq
      </div>
      <div className={styles.statusItem}>
        <span className={`${styles.statusDot} ${ttsValid ? styles.valid : styles.invalid}`}></span>
        TTS
      </div>
      <Settings size={14} style={{ marginLeft: '4px' }} />
    </div>
  );

  const renderSettingsModal = () => {
    if (!showSettings) return null;
    return (
      <div className={styles.modalOverlay}>
        <div className={styles.modalContent}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <h2 className={styles.header} style={{ fontSize: '1.4rem', margin: 0 }}>API Settings</h2>
            <button onClick={() => setShowSettings(false)} style={{ background: 'none', border: 'none', color: 'var(--text-muted)', cursor: 'pointer' }}>
              <X size={24} />
            </button>
          </div>
          
          <div className={styles.inputGroup}>
            <label>Groq API Key</label>
            <input type="password" value={apiKey} onChange={e => setApiKey(e.target.value)} />
          </div>
          
          <div className={styles.inputGroup}>
            <label>Google Cloud Console TTS API Key (Optional)</label>
            <input type="password" value={googleTtsKey} onChange={e => setGoogleTtsKey(e.target.value)} />
          </div>

          {errorMsg && <p style={{color: 'var(--error-color)', fontSize: '0.9rem'}}>{errorMsg}</p>}
          
          <button className={styles.btnPrimary} onClick={() => verifyApiKey(apiKey, googleTtsKey)} disabled={loading}>
            {loading ? <Loader2 className="animate-spin" /> : 'Save & Verify'}
          </button>
        </div>
      </div>
    );
  };

  const formatScore = (score: number | null) => score === null ? '-' : score.toFixed(1);

  const renderProgressModal = () => {
    if (!showProgress) return null;

    return (
      <div className={styles.modalOverlay}>
        <div className={`${styles.modalContent} ${styles.progressModal}`}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '1rem' }}>
            <div>
              <h2 className={styles.header} style={{ fontSize: '1.4rem', margin: 0, textAlign: 'left' }}>Progress</h2>
              <p className={styles.subtitle} style={{ textAlign: 'left', marginTop: '0.25rem' }}>
                Scores and topic memory for this API key
              </p>
            </div>
            <button onClick={() => setShowProgress(false)} style={{ background: 'none', border: 'none', color: 'var(--text-muted)', cursor: 'pointer' }}>
              <X size={24} />
            </button>
          </div>

          {progressLoading ? (
            <div className={styles.progressEmpty}>
              <Loader2 className="animate-spin" size={18} />
              Loading progress...
            </div>
          ) : !progress || progress.averages.sessions === 0 ? (
            <div className={styles.progressEmpty}>
              Finish a session and generate feedback to start tracking scores.
            </div>
          ) : (
            <>
              <div className={styles.scoreGrid}>
                <div className={styles.scoreCard}>
                  <span className={styles.scoreLabel}>Overall Avg</span>
                  <strong>{formatScore(progress.averages.overall)}</strong>
                  <span className={styles.scoreUnit}>/10</span>
                </div>
                <div className={styles.scoreCard}>
                  <span className={styles.scoreLabel}>Fluency Avg</span>
                  <strong>{formatScore(progress.averages.fluency)}</strong>
                  <span className={styles.scoreUnit}>/10</span>
                </div>
                <div className={styles.scoreCard}>
                  <span className={styles.scoreLabel}>Grammar Avg</span>
                  <strong>{formatScore(progress.averages.grammar)}</strong>
                  <span className={styles.scoreUnit}>/10</span>
                </div>
              </div>

              {progress.recent_topics.length > 0 && (
                <div>
                  <h3 className={styles.progressSectionTitle}>Recent Topics</h3>
                  <div className={styles.topicChips}>
                    {progress.recent_topics.map(topicName => (
                      <span key={topicName} className={styles.topicChip}>{topicName}</span>
                    ))}
                  </div>
                </div>
              )}

              <div>
                <h3 className={styles.progressSectionTitle}>Session History</h3>
                <div className={styles.historyList}>
                  {progress.sessions.map(session => (
                    <div key={session.id} className={styles.historyItem}>
                      <div>
                        <strong>{session.topic || 'Untitled topic'}</strong>
                        <span>{session.feedback_generated_at ? new Date(session.feedback_generated_at).toLocaleDateString() : 'No date'}</span>
                      </div>
                      <div className={styles.historyScores}>
                        <span>O {formatScore(session.overall_score)}</span>
                        <span>F {formatScore(session.fluency_score)}</span>
                        <span>G {formatScore(session.grammar_score)}</span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </>
          )}
        </div>
      </div>
    );
  };

  if (appState === 'SETUP') {
    return (
      <main className={styles.container}>
        {renderApiKeyWidget()}
        {renderSettingsModal()}
        {renderProgressModal()}
        <div className={styles.setupBox}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '1rem' }}>
            <div>
              <h1 className={styles.header} style={{ textAlign: 'left', marginBottom: '0.25rem' }}>LingoTutor Setup</h1>
              <p className={styles.subtitle} style={{ textAlign: 'left' }}>Choose your settings to customize your natural English speaking practice</p>
            </div>
            <button
              className={styles.btnSecondary}
              onClick={() => {
                setShowProgress(true);
                fetchProgress();
              }}
            >
              View Progress
            </button>
          </div>
          
          <div className={styles.setupGrid}>
            {/* Column 1: Level & Topic */}
            <div className={styles.setupColumn}>
              {/* English Level */}
              <div className={styles.inputGroup}>
                <label>English Level (영어 레벨)</label>
                <div className={styles.optionGrid}>
                  {['Beginner', 'Intermediate', 'Advanced'].map(level => {
                    const krText = level === 'Beginner' ? '초급' : level === 'Intermediate' ? '중급' : '고급';
                    return (
                      <button
                        key={level}
                        type="button"
                        className={`${styles.optionCard} ${englishLevel === level ? styles.active : ''}`}
                        onClick={() => setEnglishLevel(level)}
                      >
                        <span className={styles.levelName}>{level}</span>
                        <span className={styles.levelKr}>{krText}</span>
                      </button>
                    );
                  })}
                </div>
              </div>

              {/* Topic */}
              <div className={styles.inputGroup} style={{ marginTop: '1rem' }}>
                <label>Conversation Topic (대화 주제)</label>
                <select
                  value={topic}
                  onChange={e => setTopic(e.target.value)}
                  className={styles.selectInput}
                >
                  <option value="Free talking">💬 Free Talking (일상 대화)</option>
                  <option value="Cafe Roleplay">☕ Cafe Roleplay (카페에서 주문하기)</option>
                  <option value="Travel English">✈️ Travel English (공항, 호텔, 길 찾기 등)</option>
                  <option value="Business & Interview">💼 Business & Interview (비즈니스 회화 및 면접)</option>
                  <option value="Doctor Appointment">🏥 Doctor Appointment (병원 진료 및 예약)</option>
                  <option value="Custom">✍️ Custom Topic (직접 입력)</option>
                </select>

                {topic === 'Custom' && (
                  <input
                    type="text"
                    placeholder="e.g. Discussing favorite movies, Ordering pizza..."
                    value={customTopic}
                    onChange={e => setCustomTopic(e.target.value)}
                    className={styles.textInput}
                    style={{ marginTop: '0.5rem' }}
                  />
                )}
              </div>
              
              {/* Teacher Voice */}
              <div className={styles.inputGroup} style={{ marginTop: '1rem' }}>
                <label>Teacher Voice (선생님 목소리)</label>
                <div className={styles.optionGrid2}>
                  {[
                    { val: 'female', label: '👩‍🏫 Female (Samantha)' },
                    { val: 'male', label: '👨‍🏫 Male (John)' }
                  ].map(v => (
                    <button
                      key={v.val}
                      type="button"
                      className={`${styles.optionCard} ${teacherVoice === v.val ? styles.active : ''}`}
                      onClick={() => setTeacherVoice(v.val)}
                      style={{ padding: '10px' }}
                    >
                      {v.label}
                    </button>
                  ))}
                </div>
              </div>
            </div>

            {/* Column 2: Persona & Style */}
            <div className={styles.setupColumn}>
              {/* Teacher Persona */}
              <div className={styles.inputGroup}>
                <label>Teacher Persona (선생님 성향)</label>
                <div className={styles.personaGrid}>
                  {[
                    { val: 'Friendly & Encouraging', icon: '🌸', kr: '상냥하고 격려하는' },
                    { val: 'Strict & Detail-oriented', icon: '🎓', kr: '꼼꼼한 피드백' },
                    { val: 'Enthusiastic', icon: '⚡', kr: '에너지 넘치고 활발한' },
                    { val: 'Calm & Patient', icon: '🌿', kr: '차분하고 친절한' }
                  ].map(p => (
                    <button
                      key={p.val}
                      type="button"
                      className={`${styles.optionCard} ${styles.personaCard} ${teacherPersona === p.val ? styles.active : ''}`}
                      onClick={() => setTeacherPersona(p.val)}
                    >
                      <span style={{ fontSize: '1.4rem', marginBottom: '4px', display: 'block' }}>{p.icon}</span>
                      <span className={styles.personaTitle}>{p.val}</span>
                      <span className={styles.personaKr}>{p.kr}</span>
                    </button>
                  ))}
                </div>
              </div>

              {/* Correction Style */}
              <div className={styles.inputGroup} style={{ marginTop: '1.2rem' }}>
                <label>Correction Style (교정 방식)</label>
                <div className={styles.styleGrid}>
                  {[
                    { val: 'realtime', label: '💡 Real-time (매 대화마다 교정)' },
                    { val: 'flow', label: '🌊 Flow Focus (대화 흐름 유지)' },
                    { val: 'end', label: '📝 End of Session (종료 후 교정)' }
                  ].map(s => (
                    <button
                      key={s.val}
                      type="button"
                      className={`${styles.optionCard} ${correctionStyle === s.val ? styles.active : ''}`}
                      onClick={() => setCorrectionStyle(s.val)}
                      style={{ padding: '10px', fontSize: '0.85rem' }}
                    >
                      {s.label}
                    </button>
                  ))}
                </div>
              </div>

              {/* Auto-send Delay */}
              <div className={styles.inputGroup} style={{ marginTop: '1.2rem' }}>
                <label>Auto-send Delay (답변 후 자동 진행)</label>
                <div className={styles.optionGrid}>
                  {[
                    { val: 2, label: 'Fast', kr: '2초' },
                    { val: 3, label: 'Natural', kr: '3초' },
                    { val: 5, label: 'Relaxed', kr: '5초' }
                  ].map(option => (
                    <button
                      key={option.val}
                      type="button"
                      className={`${styles.optionCard} ${autoSendDelay === option.val ? styles.active : ''}`}
                      onClick={() => {
                        setAutoSendDelay(option.val);
                        localStorage.setItem('auto_send_delay_seconds', String(option.val));
                      }}
                    >
                      <span className={styles.levelName}>{option.label}</span>
                      <span className={styles.levelKr}>{option.kr}</span>
                    </button>
                  ))}
                </div>
              </div>
            </div>
          </div>

          <button className={styles.btnPrimary} onClick={saveContextsAndStart} disabled={loading} style={{ marginTop: '1rem', width: '100%' }}>
            {loading ? 'Starting Practice...' : 'Start Conversation Practice'}
          </button>
        </div>
      </main>
    );
  }

  if (appState === 'SUMMARY') {
    return (
      <main className={styles.container}>
        {renderApiKeyWidget()}
        {renderSettingsModal()}
        {renderProgressModal()}
        <div className={styles.summaryBox}>
          <h1 className={styles.header}>Session Summary</h1>
          <p className={styles.subtitle}>Review your transcript and teacher-style session feedback</p>
          
          <div className={styles.summaryControls}>
            <button className={styles.btnSecondary} onClick={downloadScript} style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
              <Download size={16} /> Save Script
            </button>
            <button
              className={styles.btnSecondary}
              onClick={() => {
                setShowProgress(true);
                fetchProgress();
              }}
            >
              View Progress
            </button>
            <button className={styles.btnPrimary} onClick={fetchFeedback} disabled={feedbackLoading}>
              {feedbackLoading ? <><Loader2 className="animate-spin" size={16} style={{display:'inline', marginRight:'6px'}}/> Analyzing...</> : 'Get Tutor Feedback'}
            </button>
            <button className={styles.btnSecondary} onClick={() => setAppState('SETUP')}>
              Start New Session
            </button>
          </div>

          <div className={styles.summaryContent}>
            <div className={`${styles.chatArea} ${styles.transcriptPanel}`}>
              <h3 style={{ fontSize: '1rem', marginBottom: '1rem', color: 'var(--text-primary)' }}>Transcript</h3>
              {messages.map((msg, i) => {
                if (msg.role === 'assistant') {
                  const { feedback: grammarTip, response } = cleanTeacherResponse(msg.content);
                  return (
                    <div key={i} className={styles.assistantMessageWrapper} style={{ maxWidth: '95%', marginBottom: '1rem' }}>
                      {grammarTip && (
                        <div className={styles.grammarFeedbackCard}>
                          <div className={styles.feedbackHeader}>
                            <span className={styles.feedbackIcon}>💡</span>
                            <span className={styles.feedbackTitle}>Grammar & Expression Tip</span>
                          </div>
                          <p className={styles.feedbackContent}>{grammarTip}</p>
                        </div>
                      )}
                      <div className={`${styles.message} ${styles.messageAssistant}`} style={{ maxWidth: '100%' }}>
                        <strong style={{opacity: 0.8}}>🤖 AI Tutor: </strong>
                        <br/>
                        <span style={{marginTop: '0.5rem', display: 'block'}}>{response}</span>
                      </div>
                    </div>
                  );
                } else {
                  return (
                    <div key={i} className={`${styles.message} ${styles.messageUser}`} style={{ maxWidth: '95%', marginBottom: '1rem' }}>
                      <strong style={{opacity: 0.8}}>👤 Me: </strong>
                      <br/>
                      <span style={{marginTop: '0.5rem', display: 'block'}}>{msg.content}</span>
                    </div>
                  );
                }
              })}
            </div>
            
            {(feedbackLoading || feedback) && (
              <div className={styles.feedbackArea}>
                {feedbackLoading && !feedback ? (
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', color: 'var(--text-muted)' }}>
                    <Loader2 className="animate-spin" size={18} />
                    <span>Analyzing your full conversation...</span>
                  </div>
                ) : (
                  <MarkdownRenderer text={feedback} />
                )}
              </div>
            )}
          </div>
        </div>
      </main>
    );
  }

  return (
    <main className={styles.container}>
      {renderApiKeyWidget()}
      {renderSettingsModal()}
      <div className={styles.interviewBox}>
        <div style={{display: 'flex', justifyContent: 'space-between', alignItems: 'center'}}>
          <div>
            <h1 className={styles.header} style={{fontSize: '1.4rem', textAlign: 'left', marginBottom: '4px'}}>English Practice Room</h1>
            <div style={{display: 'flex', gap: '6px', fontSize: '0.75rem', color: 'var(--text-muted)'}}>
              <span className={styles.setupBadge}>📶 {englishLevel}</span>
              <span className={styles.setupBadge}>💬 {topic === 'Custom' ? customTopic : topic}</span>
              <span className={styles.setupBadge}>👤 {teacherPersona}</span>
            </div>
          </div>
          <div style={{display: 'flex', gap: '8px', alignItems: 'center'}}>
            {/* API Usage Badge */}
            {usageInfo !== null && (
              <div className={`${styles.usageBadge} ${
                usageInfo.questions_remaining > 20
                  ? styles.usageBadgeGood
                  : usageInfo.questions_remaining > 5
                  ? styles.usageBadgeWarn
                  : styles.usageBadgeCritical
              }`}>
                <span className={styles.usageBadgeDot} />
                <span>
                  {usageInfo.questions_remaining} / {usageInfo.tts.limit} questions left today
                </span>
              </div>
            )}
            {/* Google TTS character usage badge — only shown when Google TTS key is active */}
            {usageInfo?.google_tts && localStorage.getItem('google_tts_api_key') && (
              <div className={`${styles.usageBadge} ${
                usageInfo.google_tts.chars_remaining > 100_000
                  ? styles.usageBadgeGood
                  : usageInfo.google_tts.chars_remaining > 10_000
                  ? styles.usageBadgeWarn
                  : styles.usageBadgeCritical
              }`} title={`Google TTS: ${usageInfo.google_tts.chars_this_month.toLocaleString()} / ${usageInfo.google_tts.monthly_limit.toLocaleString()} chars used this month`}>
                <span className={styles.usageBadgeDot} />
                <span>🔊 {usageInfo.google_tts.chars_this_month.toLocaleString()} / 1M chars</span>
              </div>
            )}
            <button 
              className={styles.btnSecondary} 
              onClick={downloadScript} 
              title="Download Script" 
              disabled={messages.length === 0}
              style={{ display: 'flex', alignItems: 'center', gap: '6px', padding: '0.4rem 0.8rem' }}
            >
              <Download size={16} /> <span>Save Script</span>
            </button>
            <button className={styles.btnSecondary} onClick={endSession}>End Session</button>
          </div>
        </div>
        
        <div className={styles.chatArea}>
          {messages.map((msg, i) => {
            if (msg.role === 'assistant') {
              const { feedback: grammarTip, response } = cleanTeacherResponse(msg.content);
              return (
                <div key={i} className={styles.assistantMessageWrapper}>
                  {grammarTip && (
                    <div className={styles.grammarFeedbackCard}>
                      <div className={styles.feedbackHeader}>
                        <span className={styles.feedbackIcon}>💡</span>
                        <span className={styles.feedbackTitle}>Grammar & Expression Tip</span>
                      </div>
                      <p className={styles.feedbackContent}>{grammarTip}</p>
                    </div>
                  )}
                  <div className={`${styles.message} ${styles.messageAssistant}`}>
                    <strong style={{opacity: 0.8}}>🤖 AI Tutor: </strong>
                    <br/>
                    <span style={{marginTop: '0.5rem', display: 'block'}}>{response}</span>
                  </div>
                </div>
              );
            } else {
              return (
                <div key={i} className={`${styles.message} ${styles.messageUser}`}>
                  <strong style={{opacity: 0.8}}>👤 Me: </strong>
                  <br/>
                  <span style={{marginTop: '0.5rem', display: 'block'}}>{msg.content}</span>
                </div>
              );
            }
          })}
          {loading && (
            <div className={`${styles.message} ${styles.messageAssistant}`}>
              <Loader2 className="animate-spin" size={20} />
            </div>
          )}
          <div ref={chatEndRef} />
        </div>

        <div className={styles.transcriptPreview}>
          {transcript
            ? (
              <>
                <span>{transcript}</span>
                {silenceCountdown !== null && (
                  <span style={{
                    display: 'block',
                    marginTop: '0.5rem',
                    fontSize: '0.78rem',
                    color: 'var(--accent-primary)',
                    opacity: 0.85,
                    fontStyle: 'italic'
                  }}>
                    ⏱ Auto-sending in {silenceCountdown}s... (click 🎤 to cancel)
                  </span>
                )}
              </>
            )
            : (
              <span style={{opacity: 0.5}}>
                {isRecording
                  ? '🎙 Listening... speak your answer'
                  : isSpeaking || isLoadingAudio
                  ? '🤖 AI Tutor is speaking — mic opens automatically after'
                  : 'Mic opens automatically after tutor speaks'}
              </span>
            )
          }
        </div>

        <div className={styles.controls}>
          {/* Mute toggle */}
          <button
            className={styles.micBtn}
            onClick={() => {
              const next = !isMuted;
              setIsMuted(next);
              if (next && audioRef.current) {
                audioRef.current.pause();
                audioRef.current = null;
                setIsSpeaking(false);
              }
            }}
            title={isMuted ? 'Unmute' : 'Mute AI Voice'}
            style={{ borderColor: isMuted ? 'var(--error-color)' : undefined, color: isMuted ? 'var(--error-color)' : undefined }}
          >
            {isMuted ? <VolumeX size={24} /> : <Volume2 size={24} />}
          </button>

          <button 
            className={`${styles.micBtn} ${isRecording ? styles.recording : ''}`}
            onClick={isRecording ? stopRecording : startRecording}
            disabled={loading || isSpeaking || isSubmitting}
          >
            {isRecording ? <MicOff size={28} /> : <Mic size={28} />}
          </button>
          
          <button 
            className={styles.btnPrimary} 
            style={{padding: '16px', borderRadius: '50%', display: 'flex'}}
            onClick={submitAnswer}
            disabled={!transcript || loading || isSubmitting}
          >
            <Send size={24} />
          </button>
        </div>

        {/* AI Voice Indicator */}
        {isLoadingAudio && !isSpeaking && (
          <p style={{ textAlign: 'center', fontSize: '0.85rem', color: 'var(--text-muted)', paddingBottom: '0.5rem' }}>
            <Loader2 className="animate-spin" size={14} style={{ display: 'inline-block', verticalAlign: 'middle', marginRight: '6px' }} />
            Preparing voice...
          </p>
        )}
        {isSpeaking && (
          <p style={{ textAlign: 'center', fontSize: '0.85rem', color: 'var(--accent-primary)', paddingBottom: '0.5rem' }}>
            🔊 AI Interviewer is speaking...
          </p>
        )}
        {ttsError && !isLoadingAudio && !isSpeaking && (
          <p style={{ textAlign: 'center', fontSize: '0.8rem', color: 'var(--text-muted)', paddingBottom: '0.5rem', opacity: 0.7 }}>
            ⚠️ Voice unavailable — text-only mode
          </p>
        )}
      </div>
    </main>
  );
}
