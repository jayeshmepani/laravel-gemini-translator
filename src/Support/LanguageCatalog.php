<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Support;

use Jayesh\LaravelGeminiTranslator\Utils\LocaleHelper;
use Locale;

final class LanguageCatalog
{
    /**
     * Short file codes (zh, pt, pa, …) map to the primary variant
     * when the catalog only lists regional/script tags.
     *
     * @var array<string, string>
     */
    private const array FALLBACKS = [
        'zh' => 'zh_CN',
        'pt' => 'pt_BR',
        'pa' => 'pa_GURU',
        'ms' => 'ms_LATN',
        'iu' => 'iu_LATN',
        'crh' => 'crh_LATN',
        'sat' => 'sat_LATN',
        'zgh' => 'zgh_LATN',
    ];

    /** @return array<string, string> Display name => locale code */
    public static function namesToCodes(): array
    {
        $codes = [];
        foreach (self::raw() as $name => $code) {
            $codes[$name] = LocaleHelper::canonicalize($code);
        }

        return $codes;
    }

    public static function displayName(string $code): string
    {
        $needle = self::normalize($code);
        foreach (self::namesToCodes() as $name => $mapped) {
            if (self::normalize($mapped) === $needle) {
                return $name;
            }
        }

        $base = explode('_', $needle)[0];
        if ($needle === $base && isset(self::FALLBACKS[$base])) {
            return self::displayName(self::FALLBACKS[$base]);
        }

        if (class_exists(Locale::class)) {
            $label = Locale::getDisplayLanguage(str_replace('_', '-', $code), 'en');
            if (is_string($label) && strcasecmp($label, $code) !== 0) {
                return $label;
            }
        }

        return strtoupper($code);
    }

    /** @return array<string, string> */
    private static function raw(): array
    {
        return [
            'Abkhaz' => 'ab',
            'Acehnese' => 'ace',
            'Acholi' => 'ach',
            'Afar' => 'aa',
            'Afrikaans' => 'af',
            'Albanian' => 'sq',
            'Alur' => 'alz',
            'Amharic' => 'am',
            'Arabic' => 'ar',
            'Armenian' => 'hy',
            'Assamese' => 'as',
            'Avar' => 'av',
            'Awadhi' => 'awa',
            'Aymara' => 'ay',
            'Azerbaijani' => 'az',
            'Balinese' => 'ban',
            'Baluchi' => 'bal',
            'Bambara' => 'bm',
            'Baoulé' => 'bci',
            'Bashkir' => 'ba',
            'Basque' => 'eu',
            'Batak Karo' => 'btx',
            'Batak Simalungun' => 'bts',
            'Batak Toba' => 'bbc',
            'Belarusian' => 'be',
            'Bemba' => 'bem',
            'Bengali' => 'bn',
            'Betawi' => 'bew',
            'Bhojpuri' => 'bho',
            'Bikol' => 'bik',
            'Bosnian' => 'bs',
            'Breton' => 'br',
            'Bulgarian' => 'bg',
            'Buryat' => 'bua',
            'Cantonese' => 'yue',
            'Catalan' => 'ca',
            'Cebuano' => 'ceb',
            'Chamorro' => 'ch',
            'Chechen' => 'ce',
            'Chichewa' => 'ny',
            'Chinese (Simplified)' => 'zh-CN',
            'Chinese (Traditional)' => 'zh-TW',
            'Chuukese' => 'chk',
            'Chuvash' => 'cv',
            'Corsican' => 'co',
            'Crimean Tatar (Cyrillic)' => 'crh-cyrl',
            'Crimean Tatar (Latin)' => 'crh-latn',
            'Croatian' => 'hr',
            'Czech' => 'cs',
            'Danish' => 'da',
            'Dari' => 'prs',
            'Dhivehi' => 'dv',
            'Dinka' => 'din',
            'Dogri' => 'doi',
            'Dombe' => 'dov',
            'Dutch' => 'nl',
            'Dyula' => 'dyu',
            'Dzongkha' => 'dz',
            'English' => 'en',
            'Esperanto' => 'eo',
            'Estonian' => 'et',
            'Ewe' => 'ee',
            'Faroese' => 'fo',
            'Fijian' => 'fj',
            'Filipino' => 'fil',
            'Finnish' => 'fi',
            'Fon' => 'fon',
            'French' => 'fr',
            'French (Canada)' => 'fr-CA',
            'Frisian' => 'fy',
            'Friulian' => 'fur',
            'Fulani' => 'ff',
            'Ga' => 'gaa',
            'Galician' => 'gl',
            'Georgian' => 'ka',
            'German' => 'de',
            'Greek' => 'el',
            'Guarani' => 'gn',
            'Gujarati' => 'gu',
            'Haitian Creole' => 'ht',
            'Hakha Chin' => 'cnh',
            'Hausa' => 'ha',
            'Hawaiian' => 'haw',
            'Hebrew' => 'he',
            'Hiligaynon' => 'hil',
            'Hindi' => 'hi',
            'Hmong' => 'hmn',
            'Hungarian' => 'hu',
            'Hunsrik' => 'hrx',
            'Iban' => 'iba',
            'Icelandic' => 'is',
            'Igbo' => 'ig',
            'Ilocano' => 'ilo',
            'Indonesian' => 'id',
            'Inuktut (Latin)' => 'iu-latn',
            'Inuktut (Syllabics)' => 'iu-syll',
            'Irish' => 'ga',
            'Italian' => 'it',
            'Jamaican Patois' => 'jam',
            'Japanese' => 'ja',
            'Javanese' => 'jv',
            'Jingpo' => 'kac',
            'Kalaallisut' => 'kl',
            'Kannada' => 'kn',
            'Kanuri' => 'kr',
            'Kapampangan' => 'pam',
            'Kazakh' => 'kk',
            'Khasi' => 'kha',
            'Khmer' => 'km',
            'Kiga' => 'cgg',
            'Kikongo' => 'kg',
            'Kinyarwanda' => 'rw',
            'Kituba' => 'mkw',
            'Kokborok' => 'trp',
            'Komi' => 'kv',
            'Konkani' => 'kok',
            'Korean' => 'ko',
            'Krio' => 'kri',
            'Kurdish (Kurmanji)' => 'ku',
            'Kurdish (Sorani)' => 'ckb',
            'Kyrgyz' => 'ky',
            'Lao' => 'lo',
            'Latgalian' => 'ltg',
            'Latin' => 'la',
            'Latvian' => 'lv',
            'Ligurian' => 'lij',
            'Limburgish' => 'li',
            'Lingala' => 'ln',
            'Lithuanian' => 'lt',
            'Lombard' => 'lmo',
            'Luganda' => 'lg',
            'Luo' => 'luo',
            'Luxembourgish' => 'lb',
            'Macedonian' => 'mk',
            'Madurese' => 'mad',
            'Maithili' => 'mai',
            'Makassar' => 'mak',
            'Malagasy' => 'mg',
            'Malay (Latin)' => 'ms-latn',
            'Malay (Jawi)' => 'ms-arab',
            'Malayalam' => 'ml',
            'Maltese' => 'mt',
            'Mam' => 'mam',
            'Manx' => 'gv',
            'Maori' => 'mi',
            'Marathi' => 'mr',
            'Marshallese' => 'mh',
            'Marwadi' => 'mwr',
            'Mauritian Creole' => 'mfe',
            'Meadow Mari' => 'mhr',
            'Meiteilon (Manipuri)' => 'mni',
            'Minang' => 'min',
            'Mizo' => 'lus',
            'Mongolian' => 'mn',
            'Myanmar (Burmese)' => 'my',
            'Nahuatl (Eastern Huasteca)' => 'nhe',
            'Ndau' => 'ndc',
            'Ndebele (South)' => 'nr',
            'Nepalbhasa (Newari)' => 'new',
            'Nepali' => 'ne',
            'NKo' => 'nqo',
            'Norwegian' => 'no',
            'Nuer' => 'nus',
            'Occitan' => 'oc',
            'Odia (Oriya)' => 'or',
            'Oromo' => 'om',
            'Ossetian' => 'os',
            'Pangasinan' => 'pag',
            'Papiamento' => 'pap',
            'Pashto' => 'ps',
            'Persian' => 'fa',
            'Polish' => 'pl',
            'Portuguese (Brazil)' => 'pt-BR',
            'Portuguese (Portugal)' => 'pt-PT',
            'Punjabi (Gurmukhi)' => 'pa-guru',
            'Punjabi (Shahmukhi)' => 'pa-arab',
            'Quechua' => 'qu',
            'Qʼeqchiʼ' => 'quc',
            'Romani' => 'rom',
            'Romanian' => 'ro',
            'Rundi' => 'rn',
            'Russian' => 'ru',
            'Sami (North)' => 'se',
            'Samoan' => 'sm',
            'Sango' => 'sg',
            'Sanskrit' => 'sa',
            'Santali (Latin)' => 'sat-latn',
            'Santali (Ol Chiki)' => 'sat-olck',
            'Scots Gaelic' => 'gd',
            'Sepedi' => 'nso',
            'Serbian' => 'sr',
            'Sesotho' => 'st',
            'Seychellois Creole' => 'crs',
            'Shan' => 'shn',
            'Shona' => 'sn',
            'Sicilian' => 'scn',
            'Silesian' => 'szl',
            'Sindhi' => 'sd',
            'Sinhala' => 'si',
            'Slovak' => 'sk',
            'Slovenian' => 'sl',
            'Somali' => 'so',
            'Spanish' => 'es',
            'Sundanese' => 'su',
            'Susu' => 'sus',
            'Swahili' => 'sw',
            'Swati' => 'ss',
            'Swedish' => 'sv',
            'Tahitian' => 'ty',
            'Tajik' => 'tg',
            'Tamazight (Latin)' => 'zgh-latn',
            'Tamazight (Tifinagh)' => 'zgh-tfng',
            'Tamil' => 'ta',
            'Tatar' => 'tt',
            'Telugu' => 'te',
            'Tetum' => 'tet',
            'Thai' => 'th',
            'Tibetan' => 'bo',
            'Tigrinya' => 'ti',
            'Tiv' => 'tiv',
            'Tok Pisin' => 'tpi',
            'Tongan' => 'to',
            'Tshiluba' => 'lua',
            'Tsonga' => 'ts',
            'Tswana' => 'tn',
            'Tulu' => 'tcy',
            'Tumbuka' => 'tum',
            'Turkish' => 'tr',
            'Turkmen' => 'tk',
            'Tuvan' => 'tyv',
            'Twi' => 'tw',
            'Udmurt' => 'udm',
            'Ukrainian' => 'uk',
            'Urdu' => 'ur',
            'Uyghur' => 'ug',
            'Uzbek' => 'uz',
            'Venda' => 've',
            'Venetian' => 'vec',
            'Vietnamese' => 'vi',
            'Waray' => 'war',
            'Welsh' => 'cy',
            'Wolof' => 'wo',
            'Xhosa' => 'xh',
            'Yakut' => 'sah',
            'Yiddish' => 'yi',
            'Yoruba' => 'yo',
            'Yucatec Maya' => 'yua',
            'Zapotec' => 'zap',
            'Zulu' => 'zu',
        ];
    }

    private static function normalize(string $code): string
    {
        return strtolower(str_replace('-', '_', LocaleHelper::canonicalize($code)));
    }
}
