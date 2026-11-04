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

    public function testVerifieLaSignatureDUnRappel(): void
    {
        $body = '{"uuid":"m1","status":"Delivered"}';
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'cle');

        $this->assertTrue(Webhook::isGenuine($body, $signature, 'cle'));
        $this->assertFalse(Webhook::isGenuine($body . ' ', $signature, 'cle'));
        $this->assertFalse(Webhook::isGenuine($body, $signature, 'autre'));
        $this->assertFalse(Webhook::isGenuine($body, null, 'cle'));
    }
}
