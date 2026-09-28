<?php

namespace App\Mailer;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Rocket Mailer used whenever ROCKET_MAILER_URL / secret rocket.mailer.token are not configured: a shared inbox "Contact"
 * with a few fictitious conversations (same JSON shapes as Rocket Mailer's /api/inbox), and a send that is only
 * recorded in var/demo-mailer-<env>.json. Nothing ever leaves the server.
 */
final class DemoMailer
{
    public const INBOX = '0192f7c4-0000-7000-8000-00000000a001';

    public function __construct(private readonly string $demoMailerPath)
    {
    }

    public function reset(): void
    {
        if (is_file($this->demoMailerPath)) {
            unlink($this->demoMailerPath);
        }
    }

    /** @return list<array<string, mixed>> conversation summaries whose subject, participants or text contain $query */
    public function conversations(string $query): array
    {
        $q = mb_strtolower(trim($query));
        $out = [];
        foreach ($this->all() as $c) {
            $haystack = mb_strtolower($c['subject'].' '.implode(' ', $c['participants']).' '.implode(' ', array_column($c['items'], 'text')));
            if ('' === $q || str_contains($haystack, $q)) {
                $out[] = array_diff_key($c, ['items' => 1]);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function conversation(string $id): array
    {
        foreach ($this->all() as $c) {
            if ($c['id'] === $id) {
                return ['conversation' => array_diff_key($c, ['items' => 1]), 'mailbox' => ['id' => self::INBOX, 'name' => 'Contact (démo)', 'email' => 'contact@example.org'], 'items' => $c['items']];
            }
        }
        throw new HttpException(404, 'Conversation introuvable.');
    }

    /**
     * @param array<string, mixed> $email
     *
     * @return array<string, mixed>
     */
    public function send(array $email): array
    {
        $sent = $this->sent();
        $record = ['id' => Uuid::v7()->toRfc4122(), 'status' => 'demo', 'to' => $email['to'] ?? [], 'subject' => (string) ($email['subject'] ?? ''), 'createdAt' => (new \DateTimeImmutable())->format(\DATE_ATOM)];
        $sent[] = $record;
        if (!is_dir(\dirname($this->demoMailerPath))) {
            mkdir(\dirname($this->demoMailerPath), 0o775, true);
        }
        file_put_contents($this->demoMailerPath, json_encode($sent, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT), \LOCK_EX);

        return $record;
    }

    /** @return list<array<string, mixed>> e-mails "sent" in demo mode */
    public function sent(): array
    {
        $raw = is_file($this->demoMailerPath) ? file_get_contents($this->demoMailerPath) : false;
        $data = false === $raw ? null : json_decode($raw, true);

        return \is_array($data) ? array_values($data) : [];
    }

    /** @return list<array<string, mixed>> */
    private function all(): array
    {
        $at = static fn (string $rel) => (new \DateTimeImmutable($rel))->format(\DATE_ATOM);
        $conv = static function (string $id, string $subject, string $guest, string $name, array $items) use ($at): array {
            $last = end($items);

            return [
                'id' => $id, 'subject' => $subject, 'status' => 'open', 'assignee' => null, 'participants' => [$guest],
                'lastFrom' => $last['from'] ?? $guest, 'snippet' => mb_substr((string) ($last['text'] ?? ''), 0, 120), 'messageCount' => \count($items),
                'lastMessageAt' => $last['at'] ?? $at('now'), 'lastActivityAt' => $last['at'] ?? $at('now'), 'unread' => false,
                'items' => array_map(static fn (array $i) => $i + ['fromName' => 'inbound' === $i['type'] ? $name : null], $items),
            ];
        };

        return [
            $conv('0192f7c4-0000-7000-8000-00000000c001', 'Réservation 2 : heure d’arrivée', 'marc.demo@guest.booking.com', 'Marc Durand', [
                ['type' => 'inbound', 'id' => 'm1', 'at' => $at('-2 days'), 'from' => 'marc.demo@guest.booking.com', 'subject' => 'Réservation 2 : heure d’arrivée', 'text' => "Bonjour,\nNous arriverons vers 18 h. Est-ce possible ?\nMarc"],
                ['type' => 'reply', 'id' => 'm2', 'at' => $at('-2 days +3 hours'), 'from' => 'contact@example.org', 'subject' => 'Re: Réservation 2 : heure d’arrivée', 'text' => "Bonjour Marc,\nAucun souci, le code sera actif dès 15 h.", 'status' => 'sent'],
            ]),
            $conv('0192f7c4-0000-7000-8000-00000000c002', 'Facture de votre séjour', 'paul.demo@example.org', 'Paul Morel', [
                ['type' => 'inbound', 'id' => 'm3', 'at' => $at('-1 day'), 'from' => 'paul.demo@example.org', 'subject' => 'Facture de votre séjour', 'text' => "Bonjour, pourriez-vous m'envoyer une facture au nom de ma société ? Merci, Paul"],
            ]),
            $conv('0192f7c4-0000-7000-8000-00000000c004', 'Réservation 1 : départ', 'alex.demo@guest.airbnb.com', 'Alex Martin', [
                ['type' => 'inbound', 'id' => 'm5', 'at' => $at('-1 day'), 'from' => 'alex.demo@guest.airbnb.com', 'subject' => 'Réservation 1 : départ', 'text' => "Bonjour,\nPouvons-nous laisser les bagages jusqu'à 13 h le jour du départ ?\nAlex"],
                ['type' => 'reply', 'id' => 'm6', 'at' => $at('-1 day +2 hours'), 'from' => 'contact@example.org', 'subject' => 'Re: Réservation 1 : départ', 'text' => "Bonjour Alex,\nOui, laissez-les dans l'entrée, le ménage passe à 13 h.", 'status' => 'sent'],
            ]),
            $conv('0192f7c4-0000-7000-8000-00000000c005', 'Réservation 3 : parking', 'sofia.demo@guest.airbnb.com', 'Sofia Rossi', [
                ['type' => 'inbound', 'id' => 'm7', 'at' => $at('-3 days'), 'from' => 'sofia.demo@guest.airbnb.com', 'subject' => 'Réservation 3 : parking', 'text' => 'Bonjour, y a-t-il une place de parking près du logement ? Sofia'],
            ]),
            $conv('0192f7c4-0000-7000-8000-00000000c006', 'Réservation 5 : lit bébé', 'anna.demo@guest.booking.com', 'Anna Kowalska', [
                ['type' => 'inbound', 'id' => 'm8', 'at' => $at('-6 hours'), 'from' => 'anna.demo@guest.booking.com', 'subject' => 'Réservation 5 : lit bébé', 'text' => 'Bonjour, serait-il possible d’avoir un lit bébé ? Merci, Anna'],
            ]),
            $conv('0192f7c4-0000-7000-8000-00000000c003', 'Proposition de partenariat', 'agence.demo@example.net', 'Agence Démo', [
                ['type' => 'inbound', 'id' => 'm4', 'at' => $at('-5 days'), 'from' => 'agence.demo@example.net', 'subject' => 'Proposition de partenariat', 'text' => 'Bonjour, nous gérons des locations dans votre quartier…'],
            ]),
        ];
    }
}
