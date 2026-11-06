<?php

declare(strict_types=1);

namespace Hostily\HostilyCaptcha\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Einstellungen von hostily Captcha: globale Extension-Konfiguration, je Site ueberschreibbar
 * (Site-Einstellungen "hostilyCaptcha.siteKey" usw.; leere Werte lassen die globale Einstellung stehen).
 */
final class Settings
{
    public const DEFAULT_URL = 'https://captcha.hostily.de';

    private const KEYS = ['siteKey', 'secret', 'serviceUrl', 'mode', 'holdTime', 'language', 'onError'];

    /** Erlaubte Modi; "portal" = Attribut weglassen, der Modus kommt aus dem Kundenportal. */
    public const MODES = ['portal', 'sichtbar', 'unsichtbar', 'halten', 'schieben'];

    /**
     * @return array{siteKey: string, secret: string, serviceUrl: string, mode: string, holdTime: string, language: string, onError: string}
     */
    public function get(?ServerRequestInterface $request = null): array
    {
        $request ??= $GLOBALS['TYPO3_REQUEST'] ?? null;
        $values = array_fill_keys(self::KEYS, '');
        try {
            $global = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hostily_captcha');
        } catch (\Throwable) {
            $global = [];
        }
        foreach (self::KEYS as $key) {
            $values[$key] = is_array($global) && is_scalar($global[$key] ?? null) ? trim((string)$global[$key]) : '';
        }
        $site = $request instanceof ServerRequestInterface ? $request->getAttribute('site') : null;
        if ($site instanceof Site) {
            foreach (self::KEYS as $key) {
                $override = $this->siteSetting($site, $key);
                if ($override !== '') {
                    $values[$key] = $override;
                }
            }
        }

        $url = rtrim($values['serviceUrl'], '/');
        // Nur http(s); eine ungueltige Adresse gilt als nicht konfiguriert
        $values['serviceUrl'] = $url === '' ? self::DEFAULT_URL : (preg_match('#^https?://[^\s/]+#i', $url) === 1 ? $url : '');
        // Unbekannter oder leerer (alter) Wert: sichtbar wie bisher
        $values['mode'] = in_array($values['mode'], self::MODES, true) ? $values['mode'] : 'sichtbar';
        // Haltezeit in ms, nur fuer "halten"; ausserhalb 800-3000 oder leer: Widget-Vorgabe
        $values['holdTime'] = ctype_digit($values['holdTime']) ? (string)max(800, min(3000, (int)$values['holdTime'])) : '';
        $values['onError'] = $values['onError'] === 'block' ? 'block' : 'allow';
        if ($values['language'] === 'auto' || $values['language'] === '') {
            $values['language'] = $this->languageOf($request);
        } elseif (!in_array($values['language'], ['de', 'en'], true)) {
            $values['language'] = '';
        }

        return $values;
    }

    public function isConfigured(?ServerRequestInterface $request = null): bool
    {
        $s = $this->get($request);

        return $s['siteKey'] !== '' && $s['secret'] !== '' && $s['serviceUrl'] !== '';
    }

    private function siteSetting(Site $site, string $key): string
    {
        $name = 'hostilyCaptcha.' . $key;
        $value = null;
        if (method_exists($site, 'getSettings')) {
            $settings = $site->getSettings();
            if (is_object($settings) && method_exists($settings, 'has') && $settings->has($name)) {
                $value = $settings->get($name);
            } elseif (is_object($settings) && method_exists($settings, 'getAll')) {
                $value = $settings->getAll()['hostilyCaptcha'][$key] ?? null;
            }
        }
        if ($value === null) {
            // TYPO3 12: Einstellungen stehen in der Site-Konfiguration
            $value = $site->getConfiguration()['settings']['hostilyCaptcha'][$key] ?? null;
        }

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * Sprache der Seite als "de" oder "en"; sonst leer (das Widget entscheidet selbst).
     */
    private function languageOf(?ServerRequestInterface $request): string
    {
        $language = $request?->getAttribute('language');
        if (!is_object($language)) {
            return '';
        }
        $code = '';
        if (method_exists($language, 'getLocale')) {
            $locale = $language->getLocale();
            if (is_object($locale) && method_exists($locale, 'getLanguageCode')) {
                $code = (string)$locale->getLanguageCode();
            }
        }
        if ($code === '' && method_exists($language, 'getTwoLetterIsoCode')) {
            $code = (string)$language->getTwoLetterIsoCode();
        }
        $code = strtolower($code);

        return in_array($code, ['de', 'en'], true) ? $code : '';
    }
}
