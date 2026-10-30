<?php

declare(strict_types=1);

namespace Hostily\HostilyCaptcha\Powermail;

use Hostily\HostilyCaptcha\Service\Verifier;
use In2code\Powermail\Domain\Validator\SpamShield\AbstractMethod;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Spamschutz-Methode fuer powermail 12/13: prueft das Token des Widgets beim Dienst. Sie greift nur bei Formularen,
 * die ein Feld vom Typ "hostily Captcha" enthalten (sonst waere ein Formular ohne Widget immer gesperrt).
 *
 * Mit Bestaetigungsseite (confirmation) wird das Token einmal geprueft: das Ergebnis gilt in der Sitzung fuer das
 * anschliessende Absenden (create), denn ein Token gilt beim Dienst nur einmal.
 */
class HostilyCaptchaMethod extends AbstractMethod
{
    private const SESSION_KEY = 'hostily_captcha_powermail';
    private const SESSION_SECONDS = 1800;

    public function spamCheck(): bool
    {
        if (!$this->formHasField()) {
            return false;
        }
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $formUid = (int)$this->mail->getForm()?->getUid();
        $action = $this->actionOf($request);

        // Absenden nach der Bestaetigungsseite: die dort bestandene Pruefung gilt einmal
        if (in_array($action, ['create', 'checkCreate'], true) && Verifier::tokenFrom($request) === '' && $this->consumeSession($request, $formUid)) {
            return false;
        }

        $fields = Verifier::contentFields($this->fieldValues());
        $result = GeneralUtility::makeInstance(Verifier::class)->verify($request, $fields);
        if ($result['status'] === 'ok') {
            if (in_array($action, ['confirmation', 'checkConfirmation'], true)) {
                $this->rememberInSession($request, $formUid);
            }

            return false;
        }
        $this->tellVisitor($result, $request);

        return true;
    }

    /**
     * powermail zeigt bei erkanntem Spam nur das leere Formular: die Meldung kommt als Hinweis ueber dem Formular
     * (Warteschlange des powermail-Plugins, in der Sitzung gespeichert, weil powermail danach umleitet).
     *
     * @param array{status: string, reasons: array<int, string>} $result
     */
    private function tellVisitor(array $result, ?ServerRequestInterface $request): void
    {
        $key = match (true) {
            $result['status'] === 'error' => 'error.unavailable',
            array_intersect($result['reasons'], ['token_fehlt']) !== [] || Verifier::tokenFrom($request) === '' => 'error.expired',
            default => 'error.rejected',
        };
        if (array_intersect($result['reasons'], ['token_abgelaufen']) !== []) {
            $key = 'error.expired';
        }
        $text = LocalizationUtility::translate('LLL:EXT:hostily_captcha/Resources/Private/Language/locallang.xlf:' . $key) ?? $key;
        GeneralUtility::makeInstance(FlashMessageService::class)
            ->getMessageQueueByIdentifier('extbase.flashmessages.tx_powermail_pi1')
            ->enqueue(new FlashMessage($text, '', ContextualFeedbackSeverity::ERROR, true));
    }

    private function formHasField(): bool
    {
        foreach ($this->mail->getAnswers() as $answer) {
            if ($answer->getField() !== null && $answer->getField()->getType() === 'hostilycaptcha') {
                return true;
            }
        }
        // Antworten fehlen, wenn das Feld nicht abgeschickt wurde (Widget aus dem DOM entfernt): Formularfelder ansehen
        $form = $this->mail->getForm();
        foreach ($form?->getPages() ?? [] as $page) {
            foreach ($page->getFields() as $field) {
                if ($field->getType() === 'hostilycaptcha') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldValues(): array
    {
        $values = [];
        foreach ($this->mail->getAnswers() as $answer) {
            $field = $answer->getField();
            if ($field === null || in_array($field->getType(), ['hostilycaptcha', 'password', 'captcha', 'hidden'], true)) {
                continue;
            }
            $values[$field->getMarker()] = $answer->getValue();
        }

        return $values;
    }

    private function actionOf(?ServerRequestInterface $request): string
    {
        $body = $request?->getParsedBody();
        $query = $request?->getQueryParams() ?? [];
        $action = (is_array($body) ? ($body['tx_powermail_pi1']['action'] ?? null) : null) ?? ($query['tx_powermail_pi1']['action'] ?? '');

        return is_string($action) ? $action : '';
    }

    private function frontendUser(?ServerRequestInterface $request): ?object
    {
        // TYPO3 13/14: Attribut der Anfrage; TYPO3 12: auch ueber das TSFE
        $user = $request?->getAttribute('frontend.user');

        return is_object($user) ? $user : ($GLOBALS['TSFE']->fe_user ?? null);
    }

    private function rememberInSession(?ServerRequestInterface $request, int $formUid): void
    {
        $user = $this->frontendUser($request);
        if (is_object($user) && method_exists($user, 'setKey')) {
            $user->setKey('ses', self::SESSION_KEY, [$formUid => time() + self::SESSION_SECONDS]);
            $user->storeSessionData();
        }
    }

    private function consumeSession(?ServerRequestInterface $request, int $formUid): bool
    {
        $user = $this->frontendUser($request);
        if (!is_object($user) || !method_exists($user, 'getKey')) {
            return false;
        }
        $data = $user->getKey('ses', self::SESSION_KEY);
        $valid = is_array($data) && (int)($data[$formUid] ?? 0) > time();
        if ($valid) {
            $user->setKey('ses', self::SESSION_KEY, null);
            $user->storeSessionData();
        }

        return $valid;
    }
}
