<?php

namespace App\Mailer;

use Rocket\Core\Oidc\OidcException;
use Rocket\Core\Suite\ServiceTokenProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of Rocket Mailer (rocket-middleware/rocket-mailer), the mail system of the Rocket suite: PMS never speaks
 * IMAP/SMTP itself. Every call acts on behalf of the signed-in user (X-Impersonate-User), with the application token
 * ROCKET_MAILER_TOKEN (rma_…) or, in suite mode, a token of Rocket Auth for the audience "rocket-mailer".
 * Without ROCKET_MAILER_URL/TOKEN: App\Mailer\DemoMailer answers (no network, keeps tests offline).
 *
 * Used endpoints: POST /api/emails (send), GET /api/inbox/mailboxes/{id}/conversations?q= and
 * GET /api/inbox/conversations/{id} (shared inbox ROCKET_MAILER_INBOX). Rocket Mailer 0.7 refuses its shared inboxes
 * to applications (even impersonating): the inbox calls then answer 403, mapped to InboxUnavailable (see README).
 */
final class MailerClient
{
    private const TIMEOUT = 8;
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const AUDIENCE = 'rocket-mailer';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly DemoMailer $demo,
        private readonly string $mailerUrl,
        private readonly string $mailerToken,
        private readonly string $mailerInbox,
        private readonly string $mailerMailbox,
        private readonly ?ServiceTokenProvider $serviceTokens = null,
    ) {
    }

    public function isDemo(): bool
    {
        return '' === trim($this->mailerUrl) || ('' === trim($this->mailerToken) && !$this->usesSuiteTokens());
    }

    public function usesSuiteTokens(): bool
    {
        return null !== $this->serviceTokens && $this->serviceTokens->isAvailable();
    }

    /** Shared inbox configured for guest e-mails (ROCKET_MAILER_INBOX), null when none (demo: the demo inbox). */
    public function inboxId(): ?string
    {
        if ($this->isDemo()) {
            return DemoMailer::INBOX;
        }

        return '' === trim($this->mailerInbox) ? null : trim($this->mailerInbox);
    }

    /**
     * Conversations of the shared inbox matching a search (subject, sender, text).
     *
     * @return list<array<string, mixed>>
     *
     * @throws InboxUnavailable
     */
    public function conversations(string $asUser, string $query): array
    {
        $inbox = $this->inboxId() ?? throw new InboxUnavailable('Aucune boîte partagée configurée (ROCKET_MAILER_INBOX).');
        if ($this->isDemo()) {
            return $this->demo->conversations($query);
        }
        $data = $this->inboxCall('GET', '/api/inbox/mailboxes/'.rawurlencode($inbox).'/conversations', $asUser, ['query' => ['q' => mb_substr($query, 0, 100)]]);

        return array_values(array_filter(\is_array($data['items'] ?? null) ? $data['items'] : (array_is_list($data) ? $data : []), 'is_array'));
    }

    /**
     * One conversation (thread) of the shared inbox.
     *
     * @return array<string, mixed>
     *
     * @throws InboxUnavailable
     */
    public function conversation(string $asUser, string $id): array
    {
        if ($this->isDemo()) {
            return $this->demo->conversation($id);
        }

        return $this->inboxCall('GET', '/api/inbox/conversations/'.rawurlencode($id), $asUser, []);
    }

    /**
     * Sends an e-mail through Rocket Mailer (queued there). ROCKET_MAILER_MAILBOX: sending mailbox of the application, optional.
     *
     * @param array{to: list<string>, subject: string, htmlBody: string} $email
     *
     * @return array<string, mixed> the queued e-mail (id, status…)
     */
    public function send(string $asUser, array $email): array
    {
        if ($this->isDemo()) {
            return $this->demo->send($email);
        }
        if ('' !== trim($this->mailerMailbox)) {
            $email['mailbox'] = '/api/mailboxes/'.rawurlencode(trim($this->mailerMailbox));
        }

        return $this->decode($this->call('POST', '/api/emails', $asUser, ['json' => $email]));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function inboxCall(string $method, string $path, string $asUser, array $options): array
    {
        try {
            return $this->decode($this->call($method, $path, $asUser, $options));
        } catch (HttpException $e) {
            if (\in_array($e->getStatusCode(), [403, 404], true) && str_contains($e->getMessage(), '[inbox]')) {
                throw new InboxUnavailable('Rocket Mailer refuse la lecture de la boîte partagée à l’application Rocket PMS.');
            }
            throw $e;
        }
    }

    private function bearer(): string
    {
        if ($this->usesSuiteTokens()) {
            try {
                return $this->serviceTokens->tokenForClient(self::AUDIENCE);
            } catch (OidcException $e) {
                if ('' === trim($this->mailerToken)) {
                    throw new HttpException(502, 'Rocket Auth ne délivre pas de jeton pour Rocket Mailer : '.$e->getMessage());
                }
            }
        }

        return $this->mailerToken;
    }

    /** @param array<string, mixed> $options */
    private function call(string $method, string $path, string $asUser, array $options): string
    {
        $options['headers'] = ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->bearer(), 'X-Impersonate-User' => $asUser];
        $options['timeout'] = self::TIMEOUT;
        $inbox = str_starts_with($path, '/api/inbox/');
        try {
            $response = $this->http->request($method, rtrim($this->mailerUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (\strlen($body) > self::MAX_BYTES) {
                    $response->cancel();
                    throw new HttpException(502, 'Réponse de Rocket Mailer trop volumineuse.');
                }
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new HttpException(502, 'Rocket Mailer ne répond pas ou est injoignable depuis le serveur.');
        }
        if ($inbox && (403 === $status || 404 === $status)) {
            throw new HttpException($status, '[inbox]');
        }
        if (401 === $status) {
            if ($this->usesSuiteTokens()) {
                $this->serviceTokens->forget(self::AUDIENCE);
            }
            throw new HttpException(502, 'Jeton Rocket Mailer refusé (ROCKET_MAILER_TOKEN ou client Rocket Auth).');
        }
        if (403 === $status) {
            throw new HttpException(502, 'Rocket Mailer refuse cette action à l’application Rocket PMS (impersonation autorisée ? utilisateur connu de Rocket Mailer ?).');
        }
        if ($status >= 400 && $status < 500) {
            throw new HttpException($status, self::message($body) ?? \sprintf('Rocket Mailer a répondu avec l’erreur %d.', $status));
        }
        if ($status >= 500) {
            throw new HttpException(502, \sprintf('Rocket Mailer a répondu avec l’erreur %d.', $status));
        }

        return $body;
    }

    /** @return array<mixed> */
    private function decode(string $body): array
    {
        if ('' === $body) {
            return [];
        }
        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(502, 'Réponse illisible de Rocket Mailer.');
        }

        return \is_array($data) ? $data : [];
    }

    private static function message(string $body): ?string
    {
        $data = json_decode($body, true);
        if (!\is_array($data)) {
            return null;
        }
        $m = $data['detail'] ?? $data['hydra:description'] ?? $data['message'] ?? null;

        return \is_string($m) && '' !== $m ? mb_substr($m, 0, 300) : null;
    }
}
