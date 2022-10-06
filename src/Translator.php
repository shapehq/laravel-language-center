<?php

namespace Novasa\LaravelLanguageCenter;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator as LaravelTranslator;

class Translator extends LaravelTranslator
{
    protected $languages = [];
    protected $strings = [];
    protected $languagesLoaded = false;
    protected $stringsLoaded = [];

    /**
     * Create a new translator instance.
     *
     * @param \Illuminate\Translation\FileLoader $loader
     * @param string                             $locale
     *
     * @return void
     */
    public function __construct(FileLoader $loader, $locale)
    {
        $this->loader = $loader;
        $this->locale = $locale;

        // Load languages from API
        $this->loadLanguages();
    }

    /**
     * Get the translation for the given key.
     *
     * @param string      $key
     * @param array       $replace
     * @param string|null $locale
     * @param bool        $fallback
     *
     * @return string|array|null
     */
    public function get($data, array $replace = [], $locale = null, $fallback = true, $created = false)
    {
        if (!$this->enabled()) {
            if (is_array($data)) {
                return array_get($data, 'string', $data['key']);
            }

            return $data;
        }

        // Make support for array data
        if (is_array($data)) {
            $key = $data['key'];

            if (isset($data['string'])) {
                $string = $data['string'];
            }

            if (isset($data['platform'])) {
                $platform = $data['platform'];
            }

            if (isset($data['comment'])) {
                $comment = $data['comment'];
            }
        } else {
            $key = $data;
        }

        $key = trim(str_replace([' '], ['_'], strtolower(trim($key))));

        // Ignore items that are not in the correct format, example: "__('Not found')".
        // Correct format for that would be "__('errors.not_found')".
        if (strpos($key, '.') === false) {
            return parent::get(is_string($data) ? $data : $key, $replace, $locale, $fallback);
        }

        if (!isset($string)) {
            $string = $key;
        }

        if (!isset($platform)) {
            $platform = $this->getDefaultPlatform();
        }

        if (!isset($comment)) {
            $comment = null;
        }

        list($namespace, $group, $item) = $this->parseKey($key);

        // Here we will get the locale that should be used for the language line. If one
        // was not passed, we will use the default locales which was given to us when
        // the translator was instantiated. Then, we can load the lines and return.

        $locales = $fallback ? $this->localeArray($locale)
                             : [$locale ?: $this->locale];

        foreach ($locales as $locale) {
            if (!is_null($line = $this->getLine(
                $namespace, $group, $locale, $item, $replace
            ))) {
                break;
            }
        }

        // If the line doesn't exist, we will return back the key which was requested as
        // that will be quick to spot in the UI if language keys are wrong or missing
        // from the application's language files. Otherwise we can return the line.

        if (!isset($line)) {
            if (!$created) {
                $this->createString($key, $string, $platform, $comment);

                return $this->get($data, $replace, $locale, $fallback, true);
            }

            return $key;
        }

        return $line;
    }

    public function setLocale($locale)
    {
        config()->set('app.locale', $locale);
        config()->set('fallback_locale', $locale);

        $this->locale = $locale;
    }

    public function loadLanguages()
    {
        if ($this->languagesLoaded || !$this->enabled()) {
            return;
        }

        $this->languagesLoaded = true;

        $timestamp = Carbon::now()->subSeconds($this->getUpdateAfter())->timestamp;
        $lastUpdated = Cache::get('languagecenter.timestamp', 0);

        if ($timestamp > $lastUpdated && !is_null($timestamp)) {
            $this->updateLanguages();
        }

        $languages = Cache::get('languagecenter.languages', []);

        foreach ($languages as $language) {
            $this->languages[] = $language->codename;

            if ($language->is_fallback) {
                $this->setLocale($language->codename);
            }
        }
    }

    public function loadStrings($locale, $platform = null, $check = null)
    {
        if (isset($this->stringsLoaded[$locale]) || !$this->enabled()) {
            return;
        }

        $this->stringsLoaded[$locale] = true;

        // Load languages from API
        $this->loadLanguages();

        if ($platform == null) {
            $platform = $this->getDefaultPlatform();
        }

        if (is_null($check)) {
            $check = !is_null($this->getUpdateAfter());
        }

        if ($check) {
            $languages = Cache::get('languagecenter.languages', []);

            foreach ($languages as $language) {
                $timestamp = Cache::get('languagecenter.language.'.$language->codename.'.timestamp', 0);

                if ($language->timestamp > $timestamp) {
                    $this->updateStrings($locale, $platform);
                }
            }
        }

        $this->strings = Cache::get('languagecenter.strings', []);
    }

    public function updateLanguages($catch = true)
    {
        if (!$this->enabled()) {
            return;
        }

        $timestamp = Carbon::now()->timestamp;

        $client = $this->getClient();

        try {
            $res = $client->request('GET', $this->getApiUrl().'languages', [
                'auth'  => $this->getAuthentication(),
                'query' => [
                    'timestamp' => 'on',
                ],
            ]);

            if ($res->getStatusCode() != 200) {
                throw new ApiException("API returned status [{$res->getStatusCode()}].");
            }

            $languages = json_decode((string) $res->getBody());

            Cache::forever('languagecenter.languages', $languages);
            Cache::forever('languagecenter.timestamp', $timestamp);
        } catch (\Exception $exception) {
            if (!$catch) {
                throw $exception;
            }

            $languages = Cache::get('languagecenter.languages');

            if (is_null($languages)) {
                throw $exception; // Nothing to do, can not restore data, throw exception
            }
        }
    }

    public function updateStrings($locale, $platform = null, $catch = true)
    {
        if (!$this->enabled()) {
            return;
        }

        try {
            $timestamp = Carbon::now()->timestamp;

            if (is_null($platform)) {
                $platform = $this->getDefaultPlatform();
            }

            $client = $this->getClient();

            $res = $client->request('GET', $this->getApiUrl().'strings?platform='.$platform.'&language='.$locale, [
                'auth' => $this->getAuthentication(),
            ]);

            if ($res->getStatusCode() != 200) {
                throw new ApiException("API returned status [{$res->getStatusCode()}].");
            }

            $strings = json_decode((string) $res->getBody());

            if (!isset($this->strings[$locale])) {
                $this->strings[$locale] = [];
            }

            $this->strings[$locale][$platform] = [];

            foreach ($strings as $string) {
                $this->strings[$locale][$platform][$string->key] = $string->value;
                $this->strings[$string->language][$platform][$string->key] = $string->value;
            }

            Cache::forever('languagecenter.strings', $this->strings);
            Cache::forever('languagecenter.language.'.$locale.'.timestamp', $timestamp);
        } catch (\Exception $exception) {
            if (!$catch) {
                throw $exception;
            }

            // failed to update string - it's okay - we do it later
        }
    }

    public function createString($key, $string, $platform = null, $comment = null)
    {
        if (!$this->enabled()) {
            return;
        }

        if ($platform == null) {
            $platform = $this->getDefaultPlatform();
        }

        $dotpos = strpos($key, '.');

        if (!($dotpos > 0)) {
            throw new ApiException('Missing [.] in string key.');
        }

        $category = str_replace(['_'], [' '], ucfirst(substr($key, 0, $dotpos)));
        $name = str_replace(['_'], [' '], ucfirst(substr($key, $dotpos + 1)));

        try {
            $client = $this->getClient();

            $res = $client->request('POST', $this->getApiUrl().'string', [
                'auth'        => $this->getAuthentication(),
                'form_params' => [
                    'platform' => $platform,
                    'category' => $category,
                    'key'      => $name,
                    'value'    => $string,
                    'comment'  => $comment,
                ],
            ]);

            if ($res->getStatusCode() != 200) {
                throw new ApiException("API returned status [{$res->getStatusCode()}].");
            }
        } catch (\Exception $exception) {
            // failed to create string, will just try later.
        }

        foreach ($this->strings as $locale => $platforms) {
            $this->strings[$locale][$platform][$key] = $string;
        }

        foreach ($this->languages as $language) {
            $this->loadStrings($language, $platform);
        }
    }

    public function getLanguages()
    {
        return $this->languages;
    }

    /**
     * Retrieve a language line out the loaded array.
     *
     * @param string $namespace
     * @param string $group
     * @param string $locale
     * @param string $item
     * @param array  $replace
     *
     * @return string|array|null
     */
    protected function getLine($namespace, $group, $locale, $item, array $replace, $platform = null)
    {
        $this->load($namespace, $group, $locale);

        if ($platform == null) {
            $platform = $this->getDefaultPlatform();
        }

        $this->loadStrings($locale, $platform);

        $key = implode('.', [$group, $item]);

        if (isset($this->strings[$locale]) && isset($this->strings[$locale][$platform]) && isset($this->strings[$locale][$platform][$key])) {
            return $this->makeReplacements($this->strings[$locale][$platform][$key], $replace);
        }

        $line = Arr::get($this->loaded[$namespace][$group][$locale], $item);

        if (is_string($line)) {
            return $this->makeReplacements($line, $replace);
        } elseif (is_array($line) && count($line) > 0) {
            return $line;
        }
    }

    protected function getClient()
    {
        return new Client();
    }

    protected function getApiUrl()
    {
        return config('languagecenter.url');
    }

    protected function getUsername()
    {
        return config('languagecenter.username');
    }

    protected function getPassword()
    {
        return config('languagecenter.password');
    }

    protected function getUpdateAfter()
    {
        return config('languagecenter.update_after', 60);
    }

    protected function getDefaultPlatform()
    {
        return config('languagecenter.platform', 'web');
    }

    protected function getAuthentication()
    {
        return [
            $this->getUsername(),
            $this->getPassword(),
        ];
    }

    protected function enabled()
    {
        return config('languagecenter.enabled', true);
    }
}
