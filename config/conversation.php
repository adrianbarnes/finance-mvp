<?php

/*
|--------------------------------------------------------------------------
| Voice conversation settings
|--------------------------------------------------------------------------
|
| Lexicon phrases are matched against a normalised transcript: lowercase,
| punctuation removed, and the Twi letters ɛ / ɔ folded to e / o so that
| either spelling from the ASR model matches. A phrase prefixed with "="
| only matches when it is the whole utterance (e.g. English "no", which
| is also the Twi definite article "the").
|
| English phrases are included in every language because callers often
| code-switch, and amounts may be spoken in English for now.
|
*/

$englishPhrases = [
    'check_balance' => ['balance', 'my account', 'how much do i have'],
    'apply_loan' => ['loan', 'borrow', 'apply'],
    'application_status' => ['status', 'application', 'approved'],
    'repayment_due' => ['repayment', 'repay', 'pay back', 'due', 'installment'],
    'yes' => ['yes', 'yeah', 'okay', 'ok', 'sure', 'correct'],
    'no' => ['=no', 'no thank you', 'no thanks', 'nope', 'cancel'],
    'goodbye' => ['bye', 'goodbye', 'thank you bye'],
    'repeat' => ['repeat', 'say again', 'again'],
    'speak_to_agent' => ['agent', 'representative', 'customer care', 'real person', 'human'],
];

return [

    /*
    | Languages offered on the language prompt, in this order. The keypad
    | number for each language is its position here (1, 2, 3...).
    */
    'enabled_languages' => ['twi', 'ewe', 'eng'],

    /*
    | Languages recognised by the browser phone (Web Speech API) instead of
    | Khaya. English is used for testing and verification only.
    */
    'client_recognized_languages' => ['eng'],

    'default_language' => 'twi',

    /*
    | Prompts grouped by the function they serve. Audio is stored as
    | voice-prompts/{group}/{step}/{language}/, and the Voice prompts page
    | lists prompts in these groups. Keys not listed fall under "other".
    */
    'prompt_groups' => [
        'call' => ['greeting', 'main_menu', 'anything_else', 'yes_no_hint', 'not_understood', 'speech_unavailable', 'goodbye'],
        'language' => ['choose_language'],
        'registration' => ['offer_registration', 'ask_name', 'confirm_name', 'confirm_name_question', 'registered'],
        'balance' => ['balance_is'],
        'loan_application' => ['ask_amount', 'amount_not_understood', 'amount_out_of_range', 'ask_term', 'term_invalid', 'confirm_application', 'confirm_question', 'application_submitted', 'application_cancelled'],
        'application_status' => ['application_status_intro', 'no_application', 'status_pending', 'status_under_review', 'status_approved', 'status_rejected', 'status_disbursed', 'status_closed'],
        'repayment' => ['repayment_due_is', 'repayment_due_on', 'no_repayment_due'],
        'agent' => ['agent_unavailable'],
    ],

    /*
    | Committed copy of every prompt's wording and audio. Export it with
    | `php artisan voice-prompts:export`; VoicePromptSeeder loads it.
    */
    'voice_prompt_bundle_path' => database_path('seeders/data/voice-prompts'),

    /*
    | Words that accept a language on the language-choice prompt: the
    | language's own "yes" and its name. Matched across all enabled
    | languages from a single transcription.
    */
    'language_affirmatives' => [
        'twi' => ['aane', 'yoo', 'twi', 'asante', 'akan'],
        'fat' => ['nyew', 'fante', 'mfantse'],
        'gaa' => ['hee', 'hɛɛ', 'ga'],
        'ewe' => ['ẽ', 'ɛ̃', 'ee', 'ewe', 'eʋegbe', 'evegbe'],
        'eng' => ['yes', 'yeah', 'english'],
    ],

    /*
    | How many more times a question is replayed when the caller gives no
    | input, before the assistant thanks them and ends the call.
    */
    'max_no_input_replays' => 2,

    /*
    | Seconds of silence after a prompt finishes before the client reports
    | "no input" (used by the browser phone; the IVR uses its own timeout).
    */
    'no_input_timeout_seconds' => 6,

    'loan' => [
        'min_amount_cedis' => (int) env('LOAN_MIN_AMOUNT_CEDIS', 100),
        'max_amount_cedis' => (int) env('LOAN_MAX_AMOUNT_CEDIS', 10000),
        'max_term_months' => (int) env('LOAN_MAX_TERM_MONTHS', 12),
    ],

    /*
    | Keypad (DTMF) shortcuts available in every language.
    */
    'keypad' => [
        'main_menu' => [
            '1' => 'check_balance',
            '2' => 'apply_loan',
            '3' => 'application_status',
            '4' => 'repayment_due',
            '8' => 'speak_to_agent',
            '9' => 'repeat',
            '0' => 'goodbye',
        ],
        'confirmation' => [
            '1' => 'yes',
            '2' => 'no',
        ],
    ],

    'lexicon' => [
        'twi' => [
            'check_balance' => [...$englishPhrases['check_balance'], 'sika dodoo', 'sika a ewo', 'me sika', 'akawnt', 'sika ahe na ewo'],
            'apply_loan' => [...$englishPhrases['apply_loan'], 'bosea', 'bo bosea', 'fem', 'pe bosea', 'mepe bosea'],
            'application_status' => [...$englishPhrases['application_status'], 'abisade', 'abisadee', 'eduru he', 'nkoso'],
            'repayment_due' => [...$englishPhrases['repayment_due'], 'tua', 'metua', 'wotua', 'tua bosea', 'ka a metua'],
            'yes' => [...$englishPhrases['yes'], 'aane', 'yoo', 'ampa', 'nokware', 'eye'],
            'no' => [...$englishPhrases['no'], 'daabi', 'dabi', 'mempe'],
            'goodbye' => [...$englishPhrases['goodbye'], 'nante yie', 'medaase', 'meda wo ase', 'yebehyia'],
            'repeat' => [...$englishPhrases['repeat'], 'san ka', 'ka bio'],
            'speak_to_agent' => [...$englishPhrases['speak_to_agent'], 'me ne obi nkasa', 'me ne onipa nkasa', 'adwumayefo'],
        ],
        'ewe' => [
            'check_balance' => [...$englishPhrases['check_balance'], 'nye ga', 'ga nenie', 'ga ƒe agbɔsɔsɔ', 'akɔnta'],
            'apply_loan' => [...$englishPhrases['apply_loan'], 'fenyinyi', 'nyi fe', 'xɔ fe', 'medi fe'],
            'application_status' => [...$englishPhrases['application_status'], 'biabia', 'fenyinyi biabia', 'ɖe afi ka'],
            'repayment_due' => [...$englishPhrases['repayment_due'], 'xe fe', 'fexexe', 'maxe', 'nàxe'],
            'yes' => [...$englishPhrases['yes'], 'ẽ', 'ɛ̃', 'ee', 'yoo', 'nyateƒe'],
            'no' => [...$englishPhrases['no'], 'ao', 'gbeo', 'nyemedi o'],
            'goodbye' => [...$englishPhrases['goodbye'], 'hede nyuie', 'akpe', 'mia do go'],
            'repeat' => [...$englishPhrases['repeat'], 'gagblɔe', 'gblɔe ake'],
            'speak_to_agent' => [...$englishPhrases['speak_to_agent'], 'ame', 'dɔwɔla'],
        ],
        'eng' => $englishPhrases,
    ],

    /*
    | Spoken number words per language, used for loan terms (months) and
    | small amounts. English number words are always understood.
    */
    'number_words' => [
        'twi' => [
            'baako' => 1, 'biako' => 1, 'koro' => 1,
            'mmienu' => 2, 'abien' => 2, 'abienu' => 2,
            'mmiensa' => 3, 'abiesa' => 3,
            'nnan' => 4, 'anan' => 4,
            'nnum' => 5, 'anum' => 5,
            'nsia' => 6, 'asia' => 6,
            'nson' => 7, 'ason' => 7,
            'nnwotwe' => 8, 'awotwe' => 8,
            'nkron' => 9, 'akron' => 9,
            'du' => 10,
            'dubaako' => 11, 'dubiako' => 11,
            'dummienu' => 12, 'dumienu' => 12,
        ],
        'ewe' => [
            'ɖeka' => 1, 'deka' => 1,
            'eve' => 2,
            'etɔ̃' => 3, 'etɔ' => 3, 'eto' => 3,
            'ene' => 4,
            'atɔ̃' => 5, 'atɔ' => 5, 'ato' => 5,
            'ade' => 6,
            'adre' => 7,
            'enyi' => 8,
            'asieke' => 9,
            'ewo' => 10,
            'wuiɖeke' => 11, 'wuideke' => 11,
            'wuieve' => 12,
        ],
    ],

];
