<?php

declare(strict_types=1);

namespace Hostily\HostilyCaptcha\Domain\Model\FormElements;

use TYPO3\CMS\Form\Domain\Model\FormElements\GenericFormElement;

/**
 * Formularelement "hostily Captcha": haengt sich beim Aufbau selbst den Validator an, damit
 * das Element im Form-Editor ohne weitere Einstellung schuetzt.
 */
class HostilyCaptchaElement extends GenericFormElement
{
    public function initializeFormElement(): void
    {
        parent::initializeFormElement();
        $this->createValidator('HostilyCaptcha');
    }
}
