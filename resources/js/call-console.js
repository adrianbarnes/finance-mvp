/**
 * Browser phone for the voice-assistant proof of concept.
 *
 * Hands-free like a phone call: the microphone stays open, a simple
 * energy-based voice activity detector (VAD) cuts the caller's speech into
 * utterances, and each utterance is sent to Laravel as a WAV file (ffmpeg on
 * the server normalises it for Khaya). Speaking or pressing a key while the
 * assistant is talking stops the prompt (barge-in). If the caller stays
 * silent after a prompt, a "no_input" event is sent.
 *
 * Languages the server marks as recognised by the client (English, used for
 * testing) skip Khaya: Chrome's Web Speech API turns speech into text here.
 */

const FRAME_SECONDS = 0.02;
const PRE_ROLL_FRAMES = 15; // 300 ms kept from before speech starts
const SPEECH_START_FRAMES = 4; // 80 ms above threshold starts an utterance
const SPEECH_END_SILENCE_MS = 900;
const MIN_SPEECH_MS = 300;
const MAX_UTTERANCE_MS = 15000;
const MIN_THRESHOLD = 0.012;
const BARGE_IN_THRESHOLD_FACTOR = 2.5;
const BARGE_IN_ECHO_FACTOR = 2.5; // caller must be this much louder than the assistant's own voice in the mic
const BARGE_IN_GRACE_MS = 500; // at the start of each prompt segment, only learn the echo level
const BARGE_IN_CONFIRM_MS = 450; // speech needed before a playing prompt is stopped
// Steps that take several digits ended with # (or a pause), e.g. an amount.
const MULTI_DIGIT_STEP_TYPES = ['ask_number'];
const MULTI_DIGIT_IDLE_MS = 3000;

const CAPTURE_WORKLET = `
class CaptureProcessor extends AudioWorkletProcessor {
    constructor() {
        super();
        this.frameSize = Math.round(sampleRate * ${FRAME_SECONDS});
        this.buffer = new Float32Array(this.frameSize);
        this.offset = 0;
    }

    process(inputs) {
        const channel = inputs[0] && inputs[0][0];

        if (channel) {
            for (let i = 0; i < channel.length; i++) {
                this.buffer[this.offset++] = channel[i];

                if (this.offset === this.frameSize) {
                    this.port.postMessage(this.buffer.slice(0));
                    this.offset = 0;
                }
            }
        }

        return true;
    }
}

registerProcessor('capture-processor', CaptureProcessor);
`;

/**
 * Encode mono float samples as a 16-bit PCM WAV file.
 */
function encodeWav(samples, sampleRate) {
    const buffer = new ArrayBuffer(44 + samples.length * 2);
    const view = new DataView(buffer);
    const writeString = (offset, text) => [...text].forEach((char, i) => view.setUint8(offset + i, char.charCodeAt(0)));

    writeString(0, 'RIFF');
    view.setUint32(4, 36 + samples.length * 2, true);
    writeString(8, 'WAVE');
    writeString(12, 'fmt ');
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true);
    view.setUint16(22, 1, true);
    view.setUint32(24, sampleRate, true);
    view.setUint32(28, sampleRate * 2, true);
    view.setUint16(32, 2, true);
    view.setUint16(34, 16, true);
    writeString(36, 'data');
    view.setUint32(40, samples.length * 2, true);

    samples.forEach((sample, i) => {
        const clamped = Math.max(-1, Math.min(1, sample));
        view.setInt16(44 + i * 2, clamped < 0 ? clamped * 0x8000 : clamped * 0x7fff, true);
    });

    return new Blob([view], { type: 'audio/wav' });
}

function concatFrames(frames) {
    const output = new Float32Array(frames.reduce((total, frame) => total + frame.length, 0));
    let offset = 0;

    frames.forEach((frame) => {
        output.set(frame, offset);
        offset += frame.length;
    });

    return output;
}

/**
 * Plays reply segments in order: recorded prompt audio when available,
 * otherwise browser speech synthesis of the text.
 */
class ReplyPlayer {
    constructor() {
        this.playing = false;
        this.generation = 0;
        this.currentAudio = null;
        this.segmentStartedAt = 0;
    }

    async play(segments) {
        const generation = ++this.generation;
        this.playing = true;

        for (const segment of segments) {
            if (generation !== this.generation) {
                return false;
            }

            this.segmentStartedAt = performance.now();
            await (segment.audio_url ? this.playAudio(segment.audio_url) : this.speak(segment.text, segment.language));
        }

        if (generation === this.generation) {
            this.playing = false;
        }

        return generation === this.generation;
    }

    stop() {
        this.generation++;
        this.playing = false;
        this.currentAudio?.pause();
        this.currentAudio = null;
        window.speechSynthesis?.cancel();
    }

    playAudio(url) {
        return new Promise((resolve) => {
            const audio = new Audio(url);
            this.currentAudio = audio;
            audio.onended = resolve;
            audio.onerror = resolve;
            audio.onpause = resolve;
            audio.play().catch(resolve);
        });
    }

    speak(text, language) {
        return new Promise((resolve) => {
            if (!window.speechSynthesis || !text) {
                resolve();

                return;
            }

            const utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = 'en-GB';
            utterance.rate = language === 'en' ? 1 : 0.9;
            utterance.onend = resolve;
            utterance.onerror = resolve;
            window.speechSynthesis.speak(utterance);
        });
    }
}

window.callConsole = (config) => {
    // Audio objects live outside Alpine's reactive proxy.
    const runtime = {
        stream: null,
        context: null,
        player: new ReplyPlayer(),
        noInputTimer: null,
        keypadTimer: null,
        vad: null,
        recognition: null,
    };

    const url = (template, uuid) => template.replace('__CALL__', uuid);

    return {
        callerNumber: config.defaultCallerNumber ?? '',
        status: 'idle',
        callUuid: null,
        customerName: null,
        language: null,
        clientRecognition: false,
        callState: null,
        stepType: null,
        busy: false,
        speaking: false,
        bargeIn: true,
        micLevel: 0,
        keypadBuffer: '',
        typedText: '',
        error: null,
        quota: config.quota,
        log: [],

        get inCall() {
            return this.callUuid !== null && this.status !== 'ended';
        },

        async startCall() {
            this.error = null;
            this.log = [];

            try {
                await this.openMicrophone();
            } catch (exception) {
                this.error = `Microphone unavailable: ${exception.message}. Use https or localhost and allow microphone access.`;

                return;
            }

            this.status = 'connecting';
            const response = await this.request('POST', config.routes.start, { caller_number: this.callerNumber, use_draft: Boolean(this.$wire?.useDraft) });

            if (!response) {
                this.closeMicrophone();
                this.status = 'idle';

                return;
            }

            this.callUuid = response.call.uuid;
            this.customerName = response.call.customer;
            this.status = 'in-call';
            this.addLog('system', `Call connected${this.customerName ? ` (${this.customerName})` : ' (unknown number)'} · IVR flow v${response.call.flow_version}${this.$wire?.useDraft ? ' (draft)' : ''}`);
            await this.handleReply(response);
        },

        async hangUp() {
            const uuid = this.callUuid;
            this.endLocally('You hung up');

            if (uuid) {
                await this.request('DELETE', url(config.routes.hangUp, uuid));
            }
        },

        destroy() {
            if (this.inCall) {
                this.hangUp();
            }

            this.closeMicrophone();
        },

        pressKey(key) {
            if (!this.inCall || this.busy) {
                return;
            }

            this.interrupt();
            this.keypadBuffer += key;
            clearTimeout(runtime.keypadTimer);

            const multiDigit = MULTI_DIGIT_STEP_TYPES.includes(this.stepType);

            if (!multiDigit || key === '#') {
                this.sendDigits();

                return;
            }

            runtime.keypadTimer = setTimeout(() => this.sendDigits(), MULTI_DIGIT_IDLE_MS);
        },

        async sendDigits() {
            clearTimeout(runtime.keypadTimer);
            const digits = this.keypadBuffer;
            this.keypadBuffer = '';

            if (digits === '' || digits === '#') {
                this.armNoInputTimer();

                return;
            }

            this.addLog('caller', `Pressed ${digits}`);
            await this.sendInput({ digits });
        },

        async sendTypedText() {
            const text = this.typedText.trim();

            if (!this.inCall || this.busy || text === '') {
                return;
            }

            this.interrupt();
            this.typedText = '';
            this.addLog('caller', `Typed: ${text}`);
            await this.sendInput({ text });
        },

        async sendInput(body) {
            const response = await this.request('POST', url(config.routes.input, this.callUuid), body);

            if (response) {
                await this.handleReply(response);
            }
        },

        async sendUtterance(samples, sampleRate) {
            const form = new FormData();
            form.append('audio', encodeWav(samples, sampleRate), 'utterance.wav');

            this.addLog('caller', `Spoke ${(samples.length / sampleRate).toFixed(1)}s of audio`);
            const response = await this.request('POST', url(config.routes.utterance, this.callUuid), form);

            if (response) {
                await this.handleReply(response);
            }
        },

        async handleReply(response) {
            const reply = response.reply;
            this.quota = response.quota ?? this.quota;
            this.callState = reply.state;
            this.stepType = reply.step_type;
            this.setLanguage(response.language);

            if (reply.heard) {
                this.addLog('heard', `${reply.heard}${reply.intent ? `  →  ${reply.intent}` : ''}`);
            }

            this.addLog('assistant', reply.segments.map((segment) => segment.text).join(' '), reply.segments);

            if (reply.suggest_keypad) {
                this.addLog('system', 'Speech recognition unavailable — use the keypad.');
            }

            const finished = await runtime.player.play(reply.segments);

            if (reply.ended) {
                this.endLocally('Assistant ended the call');

                return;
            }

            if (finished) {
                this.armNoInputTimer();
            }
        },

        setLanguage(language) {
            const clientRecognition = Boolean(language?.recognized_by_client);

            if (language?.code && language.code !== this.language) {
                this.addLog('system', `Language: ${language.code}${clientRecognition ? ' (recognised in the browser, no Khaya)' : ''}`);
            }

            this.language = language?.code ?? null;
            this.clientRecognition = clientRecognition;

            if (clientRecognition) {
                this.startBrowserRecognition();
            } else {
                this.stopBrowserRecognition();
            }
        },

        /**
         * Web Speech API recognition for client-recognised languages (English).
         */
        startBrowserRecognition() {
            if (runtime.recognition) {
                return;
            }

            const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;

            if (!Recognition) {
                this.error = 'This browser has no built-in speech recognition. Use Chrome for English calls, or the keypad / typed input.';

                return;
            }

            const recognition = new Recognition();
            recognition.lang = 'en-GB';
            recognition.continuous = true;
            recognition.interimResults = false;
            runtime.recognition = recognition;

            recognition.onspeechstart = () => {
                if (!runtime.player.playing || this.bargeIn) {
                    clearTimeout(runtime.noInputTimer);
                }
            };

            recognition.onresult = (event) => {
                const result = event.results[event.results.length - 1];
                const transcript = result?.[0]?.transcript?.trim();

                if (!result?.isFinal || !transcript || !this.inCall || this.busy) {
                    return;
                }

                if (runtime.player.playing && !this.bargeIn) {
                    return;
                }

                this.interrupt();
                this.addLog('caller', `Said (browser recognition): ${transcript}`);
                this.sendInput({ transcript });
            };

            recognition.onerror = (event) => {
                if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                    this.error = 'Speech recognition was blocked. Allow microphone access for this site.';
                }
            };

            // Chrome stops recognising after a while; keep it running for the call.
            recognition.onend = () => {
                if (runtime.recognition === recognition && this.inCall && this.clientRecognition) {
                    try {
                        recognition.start();
                    } catch {
                        // Already restarting.
                    }
                }
            };

            recognition.start();
        },

        stopBrowserRecognition() {
            const recognition = runtime.recognition;
            runtime.recognition = null;
            recognition?.abort();
        },

        armNoInputTimer() {
            clearTimeout(runtime.noInputTimer);

            if (!this.inCall) {
                return;
            }

            runtime.noInputTimer = setTimeout(async () => {
                if (!this.inCall || this.busy || this.speaking || this.keypadBuffer !== '') {
                    return;
                }

                this.addLog('system', `No input for ${config.noInputSeconds}s`);
                await this.sendInput({ event: 'no_input' });
            }, config.noInputSeconds * 1000);
        },

        /**
         * Caller started speaking or pressed a key: stop the prompt.
         */
        interrupt() {
            clearTimeout(runtime.noInputTimer);

            if (runtime.player.playing) {
                runtime.player.stop();
                this.addLog('system', 'Prompt interrupted');
            }
        },

        endLocally(reason) {
            clearTimeout(runtime.noInputTimer);
            clearTimeout(runtime.keypadTimer);
            runtime.player.stop();

            if (this.callUuid) {
                this.addLog('system', reason);
            }

            this.status = 'ended';
            this.callUuid = null;
            this.speaking = false;
            this.stopBrowserRecognition();
            this.clientRecognition = false;
            this.language = null;
            this.closeMicrophone();
        },

        async request(method, endpoint, body = null) {
            this.busy = true;

            try {
                const isForm = body instanceof FormData;
                const response = await fetch(endpoint, {
                    method,
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': config.csrfToken,
                        ...(body && !isForm ? { 'Content-Type': 'application/json' } : {}),
                    },
                    body: body ? (isForm ? body : JSON.stringify(body)) : null,
                });

                if (response.status === 204) {
                    return {};
                }

                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    this.error = payload.message ?? `Request failed (${response.status})`;

                    if (response.status === 409) {
                        this.endLocally('Call already ended');
                    }

                    return null;
                }

                return payload;
            } catch (exception) {
                this.error = exception.message;

                return null;
            } finally {
                this.busy = false;
            }
        },

        addLog(speaker, text, segments = null) {
            this.log.push({ id: this.log.length + 1, speaker, text, segments, at: new Date().toLocaleTimeString() });
            this.$nextTick(() => {
                const panel = this.$refs.log;

                if (panel) {
                    panel.scrollTop = panel.scrollHeight;
                }
            });
        },

        async openMicrophone() {
            runtime.stream = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 },
            });

            runtime.context = new AudioContext();
            const workletUrl = URL.createObjectURL(new Blob([CAPTURE_WORKLET], { type: 'application/javascript' }));
            await runtime.context.audioWorklet.addModule(workletUrl);
            URL.revokeObjectURL(workletUrl);

            const source = runtime.context.createMediaStreamSource(runtime.stream);
            const capture = new AudioWorkletNode(runtime.context, 'capture-processor');
            source.connect(capture);
            capture.connect(runtime.context.destination);

            runtime.vad = { floor: 0.005, echoFloor: 0, voicedFrames: 0, silentMs: 0, speechMs: 0, frames: [], preRoll: [], bargeInPending: false };
            capture.port.onmessage = (event) => this.onFrame(event.data, runtime.context.sampleRate);
        },

        closeMicrophone() {
            runtime.stream?.getTracks().forEach((track) => track.stop());
            runtime.context?.close().catch(() => {});
            runtime.stream = null;
            runtime.context = null;
            runtime.vad = null;
            this.micLevel = 0;
        },

        /**
         * Energy-based voice activity detection on 20 ms frames.
         */
        onFrame(frame, sampleRate) {
            const vad = runtime.vad;

            if (!vad) {
                return;
            }

            const rms = Math.sqrt(frame.reduce((sum, sample) => sum + sample * sample, 0) / frame.length);
            this.micLevel = Math.min(100, Math.round(rms * 600));

            const frameMs = FRAME_SECONDS * 1000;
            const promptPlaying = runtime.player.playing;

            // Client-recognised languages use the browser's speech recognition instead.
            if (this.clientRecognition) {
                return;
            }

            if (!this.inCall || this.busy || (promptPlaying && !this.bargeIn)) {
                vad.voicedFrames = 0;

                return;
            }

            if (promptPlaying && !this.speaking) {
                // Learn how loud the assistant's own voice is in the microphone (speakers, no headset).
                vad.echoFloor = vad.echoFloor * 0.9 + rms * 0.1;

                if (performance.now() - runtime.player.segmentStartedAt < BARGE_IN_GRACE_MS) {
                    vad.voicedFrames = 0;

                    return;
                }
            } else if (!promptPlaying) {
                vad.echoFloor *= 0.9;
            }

            const baseThreshold = Math.max(MIN_THRESHOLD, vad.floor * 3);
            const threshold = promptPlaying
                ? Math.max(baseThreshold * BARGE_IN_THRESHOLD_FACTOR, vad.echoFloor * BARGE_IN_ECHO_FACTOR)
                : baseThreshold;

            if (!this.speaking) {
                vad.preRoll.push(frame);

                if (vad.preRoll.length > PRE_ROLL_FRAMES) {
                    vad.preRoll.shift();
                }

                if (rms > threshold) {
                    vad.voicedFrames++;
                } else {
                    vad.voicedFrames = 0;

                    if (!promptPlaying) {
                        vad.floor = vad.floor * 0.95 + rms * 0.05;
                    }
                }

                if (vad.voicedFrames >= SPEECH_START_FRAMES) {
                    this.speaking = true;
                    vad.frames = [...vad.preRoll];
                    vad.silentMs = 0;
                    vad.speechMs = vad.voicedFrames * frameMs;
                    vad.bargeInPending = promptPlaying;

                    if (!promptPlaying) {
                        clearTimeout(runtime.noInputTimer);
                    }
                }

                return;
            }

            vad.frames.push(frame);
            const totalMs = vad.frames.length * frameMs;

            if (rms > threshold * 0.6) {
                vad.silentMs = 0;
                vad.speechMs += frameMs;
            } else {
                vad.silentMs += frameMs;
            }

            // Only stop a playing prompt once the caller has clearly been speaking for a while.
            if (vad.bargeInPending && vad.speechMs >= BARGE_IN_CONFIRM_MS) {
                vad.bargeInPending = false;
                this.interrupt();
            }

            if (vad.silentMs >= SPEECH_END_SILENCE_MS || totalMs >= MAX_UTTERANCE_MS) {
                const samples = concatFrames(vad.frames);
                const speechMs = vad.speechMs;
                const promptStillPlaying = runtime.player.playing;

                this.speaking = false;
                vad.frames = [];
                vad.preRoll = [];
                vad.voicedFrames = 0;
                vad.bargeInPending = false;

                if (speechMs < MIN_SPEECH_MS) {
                    // A blip or echo: leave the prompt playing; its end arms the no-input timer.
                    if (!promptStillPlaying) {
                        this.armNoInputTimer();
                    }

                    return;
                }

                this.interrupt();
                this.sendUtterance(samples, sampleRate);
            }
        },
    };
};
