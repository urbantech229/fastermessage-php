<?php

declare(strict_types=1);

namespace FasterMessage;

use FasterMessage\Http\CurlTransport;
use FasterMessage\Http\Requester;
use FasterMessage\Http\Transport;
use FasterMessage\Resource\Account;
use FasterMessage\Resource\ContactGroups;
use FasterMessage\Resource\Contacts;
use FasterMessage\Resource\Email;
use FasterMessage\Resource\Messages;
use FasterMessage\Resource\Otp;
use FasterMessage\Resource\Sms;

/** Point d'entrée du SDK. */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.fastermessage.com';

    public readonly Sms $sms;
    public readonly Email $email;
    public readonly Messages $messages;
    public readonly Otp $otp;
    public readonly Contacts $contacts;
    public readonly ContactGroups $contactGroups;
    public readonly Account $account;

    private readonly bool $testMode;

    /**
     * @param string $username identifiant d'API, compte débité
     * @param string $password mot de passe d'API
     * @param string $apiKey   clé `fm_live_…` (réel) ou `fm_test_…` (simulé)
     * @param array{base_url?: string, timeout?: float, max_retries?: int, transport?: Transport, sleep?: callable(float): void} $options
     *        `base_url` : le domaine d'API de votre marque, le cas échéant
     */
    public function __construct(string $username, string $password, string $apiKey, array $options = [])
    {
        if ($username === '' || $password === '' || $apiKey === '') {
            throw new \InvalidArgumentException('L\'identifiant, le mot de passe et la clé d\'API sont tous obligatoires.');
        }

        $this->testMode = str_contains($apiKey, '_test_');

        $http = new Requester(
            'Basic ' . base64_encode($username . ':' . $password),
            $apiKey,
            rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/'),
            $options['timeout'] ?? 30.0,
            max(0, $options['max_retries'] ?? 2),
            $options['transport'] ?? new CurlTransport(),
            $options['sleep'] ?? null,
        );

        $this->sms = new Sms($http);
        $this->email = new Email($http);
        $this->messages = new Messages($http);
        $this->otp = new Otp($http);
        $this->contacts = new Contacts($http);
        $this->contactGroups = new ContactGroups($http);
        $this->account = new Account($http);
    }

    /** Vrai avec une clé de test : messages et OTP simulés, mais contacts et soldes réels. */
    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    /** Évite d'écrire les identifiants dans un journal par `var_dump` ou `print_r`. */
    public function __debugInfo(): array
    {
        return ['testMode' => $this->testMode];
    }
}
