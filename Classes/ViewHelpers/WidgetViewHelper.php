<?php

declare(strict_types=1);

namespace Hostily\HostilyCaptcha\ViewHelpers;

use Hostily\HostilyCaptcha\Service\Settings;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * <hc:widget /> gibt das Widget des Dienstes aus: das Element <hostily-captcha> und (einmal je Seite) das Skript.
 * Ohne Site-Key und Secret kommt nichts (das Formular bleibt ungeschuetzt, aber benutzbar).
 */
final class WidgetViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    private static bool $scriptDone = false;

    public function render(): string
    {
        $settingsService = GeneralUtility::makeInstance(Settings::class);
        $s = $settingsService->get();
        if ($s['siteKey'] === '' || $s['secret'] === '' || $s['serviceUrl'] === '') {
            return '';
        }
        $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '<hostily-captcha data-site-key="' . $h($s['siteKey']) . '" data-api="' . $h($s['serviceUrl']) . '/api/"'
            . ($s['mode'] !== 'portal' ? ' data-modus="' . $h($s['mode']) . '"' : '')
            . ($s['mode'] === 'halten' && $s['holdTime'] !== '' ? ' data-haltezeit="' . $h($s['holdTime']) . '"' : '')
            . ($s['language'] !== '' ? ' data-sprache="' . $h($s['language']) . '"' : '')
            . '></hostily-captcha>';
        if (!self::$scriptDone) {
            self::$scriptDone = true;
            $out .= '<script src="' . $h($s['serviceUrl']) . '/widget/hostily-captcha.js" defer></script>';
        }

        return $out;
    }
}
