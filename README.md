# Finance MVP — Voice Assistant for Microfinance (Proof of Concept)

A voice assistant that lets microfinance customers handle their account by talking, in their own language. Callers can:

- **check their balance**
- **apply for a loan**
- **check the status of a loan application**
- **hear when their next repayment is due**

The assistant speaks **Twi** and **Ewe**. **English** is also available for testing and checking the dialogue. Fante and Ga are planned.

This is a proof of concept, built to be plugged into an existing microfinance system later. The "microfinance" side is deliberately minimal: a test panel to add customers and change balances and loan applications. For now, calls are made from a **browser phone**. The IVR (phone line) is the next phase, on our own voice engine (FreeSWITCH to a SIP provider).

## How a call works

The call follows the **IVR flow** built on the IVR flow page. Version 1, installed by the seeder, works like this (every step can be changed, moved or removed in the builder):

1. **Caller lookup.** The caller is identified by **phone number**; there are no PINs.
2. **Language choice.** New numbers, and customers whose language was reset, hear one prompt offering each enabled language in that language, in the order set on the **Languages** page: *Twi: say "aane" or press 1 · Ewe: say "ɛ̃" or press 2 · English: say "yes" or press 3*. Speaking or pressing a key interrupts the prompt (barge-in). If the caller gives no input, the prompt replays twice, then the call ends politely. If an answer isn't understood, the assistant says so and asks again. After **3 misunderstood answers in a row** at any step, it explains that it can't understand the caller and ends the call. Returning customers skip this and are greeted in their saved language, unless **Remember caller languages** is switched off on the call console, or their language has since been switched off or deleted.
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

**Replies** are the recordings uploaded for each step, played in order. A step plays in the caller's language when it has a recording in that language for every piece, otherwise in the primary language, otherwise in the first language that has them all. Amounts, dates and names are read in English for now. Version 1's Twi recordings were generated with Abena AI as placeholders until real recordings are uploaded.

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
  - **Abena AI** — optional English voice for amounts, dates and names (placeholder Twi audio in version 1 also came from it)

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
php artisan db:seed       # login account, IVR flow version 1 with its audio, demo customers
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
| `ABENA_API_KEY` | Abena AI key, only used when `ABENA_DYNAMIC_SPEECH` is on |
| `ABENA_DYNAMIC_SPEECH` | `true` voices amounts, dates and names with Abena's English voice, cached per phrase. Costs credits. |
| `FFMPEG_BINARY` | Full path to ffmpeg, e.g. `/opt/homebrew/bin/ffmpeg` (PHP-FPM may not see your shell `PATH`) |

With `KHAYA_DRIVER=fake`, use **"Type instead of speaking"** on the call console to test the whole dialogue without spending Khaya quota.

## Using the app

| Page | What it's for |
|---|---|
| **Call console** | The browser phone, plus the **Test draft** switch (on: calls run the draft IVR flow instead of the published one) and the **Remember caller languages** switch (on: returning customers skip the language prompt; off: every caller is asked, nothing is saved). Enter a caller number, press **Call**, and talk hands-free. It has an on-screen keypad, typed input, a live transcript, the dialogue state, the call language and the quota used. |
| **Customers** | Test data: add customers, edit balances, add or edit loan applications (status, next repayment and due date), reset a customer's language, and replay recent calls including the caller's audio. |
| **IVR flow** | The visual IVR builder: the whole call as a top-down flowchart. Add a step anywhere with **+**, drag steps by their handle (also into another branch), add options to a menu, fold or unfold branches and zoom. Click a step to set it up (keys and words for menu options, number ranges, what a check looks at, where a go-to jumps) and to build what it says from recordings, values spoken at call time (balance, dates…) and saved answers. Use the **Audio language** switcher to upload each step's recording per language; badges show whether a step has its own audio, plays the fallback language, or has none. The **Clip library** lists every recording; **Settings** holds the no-input and misunderstood limits. Edits, including uploads, go to a **draft**: try it with **Test draft** on the call console, then **Publish** to make it live (publishing is refused while a step has no recording in any language, a menu has two options on one key, and so on). **Discard draft** starts again from the live version. A call in progress keeps the version it started on. **Versions** lists every version: copy an earlier one into the draft (to test, then publish), publish an archived one again straight away, or delete archived versions; deleting also removes audio no remaining version uses, while past calls keep their transcripts and recordings. |
| **Languages** | Add, edit, reorder (drag), switch on or off, and soft delete or restore the languages callers can choose. Each language sets how speech is recognised (Khaya, the browser for English testing, or keypad only), the words that pick it on the language choice, its yes/no words and its number words. One language is **primary**: it's used until the caller chooses, and it can't be switched off or deleted. New languages start switched off. |

Demo customers from the seeder:

| Number | Customer | Language |
|---|---|---|
| 0244000001 | Ama Mensah | Twi, with a disbursed loan and repayment due |
| 0244000002 | Kwame Owusu | none saved, so the call starts with the language prompt |
| 0244000003 | Selorm Agbeko | Ewe |
| 0244000004 | Test Caller | English (browser speech recognition, no Khaya) |

Any other number tests the sign-up of an unknown caller.

Repayment due is only read out for applications with status **Disbursed** and a next repayment set.

## Audio

Recordings are uploaded per step and language on the **IVR flow** page (any common audio format). ffmpeg converts each upload to MP3 for the browser phone and 8 kHz WAV for the IVR. Files are stored in `storage/app/public/flow-audio/`, named by their content and never overwritten, so flow versions can share them.

Version 1 of the flow starts from the committed bundle in `database/seeders/data/voice-prompts/`: `manifest.json` holds the wording of every clip in every language, with audio grouped by **function / step / language** (e.g. `call/greeting/twi/greeting.mp3` and `greeting-8k.wav`). `php artisan db:seed` builds version 1 from it, publishes it and opens a draft. Recordings uploaded later live in the database and storage, not in the bundle.

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
| `app/Services/Conversation/ConversationEngine.php` | Runs the IVR flow for a call: steps, branches, answers, replies (channel-agnostic) |
| `app/Services/Conversation/IntentResolver.php`, `NumberParser.php` | Matching answers to menu options and languages, and number parsing per language |
| `app/Services/Flows/` | Version 1 of the flow, editing, publishing, recording storage, and the audio-language fallback |
| `app/Models/CallFlow.php`, `FlowStep.php`, `AudioClip.php`, `ClipRecording.php` | Flow versions, steps, clips and their recordings |
| `app/Livewire/CallFlows/Builder.php` | The visual IVR builder |
| `config/conversation.php` | Version 1's menu words, loan limits and clip groups (languages and their words are in the `languages` table) |
| `app/Models/Language.php`, `app/Livewire/Languages/` | Languages and the Languages page |
| `app/Services/Khaya/` | Khaya ASR client and quota guard |
| `app/Services/Abena/AbenaTextToSpeech.php` | Abena AI text-to-speech client |
| `app/Services/Audio/` | ffmpeg conversion |
| `app/Http/Controllers/CallController.php` | JSON endpoints used by the browser phone |
| `resources/js/call-console.js` | Browser phone: voice detection, barge-in, WAV encoding, keypad, Chrome speech recognition |
| `app/Livewire/` | Call console, customers, IVR flow and languages pages |

## Status and next steps

- [x] Browser proof of concept: Twi, Ewe, English (testing), sign-up, menu actions, keypad fallback, Khaya quota guard
- [ ] Native-speaker review of the Twi and Ewe prompt wording and keywords (current text is a draft)
- [x] Visual IVR builder with draft, test and publish
- [ ] Real recordings for Twi and Ewe, uploaded in the builder
- [ ] Amounts and dates spoken in Twi and Ewe from number clips
- [ ] Our own voice engine (FreeSWITCH → SIP provider), with full speech barge-in
- [ ] Stage 2: transfer to a human agent
- [ ] Fante and Ga
