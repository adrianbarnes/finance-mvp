# Finance MVP — Voice Assistant for Microfinance (Proof of Concept)

A voice assistant that lets microfinance customers handle their account by talking, in their own language. Callers can:

- **check their balance**
- **apply for a loan**
- **check the status of a loan application**
- **hear when their next repayment is due**

The assistant speaks **Twi** and **Ewe**. **English** is also available for testing and checking the dialogue. Fante and Ga are planned.

This is a proof of concept, built to be plugged into an existing microfinance system later. The "microfinance" side is deliberately minimal: a test panel to add customers and change balances and loan applications. For now, calls are made from a **browser phone**. The IVR (phone line) is the next phase: mNotify first, then our own voice engine on FreeSWITCH.

## How a call works

1. **Caller lookup.** The caller is identified by **phone number**; there are no PINs.
2. **Language choice.** New numbers, and customers whose language was reset, hear one prompt offering each language in that language: *Twi: say "aane" or press 1 · Ewe: say "ɛ̃" or press 2 · English: say "yes" or press 3*. Speaking or pressing a key interrupts the prompt (barge-in). If the caller gives no input, the prompt replays twice, then the call ends politely. Returning customers skip this and are greeted in their saved language, unless **Remember caller languages** is switched off on the call console.
3. **Sign-up.** Unknown numbers are offered sign-up: the caller says their name, confirms it, and a customer is created with a zero balance.
4. **Main menu.** The caller can speak naturally or use the keypad:

   | Key | Action |
   |---|---|
   | 1 | Balance |
   | 2 | Apply for a loan |
   | 3 | Application status |
   | 4 | Repayment due |
   | 8 | Agent (stage 2, not yet available) |
   | 0 | End call |

   When applying for a loan, amounts can be spoken in English, or keyed in followed by `#`.

**Speech flow:** browser microphone → voice activity detection → WAV → **ffmpeg** (16 kHz mono, silence trimmed) → **Khaya ASR** (Twi / Ewe) → keyword intent matching → reply.

**Replies** are recorded prompt audio (generated with **Abena AI** text-to-speech). Amounts, dates and names are read in English.

**English calls** use Chrome's built-in speech recognition, so they spend no Khaya quota.

**Quota guard:** Khaya's free tier allows **10 calls a minute and 100 a month**. Every Khaya call is logged, and the app enforces both limits. Audio with no speech is never sent to Khaya. When the quota runs out, the caller is asked to use the keypad.

Design diagrams (state, sequence, class, ER, architecture) are in [`docs/diagrams/`](docs/diagrams) as Mermaid files. GitHub renders them, and StarUML can import them.

## Tech stack

- **Laravel 13** on **PHP 8.5**
- **Livewire 4** with **Flux UI**, and **Tailwind CSS 4**
- **Pest** for tests
- **SQLite** by default
- **ffmpeg** for all audio conversion
- External services:
  - **Khaya AI ASR v3** — speech recognition
  - **Abena AI** — text-to-speech for prompt audio

## Requirements

- PHP 8.5, Composer, Node.js with npm
- **ffmpeg**: `brew install ffmpeg` on macOS
- **HTTPS for the site.** Browsers only allow microphone access on secure pages. [Laravel Herd](https://herd.laravel.com) with a secured site works, e.g. `https://finance-mvp.test`.
- **Google Chrome** for the call console. English calls need its speech recognition.
- **A headset** for testing. Through laptop speakers, the microphone hears the assistant's own voice and interrupts it.

## Setup

```bash
git clone <repo-url> finance-mvp && cd finance-mvp

composer run setup        # composer install, copy .env, app key, migrations, npm install and build
php artisan storage:link  # serve prompt audio from storage/app/public
php artisan db:seed       # login account, voice prompts with their audio, demo customers
```

With Herd, secure the site: `herd secure finance-mvp`. Then set `APP_URL=https://finance-mvp.test` in `.env`.

Start the app, which runs the server, queue, logs and Vite together:

```bash
composer run dev
```

Log in at `/login` with the seeded local account:
- **Email:** `support@finance-mvp.com`
- **Password:** `password`

Change it on any shared machine.

### Environment variables

Add these to `.env`. Never commit real keys.

| Variable | Purpose |
|---|---|
| `KHAYA_DRIVER` | `fake` (default, no API calls) or `khaya` for real speech recognition |
| `KHAYA_API_KEY` | Khaya subscription key, sent as the `Ocp-Apim-Subscription-Key` header |
| `KHAYA_ASR_URL` | Defaults to `https://translation-api.ghananlp.org/asr/v3` |
| `KHAYA_MONTHLY_QUOTA` / `KHAYA_PER_MINUTE_LIMIT` | Plan limits enforced by the app (100 / 10) |
| `ABENA_API_KEY` | Abena AI key, used to voice prompts |
| `ABENA_DYNAMIC_SPEECH` | `true` voices amounts, dates and names with Abena's English voice, cached per phrase. Costs credits. |
| `FFMPEG_BINARY` | Full path to ffmpeg, e.g. `/opt/homebrew/bin/ffmpeg` (PHP-FPM may not see your shell `PATH`) |

With `KHAYA_DRIVER=fake`, use **"Type instead of speaking"** on the call console to test the whole dialogue without spending Khaya quota.

## Using the app

| Page | What it's for |
|---|---|
| **Call console** | The browser phone, plus the **Remember caller languages** switch (on: returning customers skip the language prompt; off: every caller is asked, nothing is saved). Enter a caller number, press **Call**, and talk hands-free. It has an on-screen keypad, typed input, a live transcript, the dialogue state, the call language and the quota used. |
| **Customers** | Test data: add customers, edit balances, add or edit loan applications (status, next repayment and due date), reset a customer's language, and replay recent calls including the caller's audio. |
| **Voice prompts** | The wording of every prompt by function, step and language. Generate audio with Abena (sparkle button) or upload a recording. |

Demo customers from the seeder:

| Number | Customer | Language |
|---|---|---|
| 0244000001 | Ama Mensah | Twi, with a disbursed loan and repayment due |
| 0244000002 | Kwame Owusu | none saved, so he hears the language prompt |
| 0244000003 | Selorm Agbeko | Ewe |
| 0244000004 | Test Caller | English (browser speech recognition, no Khaya) |

Any other number tests the sign-up of an unknown caller.

Repayment due is only read out for applications with status **Disbursed** and a next repayment set.

## Voice prompts and audio

The committed copy of every prompt lives in `database/seeders/data/voice-prompts/`, grouped by **function / step / language**:

```
voice-prompts/
├── manifest.json                 # wording of every prompt, in every language
├── call/greeting/twi/greeting.mp3        # browser playback
├── call/greeting/twi/greeting-8k.wav     # 8 kHz copy for the IVR
├── loan_application/ask_amount/twi/…
└── …
```

Groups are defined in `prompt_groups` in `config/conversation.php`.

To change prompts:

```bash
# 1. Edit wording on the Voice prompts page, then generate audio for prompts without it:
php artisan voice-prompts:synthesize                    # Twi (default)
php artisan voice-prompts:synthesize --language=ewe     # uses the mawuli_ewe voice
php artisan voice-prompts:synthesize --key=greeting --force

# 2. Export the database copy back to the committed bundle, then commit it:
php artisan voice-prompts:export
```

On a fresh install, `php artisan db:seed` loads the bundle and copies the audio into public storage. The seeder never overwrites prompts that already exist. To pick up updated wording on an existing database, run `php artisan migrate:fresh --seed`.

Callers' recordings are stored privately in `storage/app/private/call-audio/` and are **never committed**.

## Tests

```bash
php artisan test --compact     # Pest test suite
vendor/bin/pint                # code style
vendor/bin/phpstan analyse     # static analysis (Larastan)
```

External APIs are faked in tests, so the suite makes no network calls. The ffmpeg tests run against the real binary and are skipped if it's missing.

## Where things live

| Path | What |
|---|---|
| `app/Services/Conversation/ConversationEngine.php` | The dialogue: states, intents, replies (channel-agnostic) |
| `app/Services/Conversation/IntentResolver.php`, `NumberParser.php` | Keyword intent matching and number parsing per language |
| `config/conversation.php` | Languages, keyword lexicons, number words, keypad maps, loan limits, prompt groups |
| `app/Services/Khaya/` | Khaya ASR client and quota guard |
| `app/Services/Abena/AbenaTextToSpeech.php` | Abena AI text-to-speech client |
| `app/Services/Audio/` | ffmpeg conversion, prompt audio storage, prompt bundle |
| `app/Http/Controllers/CallController.php` | JSON endpoints used by the browser phone |
| `resources/js/call-console.js` | Browser phone: voice detection, barge-in, WAV encoding, keypad, Chrome speech recognition |
| `app/Livewire/` | Call console, customers and voice prompts pages |

## Status and next steps

- [x] Browser proof of concept: Twi, Ewe, English (testing), sign-up, menu actions, keypad fallback, Khaya quota guard
- [ ] Native-speaker review of the Twi and Ewe prompt wording and keywords (current text is a draft)
- [ ] Ewe prompt audio (needs Abena credits)
- [ ] IVR via mNotify: outbound launch from the test panel, then the inbound webhook
- [ ] Our own voice engine (FreeSWITCH → SIP provider), with full speech barge-in
- [ ] Stage 2: transfer to a human agent
- [ ] Fante and Ga
