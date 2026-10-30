<?php

declare(strict_types=1);

namespace Hostily\HostilyCaptcha\Service;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Prueft ein Token beim Dienst (POST <Dienst>/api/v1/pruefen, Protokoll: dienst/docs/protokoll.md).
 *
 * Ergebnis: ['status' => 'ok'|'rejected'|'error', 'reasons' => string[]]. 'error' gibt es nur bei
 * "Bei Ausfall: blockieren"; bei "durchlassen" (Vorgabe) ist ein Ausfall oder ein Konfigurationsfehler 'ok'
 * mit dem Grund 'service' (und einem Eintrag im TYPO3-Log).
 */
final class Verifier implements LoggerAwareInterface, SingletonInterface
{
    use LoggerAwareTrait;

    public const VERSION = '0.1.1';
    public const TOKEN_FIELD = 'hostily-captcha-token';

    private const CONFIG_ERRORS = ['invalid-input-secret', 'missing-input-secret', 'bad-request'];

    /** @var array<string, array{status: string, reasons: array<int, string>}> Ergebnisse dieser Anfrage je Token */
    private array $verified = [];

    /**
     * @param array<string, string> $fields Inhaltsfelder (leer = keine Inhaltspruefung)
     *
     * @return array{status: string, reasons: array<int, string>}
     */
    public function verify(?ServerRequestInterface $request = null, array $fields = []): array
    {
        $request ??= $GLOBALS['TYPO3_REQUEST'] ?? null;
        $settings = GeneralUtility::makeInstance(Settings::class)->get($request);
        if ($settings['siteKey'] === '' || $settings['secret'] === '' || $settings['serviceUrl'] === '') {
            // ohne Konfiguration: nichts geschuetzt, nichts gesperrt
            return ['status' => 'ok', 'reasons' => ['unconfigured']];
        }
        $token = self::tokenFrom($request);
        // Ein Token gilt nur einmal, derselbe Aufruf kann aber mehrfach pruefen (mehrere Felder/Validatoren).
        if ($token !== '' && isset($this->verified[$token])) {
            return $this->verified[$token];
        }
        // Ein leeres Token wird trotzdem gesendet: ist der Dienst erreichbar, ist die Einsendung abgelehnt; ist er es
        // nicht, gilt "Bei Ausfall" (sonst sperrt ein nicht ladendes Widget alle Besucher aus).
        $result = $this->ask($settings, $token, $fields, $this->remoteIp($request));
        if ($token !== '') {
            if (count($this->verified) >= 50) {
                $this->verified = [];
            }
            $this->verified[$token] = $result;
        }

        return $result;
    }

    public static function tokenFrom(?ServerRequestInterface $request): string
    {
        $body = $request?->getParsedBody();
        $token = is_array($body) ? ($body[self::TOKEN_FIELD] ?? '') : '';

        return is_string($token) ? substr(trim($token), 0, 4000) : '';
    }

    /**
     * Felder einer Einsendung fuer die Inhaltspruefung: einfache Textwerte, ohne Kennwoerter und technische Felder.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    public static function contentFields(array $values): array
    {
        $out = [];
        foreach ($values as $name => $value) {
            $name = (string)$name;
            if ($name === '' || $name[0] === '_' || preg_match('/pass|pwd|secret|token|hmac|trusted/i', $name) === 1) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, 'is_scalar'));
            }
            if (is_scalar($value) && (string)$value !== '') {
                $out[substr($name, 0, 80)] = substr((string)$value, 0, 2000);
            }
        }

        return array_slice($out, 0, 40, true);
    }

    /**
     * @param array<string, string> $settings
     * @param array<string, string> $fields
     *
     * @return array{status: string, reasons: array<int, string>}
     */
    private function ask(array $settings, string $token, array $fields, string $ip): array
    {
        $body = [
            'secret' => $settings['secret'],
            'response' => $token,
            'remoteip' => $ip,
        ];
        if ($fields !== []) {
            $body['formular'] = $fields;
        }
        try {
            $response = GeneralUtility::makeInstance(RequestFactory::class)->request(
                $settings['serviceUrl'] . '/api/v1/pruefen',
                'POST',
                [
                    'json' => $body,
                    'timeout' => 8,
                    'connect_timeout' => 5,
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'headers' => ['User-Agent' => 'hostily-captcha-typo3/' . self::VERSION],
                ]
            );
            $code = $response->getStatusCode();
            $data = json_decode((string)$response->getBody(), true);
        } catch (\Throwable $e) {
            return $this->failure('service unreachable: ' . $e->getMessage(), $settings);
        }

        if ($code !== 200 || !is_array($data) || !isset($data['success'])) {
            return $this->failure('unexpected answer from service (HTTP ' . $code . ')', $settings);
        }
        $codes = [];
        if (isset($data['error-codes']) && is_array($data['error-codes'])) {
            foreach ($data['error-codes'] as $c) {
                if (is_string($c)) {
                    $codes[] = (string)preg_replace('/[^a-z0-9_-]/i', '', $c);
                }
            }
        }
        if (array_intersect($codes, self::CONFIG_ERRORS) !== []) {
            return $this->failure('service rejected the configuration: ' . implode(',', $codes), $settings);
        }
        if ($data['success'] === true) {
            return ['status' => 'ok', 'reasons' => []];
        }
        $reasons = [];
        if (isset($data['gruende']) && is_array($data['gruende'])) {
            foreach ($data['gruende'] as $r) {
                if (is_string($r)) {
                    $reasons[] = substr((string)preg_replace('/[^a-z0-9_:.-]/i', '', $r), 0, 60);
                }
            }
        }

        $this->logger?->info('hostily Captcha: submission rejected (' . implode(',', $reasons) . ')');

        return ['status' => 'rejected', 'reasons' => $reasons];
    }

    /**
     * Dienst- oder Konfigurationsproblem: durchlassen (Vorgabe) oder blockieren, immer protokollieren.
     * Das Secret steht nie im Log.
     *
     * @param array<string, string> $settings
     *
     * @return array{status: string, reasons: array<int, string>}
     */
    private function failure(string $why, array $settings): array
    {
        $why = str_replace($settings['secret'], '***', $why);
        $this->logger?->error('hostily Captcha: ' . substr($why, 0, 300));
        if ($settings['onError'] === 'block') {
            return ['status' => 'error', 'reasons' => ['service']];
        }

        return ['status' => 'ok', 'reasons' => ['service']];
    }

    private function remoteIp(?ServerRequestInterface $request): string
    {
        // Nach den Proxy-Einstellungen von TYPO3 aufgeloest (reverseProxyIP/-HeaderMultiValue)
        $ip = GeneralUtility::getIndpEnv('REMOTE_ADDR');

        return is_string($ip) && $ip !== '' ? $ip : (string)($request?->getServerParams()['REMOTE_ADDR'] ?? '');
    }
}
