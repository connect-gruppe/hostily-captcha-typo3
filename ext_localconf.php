<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// EXT:form: Formularelement "hostily Captcha" und Validator registrieren.
// TYPO3 14.2+ findet Configuration/Form/<Name>/config.yaml selbst; davor wird die Datei ueber TypoScript eingehaengt.
if (ExtensionManagementUtility::isLoaded('form')
    && version_compare((new Typo3Version())->getVersion(), '14.2', '<')
) {
    ExtensionManagementUtility::addTypoScriptSetup(
        'module.tx_form.settings.yamlConfigurations.1761600 = EXT:hostily_captcha/Configuration/Form/HostilyCaptcha/config.yaml' . LF
        . 'plugin.tx_form.settings.yamlConfigurations.1761600 = EXT:hostily_captcha/Configuration/Form/HostilyCaptcha/config.yaml'
    );
}
