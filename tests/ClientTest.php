<?php

declare(strict_types=1);

namespace FasterMessage\Tests;

use FasterMessage\Client;
use FasterMessage\Exception\ApiException;
use FasterMessage\Exception\AuthenticationException;
use FasterMessage\Exception\ConflictException;
use FasterMessage\Exception\ConnectionException;
use FasterMessage\Exception\InsufficientBalanceException;
use FasterMessage\Exception\InvalidRequestException;
use FasterMessage\Exception\NotFoundException;
use FasterMessage\Exception\NotImplementedException;
use FasterMessage\Exception\PermissionException;
use FasterMessage\Exception\RateLimitException;
use FasterMessage\Exception\ServerException;
use FasterMessage\Http\Response;
use FasterMessage\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    /** @var list<float> */
    private array $slept = [];

    /** @param array<string, mixed> $options */
    private function client(FakeTransport $transport, array $options = []): Client
    {
        return new Client('compte', 'secret', 'fm_test_abc', $options + [
            'transport' => $transport,
            'sleep' => function (float $seconds): void {
                $this->slept[] = $seconds;
            },
        ]);
    }

    public function testEnvoieLesDeuxIdentifiantsEtLeCorps(): void
    {
        $transport = new FakeTransport([FakeTransport::json(201, ['id' => 'm1', 'status' => 'queued'])]);

        $message = $this->client($transport)->sms->send(['to' => '+2290160000000', 'from' => 'FASTERMSG', 'content' => 'Prêt']);

        $this->assertSame('m1', $message['id']);
        $call = $transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://api.fastermessage.com/v2/sms/send', $call['url']);
        $this->assertSame('Basic ' . base64_encode('compte:secret'), $call['headers']['Authorization']);
        $this->assertSame('fm_test_abc', $call['headers']['X-Api-Key']);
        $this->assertSame('application/json', $call['headers']['Content-Type']);
        $this->assertStringContainsString('Prêt', (string) $call['body']);
    }

    public function testPoseUneCleDIdempotenceParDefautEtRespecteLaVotre(): void
    {
        $transport = new FakeTransport([FakeTransport::json(201, []), FakeTransport::json(201, [])]);
        $client = $this->client($transport);
        $mail = ['to' => 'a@b.test', 'content' => ['subject' => 's', 'text' => 't']];

        $client->email->send($mail);
        $client->email->send($mail, ['idempotency_key' => 'cmd-42']);

        $this->assertStringStartsWith('sdk-', $transport->calls[0]['headers']['Idempotency-Key']);
        $this->assertSame('cmd-42', $transport->calls[1]['headers']['Idempotency-Key']);
    }

    public function testUneLectureNePortePasDeCleDIdempotence(): void
    {
        $transport = new FakeTransport([FakeTransport::json(200, ['balances' => [['channel' => 'sms', 'amount' => 5, 'currency' => 'XOF']]])]);

        $this->assertSame(5, $this->client($transport)->account->balance()[0]['amount']);
        $this->assertArrayNotHasKey('Idempotency-Key', $transport->calls[0]['headers']);
    }

    public function testReprendSurPanneReseauAvecLaMemeCle(): void
    {
        $transport = new FakeTransport([new ConnectionException('coupure'), FakeTransport::json(201, ['id' => 'm1'])]);

        $message = $this->client($transport)->sms->send(['to' => '1', 'from' => 'F', 'content' => 'x']);

        $this->assertSame('m1', $message['id']);
        $this->assertCount(2, $transport->calls);
        $this->assertSame($transport->calls[0]['headers']['Idempotency-Key'], $transport->calls[1]['headers']['Idempotency-Key']);
        $this->assertCount(1, $this->slept);
    }

    public function testApresLesReprisesLErreurReseauDonneLaClePourRejouer(): void
    {
        $transport = new FakeTransport([new ConnectionException('a'), new ConnectionException('b')]);

        try {
            $this->client($transport, ['max_retries' => 1])->sms->send(['to' => '1', 'from' => 'F', 'content' => 'x'], ['idempotency_key' => 'k1']);
            $this->fail('Une erreur était attendue.');
        } catch (ConnectionException $error) {
            $this->assertSame('k1', $error->idempotencyKey);
        }
    }

    public function testReprendSur503(): void
    {
        $transport = new FakeTransport([FakeTransport::error(503, 'service_unavailable', 'api_error'), FakeTransport::json(201, ['id' => 'm1'])]);

        $this->assertSame('m1', $this->client($transport)->sms->send(['to' => '1', 'from' => 'F', 'content' => 'x'])['id']);
    }

    public function testRespecteRetryAfterSur429(): void
    {
        $transport = new FakeTransport([FakeTransport::error(429, 'rate_limit_exceeded', 'rate_limit_error', null, ['retry-after' => '7']), FakeTransport::json(200, ['id' => 'm1'])]);

        $this->client($transport)->messages->get('m1');

        $this->assertSame([7.0], $this->slept);
    }

    public function testNeReprendPasUn429SansRetryAfter(): void
    {
        $transport = new FakeTransport([FakeTransport::error(429, 'rate_limit_exceeded', 'rate_limit_error')]);

        $this->expectException(RateLimitException::class);
        $this->client($transport)->otp->request(['to' => '1']);
    }

    public function testAttendUneRequeteEncoreEnCours(): void
    {
        $transport = new FakeTransport([FakeTransport::error(409, 'idempotency_key_in_flight'), FakeTransport::json(201, ['id' => 'm1'])]);

        $this->assertSame('m1', $this->client($transport)->sms->send(['to' => '1', 'from' => 'F', 'content' => 'x'])['id']);
    }

    /** @return iterable<string, array{int, string, class-string}> */
    public static function refus(): iterable
    {
        yield '400' => [400, 'missing_parameter', InvalidRequestException::class];
        yield '401' => [401, 'unauthenticated', AuthenticationException::class];
        yield '402' => [402, 'insufficient_balance', InsufficientBalanceException::class];
        yield '403' => [403, 'sender_not_approved', PermissionException::class];
        yield '404' => [404, 'message_not_found', NotFoundException::class];
        yield '409' => [409, 'idempotency_key_reuse', ConflictException::class];
        yield '501' => [501, 'not_implemented', NotImplementedException::class];
    }

    /** @param class-string<\Throwable> $class */
    #[DataProvider('refus')]
    public function testTraduitLesRefusSansLesRejouer(int $status, string $code, string $class): void
    {
        $transport = new FakeTransport([FakeTransport::error($status, $code, 'invalid_request', 'to')]);

        try {
            $this->client($transport)->sms->send(['to' => '1', 'from' => 'F', 'content' => 'x']);
            $this->fail('Une erreur était attendue.');
        } catch (ApiException $error) {
            $this->assertInstanceOf($class, $error);
            $this->assertSame($code, $error->errorCode);
            $this->assertSame('to', $error->param);
            $this->assertSame('rid-9', $error->requestId);
        }
        $this->assertCount(1, $transport->calls);
    }

    public function testUneReponseSansCorpsDErreurResteLisible(): void
    {
        $transport = new FakeTransport([new Response(500, [], '<html>'), new Response(500, [], '<html>'), new Response(500, [], '<html>')]);

        try {
            $this->client($transport)->account->balance();
            $this->fail('Une erreur était attendue.');
        } catch (ServerException $error) {
            $this->assertSame('http_500', $error->errorCode);
        }
    }

    public function testSuitLeCurseurJusquAuBout(): void
    {
        $transport = new FakeTransport([
            FakeTransport::json(200, ['data' => [['id' => 'a'], ['id' => 'b']], 'has_more' => true, 'next_cursor' => 'c1']),
            FakeTransport::json(200, ['data' => [['id' => 'c']], 'has_more' => false, 'next_cursor' => null]),
        ]);

        $ids = array_column(iterator_to_array($this->client($transport)->messages->all(['channel' => 'email']), false), 'id');

        $this->assertSame(['a', 'b', 'c'], $ids);
        $this->assertStringContainsString('channel=email', $transport->calls[0]['url']);
        $this->assertStringContainsString('cursor=c1', $transport->calls[1]['url']);
    }

    public function testUnDeleteEncodeLIdentifiant(): void
    {
        $transport = new FakeTransport([new Response(204, [], '')]);

        $this->client($transport)->contacts->delete('a/b');

        $this->assertSame('DELETE', $transport->calls[0]['method']);
        $this->assertStringEndsWith('/v2/contacts/a%2Fb', $transport->calls[0]['url']);
    }

    public function testCreeUnLotDeContacts(): void
    {
        $transport = new FakeTransport([FakeTransport::json(200, ['total' => 1])]);

        $this->client($transport)->contacts->createBatch(['group_id' => 'g1', 'contacts' => [['phone_number' => '1']]]);

        $this->assertStringEndsWith('/v2/contacts/batch', $transport->calls[0]['url']);
    }

    public function testVerifieUnCodeOtpSansLeverSurUnCodeFaux(): void
    {
        $transport = new FakeTransport([FakeTransport::json(200, ['id' => 'o1', 'status' => 'rejected', 'attempts_remaining' => 2])]);

        $this->assertSame('rejected', $this->client($transport)->otp->verify('o1', '123456')['status']);
    }

    public function testOtpRetientLeSmsParDefaut(): void
    {
        $transport = new FakeTransport([FakeTransport::json(201, ['id' => 'o1'])]);

        $this->client($transport)->otp->request(['to' => '1']);

        $this->assertStringContainsString('"channel":"sms"', (string) $transport->calls[0]['body']);
    }

    public function testEnvoieUnWhatsappSurSaRoute(): void
    {
        $transport = new FakeTransport([FakeTransport::json(201, ['id' => 'w1', 'channel' => 'whatsapp'])]);
        $params = ['to' => '1', 'from' => '+2', 'content' => ['template' => 't', 'variables' => ['1' => 'a']]];

        $message = $this->client($transport)->whatsapp->send($params);

        $this->assertStringEndsWith('/v2/whatsapp/send', $transport->calls[0]['url']);
        $this->assertSame($params, json_decode((string) $transport->calls[0]['body'], true));
        $this->assertSame('whatsapp', $message['channel']);
    }

    public function testLitLesRessourcesUtilisablesSurLeursRoutes(): void
    {
        $page = ['data' => [], 'has_more' => false, 'next_cursor' => null];
        $transport = new FakeTransport(array_fill(0, 4, FakeTransport::json(200, $page)));
        $fm = $this->client($transport);

        $fm->whatsapp->listNumbers(['limit' => 10]);
        $fm->whatsapp->listTemplates();
        $fm->email->listSenders();
        $fm->email->listTemplates(['cursor' => 'c1']);

        $this->assertSame(['GET', 'GET', 'GET', 'GET'], array_column($transport->calls, 'method'));
        $this->assertStringEndsWith('/v2/whatsapp/numbers?limit=10', $transport->calls[0]['url']);
        $this->assertStringEndsWith('/v2/whatsapp/templates', $transport->calls[1]['url']);
        $this->assertStringEndsWith('/v2/email/senders', $transport->calls[2]['url']);
        $this->assertStringEndsWith('/v2/email/templates?cursor=c1', $transport->calls[3]['url']);
        // Une lecture ne porte jamais de clé d'idempotence.
        $this->assertArrayNotHasKey('Idempotency-Key', $transport->calls[0]['headers']);
    }

    public function testParcourtTousLesModelesWhatsapp(): void
    {
        $transport = new FakeTransport([
            FakeTransport::json(200, ['data' => [['name' => 'a']], 'has_more' => true, 'next_cursor' => 'c1']),
            FakeTransport::json(200, ['data' => [['name' => 'b']], 'has_more' => false, 'next_cursor' => null]),
        ]);

        $names = array_column(iterator_to_array($this->client($transport)->whatsapp->allTemplates(), false), 'name');

        $this->assertSame(['a', 'b'], $names);
        $this->assertStringContainsString('cursor=c1', $transport->calls[1]['url']);
    }

    public function testOtpTransmetLeCanalWhatsappEtLaLangue(): void
    {
        $transport = new FakeTransport([FakeTransport::json(201, ['id' => 'o1'])]);

        $this->client($transport)->otp->request(['to' => '1', 'channel' => 'whatsapp', 'from' => '+2', 'language' => 'fr']);

        $sent = json_decode((string) $transport->calls[0]['body'], true);
        $this->assertSame('whatsapp', $sent['channel']);
        $this->assertSame('fr', $sent['language']);
    }

    public function testOtpTransmetLeRepliEtRendSonEtat(): void
    {
        $repli = ['will_run' => true, 'state' => 'watching', 'steps' => [['channel' => 'email', 'will_run' => true, 'reason' => null, 'message_id' => null]]];
        $transport = new FakeTransport([FakeTransport::json(201, ['id' => 'o1', 'fallback' => $repli])]);
        $fallback = [['channel' => 'email', 'to' => 'a@b.test', 'from' => 'no-reply@b.test', 'content' => ['text' => 'Code : {code}']]];

        $otp = $this->client($transport)->otp->request([
            'to' => '1',
            'fallback' => $fallback,
            'test_outcome' => 'failed',
            'callback_url' => 'https://exemple.test/repli',
        ]);

        $sent = json_decode((string) $transport->calls[0]['body'], true);
        $this->assertSame($fallback, $sent['fallback']);
        $this->assertSame('failed', $sent['test_outcome']);
        $this->assertSame('https://exemple.test/repli', $sent['callback_url']);
        $this->assertSame('watching', $otp['fallback']['state']);
    }

    public function testRespecteLeDomaineDeLaMarque(): void
    {
        $transport = new FakeTransport([FakeTransport::json(200, [])]);

        $this->client($transport, ['base_url' => 'https://api.marque.test/'])->messages->get('m1');

        $this->assertStringStartsWith('https://api.marque.test/v2/messages/m1', $transport->calls[0]['url']);
    }

    public function testExigeLesTroisIdentifiants(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('compte', '', 'fm_test_abc');
    }

    public function testNExposePasLesIdentifiantsDansUnDump(): void
    {
        $client = $this->client(new FakeTransport([]));

        $this->assertStringNotContainsString('secret', print_r($client, true));
        $this->assertTrue($client->isTestMode());
    }

    /** @return iterable<string, array{string}> */
    public static function adressesRefusees(): iterable
    {
        foreach (['http://api.marque.test', 'file:///C:/Windows/win.ini', 'ftp://api.marque.test', 'https://compte:secret@api.marque.test', 'https://api.marque.test/?a=1', 'api.marque.test', 'https://'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('adressesRefusees')]
    public function testRefuseUneAdresseNonSure(string $url): void
    {
        try {
            new Client('a', 'b', 'c', ['base_url' => $url]);
            $this->fail('Adresse acceptée.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringNotContainsString('secret', $error->getMessage());
        }
    }

    public function testAccepteHttpsEtLaMachineLocale(): void
    {
        foreach (['https://api.marque.test', 'https://api.marque.test/x/', 'http://localhost:3001', 'http://127.0.0.1:9', 'http://[::1]:9'] as $url) {
            $this->assertInstanceOf(Client::class, new Client('a', 'b', 'c', ['base_url' => $url]));
        }
    }

    public function testRefuseUneCleInvalideSansLaCiter(): void
    {
        foreach (["fm_test_SECRET\nX: y", 'fm_test_SECRET ', "fm_test_SECRET\n"] as $key) {
            try {
                new Client('a', 'b', $key);
                $this->fail('Clé acceptée.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringNotContainsString('SECRET', $error->getMessage());
            }
        }
    }

    public function testControleLesEnTetesFournisSansRienEnvoyer(): void
    {
        $transport = new FakeTransport([]);
        foreach ([['idempotency_key' => "cle\r\nX: y"], ['request_id' => "r\n"]] as $options) {
            try {
                $this->client($transport)->sms->send(['to' => '1', 'from' => 'F', 'content' => 'c'], $options);
                $this->fail('En-tête accepté.');
            } catch (\InvalidArgumentException) {
                $this->assertSame([], $transport->calls);
            }
        }
    }

    public function testRefuseUnIdentifiantVideOuEnPoints(): void
    {
        $transport = new FakeTransport([]);
        foreach (['', '.', '..'] as $id) {
            foreach ([
                fn () => $this->client($transport)->contacts->get($id),
                fn () => $this->client($transport)->contacts->delete($id),
                fn () => $this->client($transport)->contactGroups->update($id, ['name' => 'n']),
                fn () => $this->client($transport)->messages->get($id),
            ] as $call) {
                try {
                    $call();
                    $this->fail('Identifiant accepté.');
                } catch (\InvalidArgumentException) {
                    $this->assertSame([], $transport->calls);
                }
            }
        }
    }

    public function testAccepteUnIdentifiantAvecDesPoints(): void
    {
        $transport = new FakeTransport([FakeTransport::json(200, ['id' => 'x'])]);
        $this->client($transport)->contacts->get('a..b');
        $this->assertSame('https://api.fastermessage.com/v2/contacts/a..b', $transport->calls[0]['url']);
    }

    public function testUnRetryAfterTropLongRendLaMain(): void
    {
        $transport = new FakeTransport([new Response(429, ['retry-after' => '86400'], '{"error":{"code":"rate_limited","type":"rate_limit","message":"x"}}')]);
        try {
            $this->client($transport)->account->balance();
            $this->fail('Aucune erreur.');
        } catch (RateLimitException $error) {
            $this->assertSame(86400, $error->retryAfter);
        }
        $this->assertSame([], $this->slept);
        $this->assertCount(1, $transport->calls);
    }

    public function testUnRetryAfterDe60SecondesEstAttendu(): void
    {
        $transport = new FakeTransport([new Response(429, ['retry-after' => '60'], '{"error":{"code":"rate_limited","type":"rate_limit","message":"x"}}'), FakeTransport::json(200, ['balances' => []])]);
        $this->client($transport)->account->balance();
        $this->assertSame([60.0], $this->slept);
    }

    public function testUnRetryAfterIllisibleEstIgnore(): void
    {
        foreach (['1e9', '-1', '1.5', 'demain'] as $value) {
            $transport = new FakeTransport([new Response(429, ['retry-after' => $value], '{"error":{"code":"rate_limited","type":"rate_limit","message":"x"}}')]);
            try {
                $this->client($transport)->account->balance();
                $this->fail('Aucune erreur.');
            } catch (RateLimitException $error) {
                $this->assertNull($error->retryAfter);
            }
        }
    }

    public function testUneRedirectionEstUneErreur(): void
    {
        $transport = new FakeTransport([new Response(302, ['location' => 'https://ailleurs.test/'], '')]);
        try {
            $this->client($transport)->account->balance();
            $this->fail('Aucune erreur.');
        } catch (ApiException $error) {
            $this->assertSame(302, $error->httpStatus);
        }
        $this->assertCount(1, $transport->calls);
    }

    public function testVerifieLaSignatureDUnRappel(): void
    {
        $body = '{"uuid":"m1","status":"Delivered"}';
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'cle');

        $this->assertTrue(Webhook::isGenuine($body, $signature, 'cle'));
        $this->assertFalse(Webhook::isGenuine($body . ' ', $signature, 'cle'));
        $this->assertFalse(Webhook::isGenuine($body, $signature, 'autre'));
        $this->assertFalse(Webhook::isGenuine($body, null, 'cle'));
    }

    public function testVerifieLeRappelDeRepliCommeLesAutres(): void
    {
        $body = (string) json_encode([
            'event' => 'fallback',
            'id' => 'm1',
            'kind' => 'message',
            'channel' => 'sms',
            'client_reference' => null,
            'fallback' => ['will_run' => true, 'state' => 'triggered', 'steps' => [['channel' => 'email', 'will_run' => true, 'reason' => null, 'message_id' => 'm2']]],
            'event_at' => '2026-11-07T10:00:00.000Z',
        ]);

        $this->assertTrue(Webhook::isGenuine($body, 'sha256=' . hash_hmac('sha256', $body, 'cle'), 'cle'));
        $event = json_decode($body, true);
        $this->assertSame('fallback', $event['event']);
        $this->assertSame('triggered', $event['fallback']['state']);
    }
}
