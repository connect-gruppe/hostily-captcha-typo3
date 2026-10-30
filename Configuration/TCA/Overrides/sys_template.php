<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addStaticFile(
    'hostily_captcha',
    'Configuration/TypoScript/Powermail',
    'hostily Captcha for powermail'
);
