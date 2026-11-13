<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('name');
            $table->string('speech_recognition');
            $table->string('khaya_code', 8)->nullable();
            $table->json('selection_words');
            $table->json('yes_words');
            $table->json('no_words');
            $table->json('number_words');
            $table->unsignedInteger('position');
            $table->boolean('enabled')->default(true);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();

        DB::table('languages')->insert(array_map(fn (array $language): array => [
            ...$language,
            'selection_words' => json_encode($language['selection_words'], JSON_UNESCAPED_UNICODE),
            'yes_words' => json_encode($language['yes_words'], JSON_UNESCAPED_UNICODE),
            'no_words' => json_encode($language['no_words'], JSON_UNESCAPED_UNICODE),
            'number_words' => json_encode($language['number_words'], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->defaultLanguages()));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('languages');
    }

    /**
     * The languages the voice assistant started with. English yes/no words
     * are included in every language because callers often code-switch.
     *
     * @return list<array<string, mixed>>
     */
    private function defaultLanguages(): array
    {
        $englishYes = ['yes', 'yeah', 'okay', 'ok', 'sure', 'correct'];
        $englishNo = ['=no', 'no thank you', 'no thanks', 'nope', 'cancel'];

        return [
            [
                'code' => 'twi', 'name' => 'Twi', 'speech_recognition' => 'khaya', 'khaya_code' => 'twi',
                'selection_words' => ['aane', 'yoo', 'twi', 'asante', 'akan'],
                'yes_words' => [...$englishYes, 'aane', 'yoo', 'ampa', 'nokware', 'eye'],
                'no_words' => [...$englishNo, 'daabi', 'dabi', 'mempe'],
                'number_words' => [
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
                'position' => 1, 'enabled' => true, 'is_primary' => true,
            ],
            [
                'code' => 'ewe', 'name' => 'Ewe', 'speech_recognition' => 'khaya', 'khaya_code' => 'ewe',
                'selection_words' => ['ẽ', 'ɛ̃', 'ee', 'ewe', 'eʋegbe', 'evegbe'],
                'yes_words' => [...$englishYes, 'ẽ', 'ɛ̃', 'ee', 'yoo', 'nyateƒe'],
                'no_words' => [...$englishNo, 'ao', 'gbeo', 'nyemedi o'],
                'number_words' => [
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
                'position' => 2, 'enabled' => true, 'is_primary' => false,
            ],
            [
                'code' => 'eng', 'name' => 'English', 'speech_recognition' => 'browser', 'khaya_code' => null,
                'selection_words' => ['yes', 'yeah', 'english'],
                'yes_words' => $englishYes,
                'no_words' => $englishNo,
                'number_words' => [],
                'position' => 3, 'enabled' => true, 'is_primary' => false,
            ],
            [
                'code' => 'fat', 'name' => 'Fante', 'speech_recognition' => 'khaya', 'khaya_code' => 'fat',
                'selection_words' => ['nyew', 'fante', 'mfantse'],
                'yes_words' => [...$englishYes, 'nyew'],
                'no_words' => [...$englishNo, 'oho'],
                'number_words' => [],
                'position' => 4, 'enabled' => false, 'is_primary' => false,
            ],
            [
                'code' => 'gaa', 'name' => 'Ga', 'speech_recognition' => 'khaya', 'khaya_code' => 'gaa',
                'selection_words' => ['hee', 'hɛɛ', 'ga'],
                'yes_words' => [...$englishYes, 'hee', 'hɛɛ'],
                'no_words' => [...$englishNo, 'dabi'],
                'number_words' => [],
                'position' => 5, 'enabled' => false, 'is_primary' => false,
            ],
        ];
    }
};
