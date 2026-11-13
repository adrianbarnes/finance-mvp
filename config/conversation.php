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
| Languages, with their yes/no words, number words and the words that pick
| them on the language choice, are managed on the Languages page.
|
*/

$englishPhrases = [
    'check_balance' => ['balance', 'my account', 'how much do i have'],
    'apply_loan' => ['loan', 'borrow', 'apply'],
    'application_status' => ['status', 'application', 'approved'],
    'repayment_due' => ['repayment', 'repay', 'pay back', 'due', 'installment'],
    'goodbye' => ['bye', 'goodbye', 'thank you bye'],
    'repeat' => ['repeat', 'say again', 'again'],
    'speak_to_agent' => ['agent', 'representative', 'customer care', 'real person', 'human'],
];

return [

    /*
    | Groups for the clips of version 1 of the IVR flow, by the function
    | they serve. Keys not listed fall under "other".
    */
    'clip_groups' => [
        'call' => ['greeting', 'main_menu', 'anything_else', 'yes_no_hint', 'not_understood', 'speech_unavailable', 'too_many_attempts', 'goodbye'],
        'language' => ['choose_language'],
        'registration' => ['offer_registration', 'ask_name', 'confirm_name', 'confirm_name_question', 'registered'],
        'balance' => ['balance_is'],
        'loan_application' => ['ask_amount', 'amount_not_understood', 'amount_out_of_range', 'ask_term', 'term_invalid', 'confirm_application', 'confirm_question', 'application_submitted', 'application_cancelled'],
        'application_status' => ['application_status_intro', 'no_application', 'status_pending', 'status_under_review', 'status_approved', 'status_rejected', 'status_disbursed', 'status_closed'],
        'repayment' => ['repayment_due_is', 'repayment_due_on', 'no_repayment_due'],
        'agent' => ['agent_unavailable'],
    ],

    /*
    | Committed wording and audio that version 1 of the IVR flow starts
    | from: manifest.json plus audio under {group}/{key}/{language}/.
    */
    'default_flow_bundle_path' => database_path('seeders/data/voice-prompts'),

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
    | Words for each main-menu option of version 1 of the IVR flow, per
    | language. After that, options and their words are edited in the
    | IVR flow builder.
    */
    'lexicon' => [
        'twi' => [
            'check_balance' => [...$englishPhrases['check_balance'], 'sika dodoo', 'sika a ewo', 'me sika', 'akawnt', 'sika ahe na ewo'],
            'apply_loan' => [...$englishPhrases['apply_loan'], 'bosea', 'bo bosea', 'fem', 'pe bosea', 'mepe bosea'],
            'application_status' => [...$englishPhrases['application_status'], 'abisade', 'abisadee', 'eduru he', 'nkoso'],
            'repayment_due' => [...$englishPhrases['repayment_due'], 'tua', 'metua', 'wotua', 'tua bosea', 'ka a metua', 'bere ben', 'da ben'],
            'goodbye' => [...$englishPhrases['goodbye'], 'nante yie', 'medaase', 'meda wo ase', 'yebehyia'],
            'repeat' => [...$englishPhrases['repeat'], 'san ka', 'ka bio'],
            'speak_to_agent' => [...$englishPhrases['speak_to_agent'], 'me ne obi nkasa', 'me ne onipa nkasa', 'adwumayefo'],
        ],
        'ewe' => [
            'check_balance' => [...$englishPhrases['check_balance'], 'nye ga', 'ga nenie', 'ga ƒe agbɔsɔsɔ', 'akɔnta'],
            'apply_loan' => [...$englishPhrases['apply_loan'], 'fenyinyi', 'nyi fe', 'xɔ fe', 'medi fe'],
            'application_status' => [...$englishPhrases['application_status'], 'biabia', 'fenyinyi biabia', 'ɖe afi ka'],
            'repayment_due' => [...$englishPhrases['repayment_due'], 'xe fe', 'fexexe', 'maxe', 'nàxe'],
            'goodbye' => [...$englishPhrases['goodbye'], 'hede nyuie', 'akpe', 'mia do go'],
            'repeat' => [...$englishPhrases['repeat'], 'gagblɔe', 'gblɔe ake'],
            'speak_to_agent' => [...$englishPhrases['speak_to_agent'], 'ame', 'dɔwɔla'],
        ],
        'eng' => $englishPhrases,
    ],

];
