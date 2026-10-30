<?php

declare(strict_types=1);

namespace Hostily\HostilyCaptcha\Validation;

use Hostily\HostilyCaptcha\Service\Verifier;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Extbase\Validation\Validator\AbstractValidator;

/**
 * Validator fuer das Formularelement "hostily Captcha" (EXT:form). Er liest das vom Widget angelegte Feld
 * "hostily-captcha-token" aus der Anfrage (es steht ausserhalb des Formular-Namensraums) und prueft es beim Dienst.
 */
final class HostilyCaptchaValidator extends AbstractValidator
{
    /** Auch ohne Wert pruefen: ein fehlendes Token ist ein Fehler, nicht "leer ist erlaubt". */
    protected $acceptsEmptyValues = false;

    protected function isValid(mixed $value): void
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $verifier = GeneralUtility::makeInstance(Verifier::class);
        $fields = [];
        $parsed = $request?->getParsedBody();
        if (is_array($parsed)) {
            // EXT:form sendet tx_form_formframework[<Formular-ID>][<Feld>]
            foreach ((array)($parsed['tx_form_formframework'] ?? []) as $form) {
                if (is_array($form)) {
                    $fields = Verifier::contentFields($form);
                    break;
                }
            }
        }
        $result = $verifier->verify($request, $fields);
        if ($result['status'] === 'ok') {
            return;
        }
        if ($result['status'] === 'error') {
            $this->addError($this->label('error.unavailable'), 1761600003);

            return;
        }
        $missing = array_intersect($result['reasons'], ['token_fehlt', 'token_abgelaufen']) !== [] || Verifier::tokenFrom($request) === '';
        $this->addError($this->label($missing ? 'error.expired' : 'error.rejected'), $missing ? 1761600002 : 1761600001);
    }

    private function label(string $key): string
    {
        return LocalizationUtility::translate('LLL:EXT:hostily_captcha/Resources/Private/Language/locallang.xlf:' . $key)
            ?? $key;
    }
}
